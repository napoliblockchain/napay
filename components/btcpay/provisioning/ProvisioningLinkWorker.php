<?php
namespace app\components\btcpay\provisioning;

use Yii;
use app\components\btcpay\provisioning\steps\PosLinkStep;
use app\components\btcpay\provisioning\steps\StepOutcome;
use app\components\btcpay\provisioning\steps\StoreLinkStep;
use app\components\identity\AccountEligibility;
use yii\db\Connection;

/**
 * SIP-022 B1 W3c: esecutore `store_link` / `pos_link` (tabella 3.2), un lavoro per chiamata, con la chiave corrente
 * dell'account merchant già sincronizzato. Nessuna nuova persistenza, crittografia o policy: solo Jobs/Store/passi W2.
 * - Risorsa serializzata con GET_LOCK non bloccante per istanza+tipo+entità, tenuto durante l'HTTP e sempre rilasciato;
 *   nessuna transazione DB aperta durante la rete.
 * - Preparazione fenced (prepare W3a): merchant idoneo e scope negozio/terminale letti sulla stessa connessione, poi
 *   dipendenze e mapping. Dipendenza mancante = retry_wait senza HTTP; l'account non viene mai creato qui.
 * - Un solo POST per risorsa: mapping in riconciliazione o un altro lavoro della stessa risorsa con marker in volo
 *   (anche di una generazione precedente) = solo GET di riconciliazione, che adotta il marker esatto se unico.
 * - Ogni esito: CAS W3a con mapping atomico e nuovo ricontrollo di scope; un esito tardivo non idoneo non adotta ID e,
 *   dopo un POST, conserva l'incertezza (riconciliazione con marker). Nomi remoti generici: ID locale + marker.
 * W3e: reconcileOnce() prende un lavoro reconcile_required (API W3d) e fa SOLO GET: adotta il marker unico o
 *   riprogramma la GET (deferred per l'admin quando ripetere non serve); non crea mai mapping, account o marker.
 * B5 riprova dell'admin (generazione > 1, nuovo INSERT dal web): un mapping failed/suspended senza ID remoto torna in
 *   riconciliazione e si cerca il marker con una GET. Trovato = adottato (nessun doppione). Assente in una lista completa
 *   e nessun POST tentato negli ultimi ADMIN_RETRY_QUIET secondi = lavori precedenti chiusi (superseded) e mapping di
 *   nuovo pending: il passo successivo crea la risorsa. Ambiguo, 401/403 o lettura non disponibile: come sempre.
 * Nessun loop o daemon: un lavoro per chiamata.
 */
final class ProvisioningLinkWorker
{
    private const KINDS = ['store_link' => 'create_store', 'pos_link' => 'create_pos_app'];
    private const LOCK_WAIT = 5;
    /** Secondi senza POST in volo su quella risorsa prima che il riprova dell'admin possa ricreare dopo una GET a vuoto. */
    public const ADMIN_RETRY_QUIET = 900;

    private readonly \Closure $clock;

