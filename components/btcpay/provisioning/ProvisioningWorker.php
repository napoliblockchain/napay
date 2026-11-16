<?php
namespace app\components\btcpay\provisioning;

use Yii;
use app\components\btcpay\provisioning\steps\MerchantAccountStep;
use app\components\btcpay\provisioning\steps\StepOutcome;
use app\components\identity\AccountEligibility;
use yii\db\Connection;

/**
 * SIP-022 B1 W3b: esecutore `merchant_account` (A, B, C della tabella 3.1), un lavoro per chiamata; A-r resta successivo.
 * - Risorsa serializzata con GET_LOCK non bloccante per istanza+merchant, tenuto anche durante l'HTTP e sempre
 *   rilasciato; nessuna transazione DB aperta durante la rete.
 * - Prima di ogni POST: checkpoint W3a con mapping e ricontrollo del merchant (righe identità bloccate in lettura).
 * - Ogni esito: CAS W3a con mapping atomico e nuovo ricontrollo del merchant; un merchant non più idoneo non adotta
 *   esiti tardivi (account sospeso, lavoro fallito). Esito ignoto = riconciliazione, mai un nuovo POST automatico.
 * B5 riprova dell'admin (generazione > 1, nuovo INSERT dal web) su account reconcile_required/failed/suspended:
 *   utente remoto già noto = nuova chiave (passo B, entro MAX_GENERATIONS); utente non confermato = A-r: GET per email
 *   tecnica, assente = passo A con la stessa password (BTCPay rifiuta email doppie), presente = adottato solo se la
 *   password salvata crea la chiave (passo B, prova di proprietà). Mai reset o modifica di un utente esistente.
 * Nessun loop, daemon o console: runOnce() esegue al più un lavoro.
 */
final class ProvisioningWorker
{
    private const KIND = 'merchant_account';
    private const LOCK_WAIT = 5;

    private readonly \Closure $clock;

