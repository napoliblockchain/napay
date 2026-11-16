<?php
namespace app\components\btcpay\provisioning;

use Yii;
use app\components\btcpay\StoreSettingsPayload;
use app\components\btcpay\provisioning\steps\StepOutcome;
use app\components\btcpay\provisioning\steps\StoreSettingsStep;
use app\components\identity\AccountEligibility;
use yii\db\Connection;

/**
 * SIP-022 passo 3: esecutore `store_update`, un lavoro per chiamata. Applica allo store BTCPay già collegato nome del negozio
 * (senza marker: l'ID remoto è salvato), valuta e impostazioni fisse lette da Napay all'esecuzione, con la chiave del merchant.
 * - Stesso lock di risorsa dello store_link (istanza+negozio): mai in parallelo con la creazione dello store.
 * - Dipendenze: account synced con chiave current e store_link synced con ID remoto; altrimenti attesa (RetryPolicy).
 * - Scope ricontrollato in ogni transazione della coda (merchant idoneo, negozio del merchant); nessuna transazione aperta
 *   durante l'HTTP. PUT idempotente: esito ignoto = nuovo tentativo, mai riconciliazione.
 * - 401/403: lavoro fallito e account in revisione (come i link); 404: store sparito, lavoro fallito per l'admin.
 */
final class ProvisioningSettingsWorker
{
    private const KIND = 'store_update';
    private const LOCK_WAIT = 5;

    private readonly \Closure $clock;