    public function __construct(#[\SensitiveParameter] private readonly Connection $db, #[\SensitiveParameter] private readonly ProvisioningJobs $jobs,
        #[\SensitiveParameter] private readonly ProvisioningStore $store, #[\SensitiveParameter] private readonly StoreLinkStep $storeStep,
        #[\SensitiveParameter] private readonly PosLinkStep $posStep, private readonly string $instance, #[\SensitiveParameter] ?\Closure $clock = null,
        private readonly RetryPolicy $retry = new RetryPolicy())
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $instance) !== 1) throw new \InvalidArgumentException('invalid_instance');
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
            // Frame nativi/Yii delle transazioni possono esporre connessioni e chiavi: nessun previous.
            throw new \RuntimeException('provisioning_link_worker_failed');
        }
    }

    /** W3e: una riconciliazione GET-only dei link; false se non c'era alcuna riconciliazione dovuta. */
    public function reconcileOnce(): bool
    {
        if (Yii::$app->db !== $this->db) throw new \LogicException('worker_connection_mismatch');
        try {
            return $this->reconcileOne();
        } catch (\Throwable $error) {
            throw new \RuntimeException('provisioning_link_worker_failed');
        }
    }

    private function processOne(): bool
    {
        $job = $this->jobs->claim($this->instance, array_keys(self::KINDS));
        if ($job === null) return false;
        if (!$this->validJob($job)) {
            $this->jobs->fail($job['id'], $job['version'], 'resource_scope_mismatch');
            return true;
        }
        $this->locked($job, fn() => $this->link($job), fn() => $this->jobs->retryLater($job['id'], $job['version'], 'resource_busy', $this->now() + self::LOCK_WAIT));
        return true;
    }

    private function reconcileOne(): bool
    {
        $job = $this->jobs->claimReconciliation($this->instance, array_keys(self::KINDS));
        if ($job === null) return false;
        if (!$this->validJob($job)) {
            $this->jobs->retryReconciliation($job['id'], $job['version'], 'resource_scope_mismatch', $this->now() + self::LOCK_WAIT, true);
            return true;
        }
        $this->locked($job, fn() => $this->reconcileLink($job),
            fn() => $this->jobs->retryReconciliation($job['id'], $job['version'], 'resource_busy', $this->now() + self::LOCK_WAIT));
        return true;
    }

    private function validJob(array $job): bool
    {
        return isset(self::KINDS[$job['kind']]) && $job['entity_id'] >= 1 && $job['merchant_id'] >= 1;
    }

    /** Lock di risorsa (istanza+tipo+entità) attraverso l'HTTP, sempre rilasciato; occupato = $busy senza chiamate. */
    private function locked(array $job, #[\SensitiveParameter] callable $work, #[\SensitiveParameter] callable $busy): void
    {
        $lock = 'napay-btcpay-' . substr(hash('sha256', $this->instance . '|' . $job['kind'] . '|' . $job['entity_id']), 0, 40);
        if ((int) $this->db->createCommand('SELECT GET_LOCK(:l, 0)', [':l' => $lock])->queryScalar() !== 1) {
            $busy();
            return;
        }
        try {
            $work();
        } finally {
            $this->db->createCommand('SELECT RELEASE_LOCK(:l)', [':l' => $lock])->queryScalar();
        }
    }

    private function link(array $job): void
    {
        $snapshot = null; $key = null;
        // Preparazione fenced: scope prima di qualunque mapping, poi dipendenze, mapping e lettura della chiave.
        $version = $this->outcome($job, $job['version'], fn(#[\SensitiveParameter] $m) => $this->jobs->prepare($job['id'], $job['version'], $m),
            function () use ($job, &$snapshot, &$key) { [$snapshot, $key] = $this->snapshot($job, true); });
        if ($version === null) return;
        if ($snapshot['waiting'] !== null) { $this->later($job, $version, StepOutcome::pending($snapshot['waiting']), false); return; }
        $step = $job['kind'] === 'store_link' ? $this->storeStep : $this->posStep;
        if ($snapshot['uncertain'] || $snapshot['state'] !== 'pending' || $snapshot[$this->remoteField($job)] !== null) {
            // Esito di un POST precedente incerto o mapping già oltre pending: solo GET (o stato già finale).
            $result = $step->reconcile($snapshot, $key);
            unset($key);
            if ($job['generation'] > 1 && $result->kind === StepOutcome::RECONCILE && str_ends_with((string) $result->code, '_not_found')
                && $snapshot['state'] === 'reconcile_required' && $snapshot[$this->remoteField($job)] === null) {
                $this->recreateAfterRetry($job, $version);
                return;
            }
            $this->apply($job, $version, $result, false);
            return;
        }
        $checkpoint = $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->checkpoint($job['id'], $version, self::KINDS[$job['kind']], $m),
            function () use ($job) {
                // Sotto checkpoint: mapping ancora pending senza ID e nessun altro POST in volo sulla stessa risorsa.
                $link = $this->currentLink($job) ?? throw new \UnexpectedValueException('link_missing');
                if ($link['state'] !== 'pending' || $link[$this->remoteField($job)] !== null || $this->otherInFlight($job)) throw new \UnexpectedValueException('link_changed');
            });
        if ($checkpoint === null) { unset($key); return; }
        $result = $step->create($snapshot, $key);
        unset($key);
        $this->apply($job, $checkpoint, $result, true);
    }

    /** W3e: solo GET sul mapping esistente; nessun POST, qualunque sia l'esito. */
    private function reconcileLink(array $job): void
    {
        $snapshot = null; $key = null;
        $version = $this->outcome($job, $job['version'], fn(#[\SensitiveParameter] $m) => $this->jobs->prepareReconciliation($job['id'], $job['version'], $m),
            function () use ($job, &$snapshot, &$key) { [$snapshot, $key] = $this->snapshot($job, false); }, false, true);
        if ($version === null) return;
        if ($snapshot['waiting'] !== null) { $this->retryGet($job, $version, $snapshot['waiting'], null, $snapshot['waiting'] === 'link_missing'); return; }
        $step = $job['kind'] === 'store_link' ? $this->storeStep : $this->posStep;
        $result = $step->reconcile(['in_flight' => true] + $snapshot, $key);
        unset($key);
        $code = (string) $result->code;
        switch ($result->kind) {
            case StepOutcome::DONE:
                $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->succeed($job['id'], $version, $m), fn() => $this->adopt($job, $result), false, true);
                return;
            case StepOutcome::RETRY:
            case StepOutcome::PENDING:
                $this->retryGet($job, $version, $code, $result->retryAfter, false);
                return;
            case StepOutcome::FAILED:
                // 401/403: link bloccato e account in revisione; richiesta non inviata: si riprova; link già chiuso: admin.
                if ($code === 'request_not_sent') { $this->retryGet($job, $version, $code, null, false); return; }
                $this->retryGet($job, $version, $code, null, true, $code === 'merchant_key_denied' ? fn() => $this->failed($job, $code) : null);
                return;
            default:
                // Marker assente o lettura non disponibile: nuova GET più tardi; multiplo o malformato: decide l'admin.
                $again = str_ends_with($code, '_not_found') || str_ends_with($code, '_lookup_unavailable');
                $this->retryGet($job, $version, $code, null, !$again, fn() => $this->uncertain($job, $code));
        }
    }

    /** Nuova GET programmata con RetryPolicy (deferred oltre soglia o se forzato); marker e incertezza conservati. */
    private function retryGet(array $job, int $version, string $code, ?string $retryAfter, bool $defer, #[\SensitiveParameter] ?callable $mapping = null): void
    {
        $decision = $this->retry->decide($retryAfter, max(1, $job['attempts']), $this->now());
        $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->retryReconciliation($job['id'], $version, $code,
            $decision['next_attempt_at'], $defer || $decision['deferred'], $m), $mapping, false, true);
    }

    /** Applica l'esito del passo: CAS W3a con mapping atomico; scope ricontrollato in ogni transazione. */
    private function apply(array $job, int $version, StepOutcome $result, bool $inFlight): void
    {
        $id = $job['id'];
        switch ($result->kind) {
            case StepOutcome::DONE:
                $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->succeed($id, $version, $m), fn() => $this->adopt($job, $result), $inFlight);
                return;
            case StepOutcome::RETRY:
            case StepOutcome::PENDING:
                $this->later($job, $version, $result, $inFlight);
                return;
            case StepOutcome::FAILED:
                $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->fail($id, $version, (string) $result->code, $m),
                    fn() => $this->failed($job, (string) $result->code), $inFlight);
                return;
            default:
                // Ignoto, malformato, marker assente o multiplo: solo riconciliazione, marker del lavoro conservati.
                $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->reconcile($id, $version, (string) $result->code, $m),
                    fn() => $this->uncertain($job, (string) $result->code), $inFlight);
        }
    }

    /** 429 (con prova di mancata esecuzione dopo un POST) o dipendenza non pronta: RetryPolicy, nessun nuovo POST ora. */
    private function later(array $job, int $version, StepOutcome $result, bool $inFlight): void
    {
        $decision = $this->retry->decide($result->retryAfter, max(1, $job['attempts']), $this->now());
        if ($inFlight) {
            $version = $result->kind === StepOutcome::RETRY
                ? $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->remoteRejected($job['id'], $version, 429, $m), null, true)
                // Nessuna chiamata eseguita dal passo (dipendenza): marker azzerati senza adottare nulla.
                : $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->advance($job['id'], $version, $m), null, true);
            if ($version === null) return;
        }
        $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->retryLater($job['id'], $version, (string) $result->code,
            $decision['next_attempt_at'], $decision['deferred'], $m), null);
    }

    /**
     * Esito CAS con mapping atomico e scope ricontrollato. Scope non più valido: nessun ID adottato; dopo un POST
     * il lavoro va in riconciliazione con i marker (incertezza conservata), altrimenti fallisce. Nuova versione o null.
     */
    private function outcome(array $job, int $version, #[\SensitiveParameter] callable $cas, #[\SensitiveParameter] ?callable $mapping, bool $inFlight = false, bool $reconciling = false): ?int
    {
        try {
            return $cas(function (#[\SensitiveParameter] Connection $ignoredConnection) use ($job, $mapping) {
                $this->requireScope($job);
                if ($mapping !== null) $mapping();
            });
        } catch (LinkIneligible $error) {
            $code = $error->getMessage();
            if ($reconciling) {
                // Riconciliazione: nessuna adozione, marker conservato, decide l'admin.
                $this->jobs->retryReconciliation($job['id'], $version, $code, $this->now() + self::LOCK_WAIT, true);
            } elseif ($inFlight) {
                $this->jobs->reconcile($job['id'], $version, $code, function (#[\SensitiveParameter] Connection $ignoredConnection) use ($job, $code) { $this->uncertain($job, $code); });
            } else {
                $this->jobs->fail($job['id'], $version, $code);
            }
            return null;
        }
    }

    /**
     * Snapshot nella transazione di preparazione. Dipendenze: account synced con chiave current; per il POS anche
     * lo store del negozio synced. Mancanti = attesa, nessun mapping nuovo. $create=false (riconciliazione): il mapping
     * deve esistere già, mai creato. Restituisce [snapshot, chiave].
     */
    private function snapshot(array $job, bool $create): array
    {
        $wait = static fn(string $code): array => [['waiting' => $code], null];
        $account = $this->store->account($job['merchant_id']);
        if ($account === null || $account['state'] !== 'synced') return $wait('account_not_ready');
        $current = $this->store->currentKey($account);
        if ($current === null) return $wait('account_not_ready');
        if ($job['kind'] === 'store_link') {
            $link = $create ? $this->store->ensureStoreLink($account, $job['entity_id']) : $this->store->storeLink($job['entity_id']);
            if ($link === null) return $wait('link_missing');
            if ($link['merchant_id'] !== $job['merchant_id']) throw new LinkIneligible('scope_mismatch');
            if ($create) $link = $this->reopenForRetry($job, $link);
            $snapshot = ['store_name' => 'Negozio ' . $job['entity_id'], 'remote_store_id' => $link['remote_store_id']];
        } else {
            $terminal = $this->terminal($job['entity_id']);
            $storeLink = $this->store->storeLink($terminal['store_id']);
            if ($storeLink === null || $storeLink['merchant_id'] !== $job['merchant_id'] || $storeLink['state'] !== 'synced' || $storeLink['remote_store_id'] === null) return $wait('store_not_ready');
            $link = $create ? $this->store->ensurePosLink($storeLink, $job['entity_id']) : $this->store->posLink($job['entity_id']);
            if ($link === null) return $wait('link_missing');
            if ($link['merchant_id'] !== $job['merchant_id'] || $link['store_id'] !== $terminal['store_id']) throw new LinkIneligible('scope_mismatch');
            if ($create) $link = $this->reopenForRetry($job, $link);
            $snapshot = ['terminal_name' => 'Cassa ' . $job['entity_id'], 'remote_store_id' => $storeLink['remote_store_id'], 'remote_app_id' => $link['remote_app_id']];
        }
        return [$snapshot + ['waiting' => null, 'state' => $link['state'], 'marker' => $link['marker'], 'account_state' => $account['state'],
            'uncertain' => $link['state'] === 'reconcile_required' || $this->otherInFlight($job), 'in_flight' => false], $current['key']];
    }

    /** Riprova dell'admin: mapping chiuso (failed/suspended) senza ID remoto torna in riconciliazione; altrimenti invariato. */
    private function reopenForRetry(array $job, array $link): array
    {
        if ($job['generation'] < 2 || !in_array($link['state'], ['failed', 'suspended'], true) || $link[$this->remoteField($job)] !== null) return $link;
        $this->updateLink($job, $link, ['state' => 'reconcile_required', 'last_error_code' => 'admin_retry']);
        return $this->currentLink($job) ?? throw new \UnexpectedValueException('link_missing');
    }

    /**
     * Riprova dell'admin con marker assente da una lista completa: dopo ADMIN_RETRY_QUIET secondi dall'ultimo POST in volo
     * della risorsa, lavori precedenti chiusi e mapping pending (la creazione avviene al passo successivo, con checkpoint);
     * prima, nuova verifica alla scadenza. Nessuna chiamata remota qui.
     */
    private function recreateAfterRetry(array $job, int $version): void
    {
        $since = $this->db->createCommand('SELECT MAX(in_flight_started_at) FROM {{%btcpay_provisioning_jobs}} WHERE instance=:i AND kind=:k AND entity_id=:e AND id<>:id AND in_flight_op IS NOT NULL',
            [':i' => $this->instance, ':k' => $job['kind'], ':e' => $job['entity_id'], ':id' => $job['id']])->queryScalar();
        $quietUntil = $since === null || $since === false ? 0 : (int) $since + self::ADMIN_RETRY_QUIET;
        if ($this->now() < $quietUntil) {
            $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->retryLater($job['id'], $version, 'admin_retry_wait', $quietUntil, false, $m), null);
            return;
        }
        $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->retryLater($job['id'], $version, 'admin_retry_recreate', $this->now() + 1, false, $m), function () use ($job) {
            $link = $this->currentLink($job) ?? throw new \UnexpectedValueException('link_missing');
            if ($link['state'] !== 'reconcile_required' || $link[$this->remoteField($job)] !== null) throw new \UnexpectedValueException('link_changed');
            $params = [':i' => $this->instance, ':k' => $job['kind'], ':e' => $job['entity_id'], ':id' => $job['id']];
            if ((int) $this->db->createCommand("SELECT COUNT(*) FROM {{%btcpay_provisioning_jobs}} WHERE instance=:i AND kind=:k AND entity_id=:e AND id<>:id AND state IN ('running','in_flight') FOR UPDATE", $params)->queryScalar() > 0) {
                throw new \UnexpectedValueException('link_changed');
            }
            $this->db->createCommand("UPDATE {{%btcpay_provisioning_jobs}} SET state='failed', last_error_code='superseded', lease_owner=NULL, lease_until=NULL,"
                . ' in_flight_op=NULL, in_flight_started_at=NULL, in_flight_owner=NULL, version=version+1, updated_at=:now'
                . " WHERE instance=:i AND kind=:k AND entity_id=:e AND id<>:id AND (state IN ('pending','retry_wait','deferred','reconcile_required') OR in_flight_op IS NOT NULL)",
                $params + [':now' => $this->now()])->execute();
            $this->updateLink($job, $link, ['state' => 'pending', 'last_error_code' => null]);
        });
    }

    /** Adozione dell'ID remoto solo su un mapping ancora pending/in riconciliazione e senza un ID diverso. */
    private function adopt(array $job, StepOutcome $result): void
    {
        $field = $this->remoteField($job);
        $remote = $result->data[$field] ?? throw new \UnexpectedValueException('remote_id_missing');
        $link = $this->currentLink($job) ?? throw new \UnexpectedValueException('link_missing');
        if ($link['state'] === 'synced' && $link[$field] === $remote) return;
        if (!in_array($link['state'], ['pending', 'reconcile_required'], true) || ($link[$field] !== null && $link[$field] !== $remote)) throw new \UnexpectedValueException('link_changed');
        $this->updateLink($job, $link, ['state' => 'synced', $field => $remote, 'last_error_code' => null]);
        // impostazioni, wallet e app POS in attesa dello store: subito dovuti
        if ($job['kind'] === 'store_link') $this->jobs->wakeWaiting($this->instance, $job['merchant_id'], ['store_not_ready']);
    }

    /** Esito ignoto/ambiguo: mapping in riconciliazione (mai cancellato, nessun nuovo marker). */
    private function uncertain(array $job, string $code): void
    {
        $link = $this->currentLink($job);
        if ($link === null || !in_array($link['state'], ['pending', 'reconcile_required'], true)) return;
        if ($link['state'] !== 'reconcile_required' || $link['last_error_code'] !== $code) $this->updateLink($job, $link, ['state' => 'reconcile_required', 'last_error_code' => $code]);
    }

    /** Esito definitivo noto. 401/403: link bloccato e account in revisione; richiesta mai inviata: nessun blocco. */
    private function failed(array $job, string $code): void
    {
        if (in_array($code, ['request_not_sent', 'link_failed', 'link_suspended'], true)) return;
        $link = $this->currentLink($job);
        if ($link !== null && in_array($link['state'], ['pending', 'reconcile_required'], true)) $this->updateLink($job, $link, ['state' => 'failed', 'last_error_code' => $code]);
        if ($code !== 'merchant_key_denied') return;
        $account = $this->store->account($job['merchant_id']) ?? throw new \UnexpectedValueException('account_missing');
        if ($account['state'] === 'synced' && $this->store->updateAccount($account, ['state' => 'reconcile_required', 'last_error_code' => $code]) === null) throw new \UnexpectedValueException('account_changed');
    }

    /**
     * Scope letto fresco nella transazione della coda, righe bloccate in lettura: merchant con gestore idoneo
     * (merchantForManager verifica anche is_active), negozio del merchant, terminale del negozio e del merchant.
     */
    private function requireScope(array $job): void
    {
        $merchant = $job['merchant_id'];
        $owner = $this->db->createCommand('SELECT user_id FROM {{%merchants}} WHERE id=:m FOR SHARE', [':m' => $merchant])->queryScalar();
        if ($owner === false || $owner === null) throw new LinkIneligible('merchant_ineligible');
        $this->db->createCommand('SELECT id FROM {{%users}} WHERE id=:u FOR SHARE', [':u' => (int) $owner])->queryScalar();
        if (AccountEligibility::merchantForManager((int) $owner) !== $merchant) throw new LinkIneligible('merchant_ineligible');
        if ($job['kind'] === 'store_link') {
            $storeId = $job['entity_id'];
        } else {
            $terminal = $this->terminal($job['entity_id']);
            if ($terminal['merchant_id'] !== $merchant) throw new LinkIneligible('scope_mismatch');
            $storeId = $terminal['store_id'];
        }
        $store = $this->db->createCommand('SELECT merchant_id, archived_at FROM {{%stores}} WHERE id=:s FOR SHARE', [':s' => $storeId])->queryOne();
        if ($store === false || (int) $store['merchant_id'] !== $merchant) throw new LinkIneligible('scope_mismatch');
        // negozio eliminato in Napay (SIP-022 passo 3b): nessuno store o app creati o adottati
        if ($store['archived_at'] !== null) throw new LinkIneligible('store_archived');
    }

    /** Terminale bloccato in lettura e appartenente al merchant del lavoro. */
    private function terminal(int $terminalId): array
    {
        $row = $this->db->createCommand('SELECT store_id, merchant_id FROM {{%pos_terminals}} WHERE id=:t FOR SHARE', [':t' => $terminalId])->queryOne();
        if ($row === false) throw new LinkIneligible('scope_mismatch');
        return ['store_id' => (int) $row['store_id'], 'merchant_id' => (int) $row['merchant_id']];
    }

    /** Un altro lavoro della stessa risorsa con marker in volo (anche reconcile_required): il suo POST è incerto. */
    private function otherInFlight(array $job): bool
    {
        return (int) $this->db->createCommand('SELECT COUNT(*) FROM {{%btcpay_provisioning_jobs}} WHERE instance=:i AND kind=:k AND entity_id=:e AND id<>:id AND in_flight_op IS NOT NULL',
            [':i' => $this->instance, ':k' => $job['kind'], ':e' => $job['entity_id'], ':id' => $job['id']])->queryScalar() > 0;
    }

    private function currentLink(array $job): ?array
    {
        $link = $job['kind'] === 'store_link' ? $this->store->storeLink($job['entity_id']) : $this->store->posLink($job['entity_id']);
        if ($link !== null && $link['merchant_id'] !== $job['merchant_id']) throw new LinkIneligible('scope_mismatch');
        return $link;
    }

    /** Aggiornamento CAS del mapping: un fallimento annulla l'intero esito (rollback). */
    private function updateLink(array $job, array $link, array $changes): void
    {
        $updated = $job['kind'] === 'store_link' ? $this->store->updateStoreLink($link, $changes) : $this->store->updatePosLink($link, $changes);
        if ($updated === null) throw new \UnexpectedValueException('link_changed');
    }

    private function remoteField(array $job): string
    {
        return $job['kind'] === 'store_link' ? 'remote_store_id' : 'remote_app_id';
    }

    private function now(): int
    {
        $now = ($this->clock)();
        if (!is_int($now) || $now < 0) throw new \UnexpectedValueException('invalid_clock');
        return $now;
    }
}

/** @internal scope del lavoro non più valido durante una transazione: annulla il passo, nessun ID adottato. */
final class LinkIneligible extends \RuntimeException
{
}