    public function __construct(#[\SensitiveParameter] private readonly Connection $db, #[\SensitiveParameter] private readonly ProvisioningJobs $jobs,
        #[\SensitiveParameter] private readonly ProvisioningStore $store, #[\SensitiveParameter] private readonly MerchantAccountStep $step, private readonly string $instance,
        #[\SensitiveParameter] private readonly string $provisionerKey, #[\SensitiveParameter] ?\Closure $clock = null, private readonly RetryPolicy $retry = new RetryPolicy())
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $instance) !== 1) throw new \InvalidArgumentException('invalid_instance');
        if ($provisionerKey === '') throw new \InvalidArgumentException('invalid_provisioner_key');
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** @return bool false se non c'era alcun lavoro dovuto, true se un lavoro è stato preso (qualunque esito). */
    public function runOnce(): bool
    {
        // I controlli di idoneità (ActiveRecord) devono leggere sulla stessa connessione delle transazioni della coda.
        if (Yii::$app->db !== $this->db) throw new \LogicException('worker_connection_mismatch');
        try {
            return $this->processOne();
        } catch (\Throwable $error) {
            // Native/Yii transaction frames can expose connection objects and captured credentials.
            throw new \RuntimeException('provisioning_worker_failed');
        }
    }

    private function processOne(): bool
    {
        $job = $this->jobs->claim($this->instance, [self::KIND]);
        if ($job === null) return false;
        $merchant = $job['merchant_id'];
        if ($job['kind'] !== self::KIND || $job['entity_id'] !== $merchant) {
            $this->jobs->fail($job['id'], $job['version'], 'resource_scope_mismatch');
            return true;
        }
        $lock = 'napay-btcpay-' . substr(hash('sha256', $this->instance . '|merchant|' . $merchant), 0, 40);
        if ((int) $this->db->createCommand('SELECT GET_LOCK(:l, 0)', [':l' => $lock])->queryScalar() !== 1) {
            // Un altro lavoro (altra generazione) sta già lavorando sulla stessa risorsa.
            $this->jobs->retryLater($job['id'], $job['version'], 'resource_busy', $this->now() + self::LOCK_WAIT);
            return true;
        }
        try {
            $this->account($job, $merchant);
        } finally {
            $this->db->createCommand('SELECT RELEASE_LOCK(:l)', [':l' => $lock])->queryScalar();
        }
        return true;
    }

    /** Fino a un passo remoto per volta (A, B, C) nello stesso lavoro; ogni passo riuscito avanza con mapping atomico. */
    private function account(array $job, int $merchant): void
    {
        $version = $this->outcome($job, $job['version'], fn(#[\SensitiveParameter] $m) => $this->jobs->prepare($job['id'], $job['version'], $m), $merchant,
            fn() => $this->store->ensureAccount($merchant));
        if ($version === null) return;
        if ($job['generation'] > 1) {
            $version = $this->reopenForRetry($job, $version, $merchant);
            if ($version === null) return;
        }
        for ($step = 0; $step < 4; ++$step) {
            $account = $this->store->account($merchant);
            $state = $account['state'];
            if ($state === 'synced') { $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->succeed($job['id'], $version, $m), $merchant, null); return; }
            if (!in_array($state, ['pending', 'user_requested', 'user_created', 'key_saved'], true)) {
                // suspended / failed / reconcile_required: nessuna chiamata, decide l'admin.
                $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->fail($job['id'], $version, 'account_' . $state, $m), $merchant, null);
                return;
            }
            $next = match ($state) {
                'pending', 'user_requested' => $this->createUser($job, $version, $account, $merchant),
                'user_created' => $this->createKey($job, $version, $account, $merchant),
                'key_saved' => $this->verifyKey($job, $version, $account, $merchant),
            };
            if ($next === null) return;
            $version = $next;
        }
    }

    /**
     * Riprova dell'admin: account chiuso (reconcile_required/failed/suspended) riaperto al passo giusto, con lo stato
     * cambiato sotto CAS della coda. Utente non confermato: A-r (solo GET con la chiave del provisioner). Altri stati: invariato.
     */
    private function reopenForRetry(array $job, int $version, int $merchant): ?int
    {
        $account = $this->store->account($merchant) ?? throw new \UnexpectedValueException('account_missing');
        if (!in_array($account['state'], ['reconcile_required', 'failed', 'suspended'], true)) return $version;
        if ($account['remote_user_id'] !== null) {
            return $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->prepare($job['id'], $version, $m), $merchant,
                fn() => $this->setAccount($merchant, ['state' => 'user_created', 'last_error_code' => null]));
        }
        $result = $this->step->findUser($account['technical_email'], $this->provisionerKey);
        return match ($result->kind) {
            StepOutcome::ABSENT => $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->prepare($job['id'], $version, $m), $merchant,
                fn() => $this->setAccount($merchant, ['state' => 'user_requested', 'last_error_code' => null])),
            // Presente: proprietà ancora da provare, la prova è il passo B con la password salvata (401 = riconciliazione).
            StepOutcome::FOUND => $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->prepare($job['id'], $version, $m), $merchant,
                fn() => $this->setAccount($merchant, ['state' => 'user_created', 'remote_user_id' => $result->data['remote_user_id'], 'last_error_code' => null])),
            StepOutcome::RETRY => $this->later($job, $version, $result, $merchant, false),
            StepOutcome::FAILED => $this->finalFailure($job, $version, $result->code, $merchant, 'failed'),
            default => $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->reconcile($job['id'], $version, (string) $result->code, $m), $merchant,
                fn() => $this->setAccount($merchant, ['state' => 'reconcile_required', 'last_error_code' => $result->code]), false),
        };
    }

    /** A: password casuale salvata cifrata PRIMA della chiamata (una volta sola), checkpoint, POST, esito. */
    private function createUser(array $job, int $version, array $account, int $merchant): ?int
    {
        // Checkpoint con merchant ricontrollato: se non è più idoneo, lavoro fallito senza alcuna chiamata remota.
        $checkpoint = $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->checkpoint($job['id'], $version, 'create_user', $m), $merchant, function () use ($merchant) {
            $fresh = $this->store->account($merchant) ?? throw new \UnexpectedValueException('account_missing');
            if ($this->store->password($fresh) === null && !$this->store->storePassword($fresh, bin2hex(random_bytes(32)))) throw new \UnexpectedValueException('password_not_saved');
            if ($fresh['state'] !== 'user_requested' && $this->store->updateAccount($fresh, ['state' => 'user_requested']) === null) throw new \UnexpectedValueException('account_changed');
        });
        if ($checkpoint === null) return null;
        $password = $this->store->password($this->store->account($merchant)) ?? throw new \UnexpectedValueException('password_missing');
        $result = $this->step->createUser($account['technical_email'], $password, $this->provisionerKey);
        unset($password);
        $id = $job['id'];
        return match ($result->kind) {
            StepOutcome::DONE => $this->outcome($job, $checkpoint, fn(#[\SensitiveParameter] $m) => $this->jobs->advance($id, $checkpoint, $m), $merchant, function () use ($merchant, $result) {
                $this->setAccount($merchant, ['state' => 'user_created', 'remote_user_id' => $result->data['remote_user_id']]);
            }),
            StepOutcome::RETRY => $this->later($job, $checkpoint, $result, $merchant, true),
            StepOutcome::FAILED => $this->finalFailure($job, $checkpoint, $result->code, $merchant, 'failed'),
            // 422/5xx/ignoto/malformato: l'utente potrebbe esistere. Solo riconciliazione (A-r), nessun nuovo POST.
            default => $this->outcome($job, $checkpoint, fn(#[\SensitiveParameter] $m) => $this->jobs->reconcile($id, $checkpoint, (string) $result->code, $m), $merchant,
                fn() => $this->setAccount($merchant, ['state' => 'reconcile_required', 'last_error_code' => $result->code]), false),
        };
    }

    /** B: generazione incrementata e salvata al checkpoint (limite 3), POST in Basic, chiave salvata `pending`. */
    private function createKey(array $job, int $version, array $account, int $merchant): ?int
    {
        $generation = $account['key_generations_used'] + 1;
        if ($generation > MerchantAccountStep::MAX_GENERATIONS) {
            $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->reconcile($job['id'], $version, 'generations_exhausted', $m), $merchant,
                fn() => $this->setAccount($merchant, ['state' => 'reconcile_required', 'last_error_code' => 'generations_exhausted']), false);
            return null;
        }
        $checkpoint = $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->checkpoint($job['id'], $version, 'create_api_key', $m), $merchant,
            fn() => $this->setAccount($merchant, ['key_generations_used' => $generation]));
        if ($checkpoint === null) return null;
        $password = $this->store->password($account) ?? throw new \UnexpectedValueException('password_missing');
        $result = $this->step->createKey($account['technical_email'], $password, $account['ref'], $generation);
        unset($password);
        $id = $job['id'];
        return match ($result->kind) {
            StepOutcome::DONE => $this->outcome($job, $checkpoint, fn(#[\SensitiveParameter] $m) => $this->jobs->advance($id, $checkpoint, $m), $merchant, function () use ($merchant, $result, $generation) {
                $fresh = $this->store->account($merchant);
                // La chiave nuova resta pending: diventa current solo dopo la verifica C.
                if (!$this->store->storeKey($fresh, $generation, (string) $result->secret())) throw new \UnexpectedValueException('key_not_saved');
                $this->setAccount($merchant, ['state' => 'key_saved']);
            }),
            // 429 (B1 §3.1: deferred): nessuna esecuzione remota, la generazione non si consuma.
            StepOutcome::DEFERRED => $this->later($job, $checkpoint, $result, $merchant, true, $generation - 1),
            // Esito ignoto: generazione consumata, nuovo tentativo dalla coda con la generazione successiva (max 3).
            StepOutcome::REPEAT => $this->unknownKey($job, $checkpoint, $result, $merchant),
            StepOutcome::FAILED => $this->finalFailure($job, $checkpoint, $result->code, $merchant, 'failed'),
            default => $this->outcome($job, $checkpoint, fn(#[\SensitiveParameter] $m) => $this->jobs->reconcile($id, $checkpoint, (string) $result->code, $m), $merchant,
                fn() => $this->setAccount($merchant, ['state' => 'reconcile_required', 'last_error_code' => $result->code]), false),
        };
    }

    /** C: GET con la chiave pending (nessun POST): verificata, promossa a current, account synced, lavoro done. */
    private function verifyKey(array $job, int $version, array $account, int $merchant): ?int
    {
        $generation = 0; $key = null;
        $prepared = $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->prepare($job['id'], $version, $m), $merchant,
            function () use ($merchant, &$generation, &$key) {
                $fresh = $this->store->account($merchant) ?? throw new \UnexpectedValueException('account_missing');
                $generation = $fresh['key_generations_used'];
                $key = $generation > 0 ? $this->store->key($fresh, $generation) : null;
            });
        if ($prepared === null) return null;
        $version = $prepared;
        if ($key === null || !in_array($key['state'], ['pending', 'verified'], true)) {
            $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->reconcile($job['id'], $version, 'key_missing', $m), $merchant,
                fn() => $this->setAccount($merchant, ['state' => 'reconcile_required', 'last_error_code' => 'key_missing']), false);
            return null;
        }
        if ($key['state'] === 'pending') {
            $result = $this->step->verifyKey($key['key']);
            if ($result->kind === StepOutcome::RETRY) { $this->later($job, $version, $result, $merchant, false); return null; }
            if ($result->kind !== StepOutcome::DONE) {
                $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->reconcile($job['id'], $version, (string) $result->code, $m), $merchant,
                    fn() => $this->setAccount($merchant, ['state' => 'reconcile_required', 'last_error_code' => $result->code]), false);
                return null;
            }
        }
        unset($key);
        $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->succeed($job['id'], $version, $m), $merchant, function () use ($merchant, $generation) {
            $fresh = $this->store->account($merchant);
            $current = $this->store->key($fresh, $generation) ?? throw new \UnexpectedValueException('key_missing');
            if ($current['state'] === 'pending' && !$this->store->verifyKey($fresh, $generation, $current['lock_version'])) throw new \UnexpectedValueException('key_changed');
            if (!$this->store->promoteKey($fresh, $generation)) throw new \UnexpectedValueException('key_not_promoted');
            $this->setAccount($merchant, ['state' => 'synced', 'last_error_code' => null]);
        });
        return null;
    }

    /** 429 o lettura ripetibile: RetryPolicy (Retry-After del server, deferred oltre 24 h o per contratto). */
    private function later(array $job, int $version, StepOutcome $result, int $merchant, bool $inFlight, ?int $restoreGenerations = null): ?int
    {
        $decision = $this->retry->decide($result->retryAfter, max(1, $job['attempts']), $this->now());
        $deferred = $decision['deferred'] || $result->kind === StepOutcome::DEFERRED;
        if ($inFlight) {
            $version = $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->remoteRejected($job['id'], $version, 429, $m), $merchant,
                $restoreGenerations === null ? null : fn() => $this->setAccount($merchant, ['key_generations_used' => $restoreGenerations]), false);
            if ($version === null) return null;
        }
        $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->retryLater($job['id'], $version,
            (string) $result->code, $decision['next_attempt_at'], $deferred, $m), $merchant, null);
        return null;
    }

    /** B con esito ignoto: in_flight -> running (generazione già consumata), poi nuovo tentativo dalla coda. */
    private function unknownKey(array $job, int $version, StepOutcome $result, int $merchant): ?int
    {
        $version = $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->advance($job['id'], $version, $m), $merchant, fn() => $this->setAccount($merchant, ['last_error_code' => $result->code]));
        if ($version === null) return null;
        $decision = $this->retry->decide(null, max(1, $job['attempts']), $this->now());
        $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->retryLater($job['id'], $version,
            (string) $result->code, $decision['next_attempt_at'], $decision['deferred'], $m), $merchant, null);
        return null;
    }

    private function finalFailure(array $job, int $version, ?string $code, int $merchant, string $state): ?int
    {
        $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->fail($job['id'], $version, (string) $code, $m), $merchant,
            fn() => $this->setAccount($merchant, ['state' => $state, 'last_error_code' => $code]), false);
        return null;
    }

    /**
     * Esito CAS con mapping atomico. $tenant=true: il merchant deve essere ancora idoneo, altrimenti l'esito tardivo
     * non viene adottato (account sospeso, lavoro fallito con la stessa versione). Restituisce la nuova versione o null.
     */
    private function outcome(array $job, int $version, #[\SensitiveParameter] callable $cas, int $merchant, #[\SensitiveParameter] ?callable $mapping, bool $tenant = true): ?int
    {
        try {
            return $cas(function (#[\SensitiveParameter] Connection $ignoredConnection) use ($merchant, $mapping, $tenant) {
                if ($tenant) $this->requireTenant($merchant);
                if ($mapping !== null) $mapping();
            });
        } catch (MerchantIneligible) {
            $this->suspend($job, $version, $merchant);
            return null;
        }
    }

    /** Merchant non più idoneo: account sospeso e lavoro fallito, nella stessa transazione della coda. */
    private function suspend(array $job, int $version, int $merchant): void
    {
        $this->jobs->fail($job['id'], $version, 'merchant_ineligible', function (#[\SensitiveParameter] Connection $ignoredConnection) use ($merchant) {
            $account = $this->store->account($merchant);
            if ($account !== null && $account['state'] !== 'suspended') $this->setAccount($merchant, ['state' => 'suspended', 'last_error_code' => 'merchant_ineligible']);
        });
    }

    /**
     * Idoneità letta fresca nella transazione della coda, con le righe identità bloccate in lettura (FOR SHARE):
     * titolare attivo, gestore PG qualificato con quel solo merchant (merchantForManager verifica anche is_active).
     */
    private function requireTenant(int $merchant): void
    {
        $owner = $this->db->createCommand('SELECT user_id FROM {{%merchants}} WHERE id=:m FOR SHARE', [':m' => $merchant])->queryScalar();
        if ($owner === false || $owner === null) throw new MerchantIneligible();
        $this->db->createCommand('SELECT id FROM {{%users}} WHERE id=:u FOR SHARE', [':u' => (int) $owner])->queryScalar();
        if (AccountEligibility::merchantForManager((int) $owner) !== $merchant) throw new MerchantIneligible();
    }

    /** Aggiornamento CAS dell'account riletto: un fallimento deve far fallire l'intero esito (rollback). */
    private function setAccount(int $merchant, array $changes): void
    {
        $account = $this->store->account($merchant) ?? throw new \UnexpectedValueException('account_missing');
        if ($this->store->updateAccount($account, $changes) === null) throw new \UnexpectedValueException('account_changed');
    }

    private function now(): int
    {
        $now = ($this->clock)();
        if (!is_int($now) || $now < 0) throw new \UnexpectedValueException('invalid_clock');
        return $now;
    }
}

/** @internal merchant non più idoneo durante un esito: annulla la transazione del passo. */
final class MerchantIneligible extends \RuntimeException
{
}