    public function __construct(#[\SensitiveParameter] private readonly Connection $db, #[\SensitiveParameter] private readonly ProvisioningJobs $jobs,
        #[\SensitiveParameter] private readonly ProvisioningStore $store, #[\SensitiveParameter] private readonly StoreSettingsStep $step,
        private readonly string $instance, #[\SensitiveParameter] ?\Closure $clock = null, private readonly RetryPolicy $retry = new RetryPolicy())
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $instance) !== 1) throw new \InvalidArgumentException('invalid_instance');
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** @return bool false se non c'era alcun lavoro dovuto, true se un lavoro è stato preso (qualunque esito). */
    public function runOnce(): bool
    {
        if (Yii::$app->db !== $this->db) throw new \LogicException('worker_connection_mismatch');
        try {
            return $this->processOne();
        } catch (\Throwable $error) {
            throw new \RuntimeException('provisioning_settings_worker_failed');
        }
    }

    private function processOne(): bool
    {
        $job = $this->jobs->claim($this->instance, [self::KIND]);
        if ($job === null) return false;
        if ($job['kind'] !== self::KIND || $job['entity_id'] < 1 || $job['merchant_id'] < 1) {
            $this->jobs->fail($job['id'], $job['version'], 'resource_scope_mismatch');
            return true;
        }
        $lock = 'napay-btcpay-' . substr(hash('sha256', $this->instance . '|store_link|' . $job['entity_id']), 0, 40);
        if ((int) $this->db->createCommand('SELECT GET_LOCK(:l, 0)', [':l' => $lock])->queryScalar() !== 1) {
            $this->jobs->retryLater($job['id'], $job['version'], 'resource_busy', $this->now() + self::LOCK_WAIT);
            return true;
        }
        try {
            $this->sync($job);
        } finally {
            $this->db->createCommand('SELECT RELEASE_LOCK(:l)', [':l' => $lock])->queryScalar();
        }
        return true;
    }

    private function sync(array $job): void
    {
        $snapshot = null;
        $version = $this->outcome($job, $job['version'], fn(#[\SensitiveParameter] $m) => $this->jobs->prepare($job['id'], $job['version'], $m),
            function () use ($job, &$snapshot) { $snapshot = $this->snapshot($job); });
        if ($version === null) return;
        if ($snapshot['waiting'] !== null) { $this->later($job, $version, $snapshot['waiting'], null); return; }
        $result = $this->step->update($snapshot['remote_store_id'], $snapshot['payload'], $snapshot['key']);
        unset($snapshot);
        $code = (string) $result->code;
        switch ($result->kind) {
            case StepOutcome::DONE:
                $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->succeed($job['id'], $version, $m), null);
                return;
            case StepOutcome::FAILED:
                $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->fail($job['id'], $version, $code, $m),
                    $code === 'merchant_key_denied' ? fn() => $this->keyDenied($job) : null);
                return;
            default:
                $this->later($job, $version, $code, $result->retryAfter);
        }
    }

    /** Snapshot nella transazione di preparazione: dipendenze, ID remoto, payload da Napay e chiave corrente. */
    private function snapshot(array $job): array
    {
        $wait = static fn(string $code): array => ['waiting' => $code];
        $account = $this->store->account($job['merchant_id']);
        if ($account === null || $account['state'] !== 'synced') return $wait('account_not_ready');
        $current = $this->store->currentKey($account);
        if ($current === null) return $wait('account_not_ready');
        $link = $this->store->storeLink($job['entity_id']);
        if ($link !== null && $link['merchant_id'] !== $job['merchant_id']) throw new SettingsIneligible('scope_mismatch');
        if ($link === null || $link['state'] !== 'synced' || $link['remote_store_id'] === null) return $wait('store_not_ready');
        $name = $this->db->createCommand('SELECT description FROM {{%stores}} WHERE id=:s', [':s' => $job['entity_id']])->queryScalar();
        $settings = $this->db->createCommand('SELECT ' . implode(', ', array_map(fn($c) => $this->db->quoteColumnName($c), StoreSettingsPayload::COLUMNS))
            . ' FROM {{%storesettings}} WHERE store_id=:s', [':s' => $job['entity_id']])->queryOne();
        if (!is_string($name) || $settings === false) throw new SettingsIneligible('settings_missing');
        // store già collegato (ID remoto salvato): il marker non serve più, il nome è quello del negozio (visibile nel checkout)
        return ['waiting' => null, 'remote_store_id' => $link['remote_store_id'], 'payload' => StoreSettingsPayload::build($name, $settings), 'key' => $current['key']];
    }

    /** Chiave del merchant rifiutata: account in revisione (riprova dell'admin = nuova chiave, SIP-022 R2). */
    private function keyDenied(array $job): void
    {
        $account = $this->store->account($job['merchant_id']) ?? throw new \UnexpectedValueException('account_missing');
        if ($account['state'] === 'synced' && $this->store->updateAccount($account, ['state' => 'reconcile_required', 'last_error_code' => 'merchant_key_denied']) === null) {
            throw new \UnexpectedValueException('account_changed');
        }
    }

    /** Nuovo tentativo con RetryPolicy (Retry-After del server, deferred oltre soglia). */
    private function later(array $job, int $version, string $code, ?string $retryAfter): void
    {
        $decision = $this->retry->decide($retryAfter, max(1, $job['attempts']), $this->now());
        $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->retryLater($job['id'], $version, $code,
            $decision['next_attempt_at'], $decision['deferred'], $m), null);
    }

    /** Esito CAS con scope ricontrollato nella stessa transazione; scope non valido = lavoro fallito. Nuova versione o null. */
    private function outcome(array $job, int $version, #[\SensitiveParameter] callable $cas, #[\SensitiveParameter] ?callable $mapping): ?int
    {
        try {
            return $cas(function (#[\SensitiveParameter] Connection $ignoredConnection) use ($job, $mapping) {
                $this->requireScope($job);
                if ($mapping !== null) $mapping();
            });
        } catch (SettingsIneligible $error) {
            $this->jobs->fail($job['id'], $version, $error->getMessage());
            return null;
        }
    }

    /** Merchant con gestore idoneo e negozio del merchant, righe bloccate in lettura. */
    private function requireScope(array $job): void
    {
        $merchant = $job['merchant_id'];
        $owner = $this->db->createCommand('SELECT user_id FROM {{%merchants}} WHERE id=:m FOR SHARE', [':m' => $merchant])->queryScalar();
        if ($owner === false || $owner === null) throw new SettingsIneligible('merchant_ineligible');
        $this->db->createCommand('SELECT id FROM {{%users}} WHERE id=:u FOR SHARE', [':u' => (int) $owner])->queryScalar();
        if (AccountEligibility::merchantForManager((int) $owner) !== $merchant) throw new SettingsIneligible('merchant_ineligible');
        $storeMerchant = $this->db->createCommand('SELECT merchant_id FROM {{%stores}} WHERE id=:s FOR SHARE', [':s' => $job['entity_id']])->queryScalar();
        if ($storeMerchant === false || (int) $storeMerchant !== $merchant) throw new SettingsIneligible('scope_mismatch');
    }

    private function now(): int
    {
        $now = ($this->clock)();
        if (!is_int($now) || $now < 0) throw new \UnexpectedValueException('invalid_clock');
        return $now;
    }
}

/** @internal scope del lavoro non più valido durante una transazione: lavoro fallito, nessuna chiamata. */
final class SettingsIneligible extends \RuntimeException
{
}
