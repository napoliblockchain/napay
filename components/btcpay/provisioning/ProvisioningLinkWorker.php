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
 * Nessun loop, daemon o console; la presa automatica delle riconciliazioni non è di questo micro-step.
 */
final class ProvisioningLinkWorker
{
    private const KINDS = ['store_link' => 'create_store', 'pos_link' => 'create_pos_app'];
    private const LOCK_WAIT = 5;

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

    private function processOne(): bool
    {
        $job = $this->jobs->claim($this->instance, array_keys(self::KINDS));
        if ($job === null) return false;
        if (!isset(self::KINDS[$job['kind']]) || $job['entity_id'] < 1 || $job['merchant_id'] < 1) {
            $this->jobs->fail($job['id'], $job['version'], 'resource_scope_mismatch');
            return true;
        }
        $lock = 'napay-btcpay-' . substr(hash('sha256', $this->instance . '|' . $job['kind'] . '|' . $job['entity_id']), 0, 40);
        if ((int) $this->db->createCommand('SELECT GET_LOCK(:l, 0)', [':l' => $lock])->queryScalar() !== 1) {
            $this->jobs->retryLater($job['id'], $job['version'], 'resource_busy', $this->now() + self::LOCK_WAIT);
            return true;
        }
        try {
            $this->link($job);
        } finally {
            $this->db->createCommand('SELECT RELEASE_LOCK(:l)', [':l' => $lock])->queryScalar();
        }
        return true;
    }

    private function link(array $job): void
    {
        $snapshot = null; $key = null;
        // Preparazione fenced: scope prima di qualunque mapping, poi dipendenze, mapping e lettura della chiave.
        $version = $this->outcome($job, $job['version'], fn(#[\SensitiveParameter] $m) => $this->jobs->prepare($job['id'], $job['version'], $m),
            function () use ($job, &$snapshot, &$key) { [$snapshot, $key] = $this->snapshot($job); });
        if ($version === null) return;
        if ($snapshot['waiting'] !== null) { $this->later($job, $version, StepOutcome::pending($snapshot['waiting']), false); return; }
        $step = $job['kind'] === 'store_link' ? $this->storeStep : $this->posStep;
        if ($snapshot['uncertain'] || $snapshot['state'] !== 'pending' || $snapshot[$this->remoteField($job)] !== null) {
            // Esito di un POST precedente incerto o mapping già oltre pending: solo GET (o stato già finale).
            $result = $step->reconcile($snapshot, $key);
            unset($key);
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
    private function outcome(array $job, int $version, #[\SensitiveParameter] callable $cas, #[\SensitiveParameter] ?callable $mapping, bool $inFlight = false): ?int
    {
        try {
            return $cas(function (#[\SensitiveParameter] Connection $ignoredConnection) use ($job, $mapping) {
                $this->requireScope($job);
                if ($mapping !== null) $mapping();
            });
        } catch (LinkIneligible $error) {
            $code = $error->getMessage();
            if ($inFlight) {
                $this->jobs->reconcile($job['id'], $version, $code, function (#[\SensitiveParameter] Connection $ignoredConnection) use ($job, $code) { $this->uncertain($job, $code); });
            } else {
                $this->jobs->fail($job['id'], $version, $code);
            }
            return null;
        }
    }

    /**
     * Snapshot nella transazione di preparazione. Dipendenze: account synced con chiave current; per il POS anche
     * lo store del negozio synced. Mancanti = attesa, nessun mapping nuovo. Restituisce [snapshot, chiave].
     */
    private function snapshot(array $job): array
    {
        $wait = static fn(string $code): array => [['waiting' => $code], null];
        $account = $this->store->account($job['merchant_id']);
        if ($account === null || $account['state'] !== 'synced') return $wait('account_not_ready');
        $current = $this->store->currentKey($account);
        if ($current === null) return $wait('account_not_ready');
        if ($job['kind'] === 'store_link') {
            $link = $this->store->ensureStoreLink($account, $job['entity_id']);
            $snapshot = ['store_name' => 'Negozio ' . $job['entity_id'], 'remote_store_id' => $link['remote_store_id']];
        } else {
            $terminal = $this->terminal($job['entity_id']);
            $storeLink = $this->store->storeLink($terminal['store_id']);
            if ($storeLink === null || $storeLink['merchant_id'] !== $job['merchant_id'] || $storeLink['state'] !== 'synced' || $storeLink['remote_store_id'] === null) return $wait('store_not_ready');
            $link = $this->store->ensurePosLink($storeLink, $job['entity_id']);
            if ($link['store_id'] !== $terminal['store_id']) throw new LinkIneligible('scope_mismatch');
            $snapshot = ['terminal_name' => 'Cassa ' . $job['entity_id'], 'remote_store_id' => $storeLink['remote_store_id'], 'remote_app_id' => $link['remote_app_id']];
        }
        return [$snapshot + ['waiting' => null, 'state' => $link['state'], 'marker' => $link['marker'], 'account_state' => $account['state'],
            'uncertain' => $link['state'] === 'reconcile_required' || $this->otherInFlight($job), 'in_flight' => false], $current['key']];
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
        $storeMerchant = $this->db->createCommand('SELECT merchant_id FROM {{%stores}} WHERE id=:s FOR SHARE', [':s' => $storeId])->queryScalar();
        if ($storeMerchant === false || (int) $storeMerchant !== $merchant) throw new LinkIneligible('scope_mismatch');
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
