<?php
namespace app\components\btcpay\provisioning;

use Yii;
use app\components\btcpay\StoreSettingsPayload;
use app\components\btcpay\provisioning\steps\StepOutcome;
use app\components\btcpay\provisioning\steps\StoreArchiveStep;
use app\components\btcpay\provisioning\steps\StoreSettingsStep;
use app\components\btcpay\provisioning\steps\StoreWalletStep;
use app\components\identity\AccountEligibility;
use yii\db\Connection;

/**
 * SIP-022 passi 3 e 3b: esecutore dei lavori sullo store BTCPay di un negozio già collegato, un lavoro per chiamata,
 * con la chiave corrente del merchant e valori letti da Napay all'esecuzione (mai dal lavoro).
 * - `store_update`: nome del negozio (senza marker: l'ID remoto è salvato), valuta e impostazioni fisse (PUT idempotente).
 * - `store_archive` (negozio eliminato in Napay): DELETE delle app POS collegate (404 = già rimossa), poi nome
 *   "archiviato" sullo store, mapping chiusi (`suspended`, `store_archived`). Lo store non viene mai cancellato.
 *   Store mai collegato: niente da fare. Creazione ancora incerta: decide l'admin.
 * - `store_wallet` (xpub del merchant, decisione A): metodo di pagamento on-chain impostato con la xpub salvata in
 *   Napay al momento dell'esecuzione, oppure rimosso se il merchant l'ha tolta. Napay non custodisce chiavi private.
 * - Stesso lock di risorsa dello store_link (istanza+negozio): mai in parallelo con la creazione dello store.
 * - Scope ricontrollato in ogni transazione della coda; nessuna transazione aperta durante l'HTTP. Esito ignoto =
 *   nuovo tentativo (operazioni idempotenti), mai riconciliazione. 401/403: lavoro fallito e account in revisione.
 */
final class ProvisioningSettingsWorker
{
    private const KINDS = ['store_update', 'store_archive', 'store_wallet'];
    private const LOCK_WAIT = 5;
    private const ARCHIVED_SUFFIX = ' - archiviato';

    private readonly \Closure $clock;

    public function __construct(#[\SensitiveParameter] private readonly Connection $db, #[\SensitiveParameter] private readonly ProvisioningJobs $jobs,
        #[\SensitiveParameter] private readonly ProvisioningStore $store, #[\SensitiveParameter] private readonly StoreSettingsStep $step,
        private readonly string $instance, #[\SensitiveParameter] ?\Closure $clock = null, private readonly RetryPolicy $retry = new RetryPolicy(),
        #[\SensitiveParameter] private readonly ?StoreArchiveStep $archiveStep = null, #[\SensitiveParameter] private readonly ?StoreWalletStep $walletStep = null)
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
        $job = $this->jobs->claim($this->instance, self::KINDS);
        if ($job === null) return false;
        if (!in_array($job['kind'], self::KINDS, true) || $job['entity_id'] < 1 || $job['merchant_id'] < 1) {
            $this->jobs->fail($job['id'], $job['version'], 'resource_scope_mismatch');
            return true;
        }
        $lock = 'napay-btcpay-' . substr(hash('sha256', $this->instance . '|store_link|' . $job['entity_id']), 0, 40);
        if ((int) $this->db->createCommand('SELECT GET_LOCK(:l, 0)', [':l' => $lock])->queryScalar() !== 1) {
            $this->jobs->retryLater($job['id'], $job['version'], 'resource_busy', $this->now() + self::LOCK_WAIT);
            return true;
        }
        try {
            match ($job['kind']) {
                'store_archive' => $this->archive($job),
                'store_wallet' => $this->wallet($job),
                default => $this->sync($job),
            };
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
        $this->finish($job, $version, $result, null);
    }

    /** store_wallet: xpub impostata o metodo on-chain rimosso, secondo il valore attuale in Napay. */
    private function wallet(array $job): void
    {
        if ($this->walletStep === null) throw new \LogicException('wallet_step_missing');
        $snapshot = null;
        $version = $this->outcome($job, $job['version'], fn(#[\SensitiveParameter] $m) => $this->jobs->prepare($job['id'], $job['version'], $m),
            function () use ($job, &$snapshot) { $snapshot = $this->walletSnapshot($job); });
        if ($version === null) return;
        if ($snapshot['waiting'] !== null) { $this->later($job, $version, $snapshot['waiting'], null); return; }
        $result = $snapshot['scheme'] === null
            ? $this->walletStep->remove($snapshot['remote_store_id'], $snapshot['key'])
            : $this->walletStep->set($snapshot['remote_store_id'], $snapshot['scheme'], $snapshot['key']);
        unset($snapshot);
        $this->finish($job, $version, $result, null);
    }

    /** Snapshot di store_wallet: store collegato, chiave corrente e xpub attuale (null = da rimuovere). */
    private function walletSnapshot(array $job): array
    {
        $link = $this->store->storeLink($job['entity_id']);
        if ($link !== null && $link['merchant_id'] !== $job['merchant_id']) throw new SettingsIneligible('scope_mismatch');
        if ($link !== null && $link['state'] === 'suspended') throw new SettingsIneligible('store_archived');
        [$key, $waiting] = $this->currentKey($job);
        if ($waiting !== null) return ['waiting' => $waiting];
        if ($link === null || $link['state'] !== 'synced' || $link['remote_store_id'] === null) return ['waiting' => 'store_not_ready'];
        $scheme = $this->db->createCommand('SELECT derivationScheme FROM {{%storesettings}} WHERE store_id=:s', [':s' => $job['entity_id']])->queryScalar();
        if ($scheme === false) throw new SettingsIneligible('settings_missing');
        $scheme = is_string($scheme) ? trim($scheme) : '';
        return ['waiting' => null, 'remote_store_id' => $link['remote_store_id'], 'scheme' => $scheme === '' ? null : $scheme, 'key' => $key];
    }

    /** store_archive: app POS rimosse una per volta (mapping chiuso dopo ognuna), poi store rinominato e mapping chiuso. */
    private function archive(array $job): void
    {
        if ($this->archiveStep === null) throw new \LogicException('archive_step_missing');
        $plan = null;
        $version = $this->outcome($job, $job['version'], fn(#[\SensitiveParameter] $m) => $this->jobs->prepare($job['id'], $job['version'], $m),
            function () use ($job, &$plan) { $plan = $this->archivePlan($job); }, false);
        if ($version === null) return;
        if ($plan['nothing']) { $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->succeed($job['id'], $version, $m), null, false); return; }
        if ($plan['waiting'] !== null) { $this->later($job, $version, $plan['waiting'], null); return; }
        foreach ($plan['apps'] as $terminalId => $appId) {
            $result = $this->archiveStep->deleteApp($appId, $plan['key']);
            if ($result->kind !== StepOutcome::DONE) { unset($plan); $this->finish($job, $version, $result, null); return; }
            $version = $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->prepare($job['id'], $version, $m),
                fn() => $this->close('pos_link', $terminalId), false);
            if ($version === null) return;
        }
        $result = $this->step->update($plan['remote_store_id'], $plan['payload'], $plan['key']);
        unset($plan);
        // store sparito su BTCPay: niente da rinominare, il mapping si chiude comunque
        if ($result->kind === StepOutcome::FAILED && $result->code === 'store_missing') $result = StepOutcome::done();
        $this->finish($job, $version, $result, fn() => $this->close('store_link', $job['entity_id']));
    }

    /** Esito finale di un passo: fatto (con chiusura opzionale del mapping), fallito, oppure nuovo tentativo. */
    private function finish(array $job, int $version, StepOutcome $result, #[\SensitiveParameter] ?callable $onDone): void
    {
        $code = (string) $result->code;
        $tenant = $job['kind'] !== 'store_archive';
        switch ($result->kind) {
            case StepOutcome::DONE:
                $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->succeed($job['id'], $version, $m), $onDone, $tenant);
                return;
            case StepOutcome::FAILED:
                $this->outcome($job, $version, fn(#[\SensitiveParameter] $m) => $this->jobs->fail($job['id'], $version, $code, $m),
                    $code === 'merchant_key_denied' ? fn() => $this->keyDenied($job) : null, $tenant);
                return;
            default:
                $this->later($job, $version, $code, $result->retryAfter);
        }
    }

    /** Snapshot di store_update nella transazione di preparazione: dipendenze, ID remoto, payload da Napay e chiave. */
    private function snapshot(array $job): array
    {
        $wait = static fn(string $code): array => ['waiting' => $code];
        $link = $this->store->storeLink($job['entity_id']);
        if ($link !== null && $link['merchant_id'] !== $job['merchant_id']) throw new SettingsIneligible('scope_mismatch');
        if ($link !== null && $link['state'] === 'suspended') throw new SettingsIneligible('store_archived');
        [$key, $waiting] = $this->currentKey($job);
        if ($waiting !== null) return $wait($waiting);
        if ($link === null || $link['state'] !== 'synced' || $link['remote_store_id'] === null) return $wait('store_not_ready');
        [$name, $settings] = $this->settings($job['entity_id']);
        return ['waiting' => null, 'remote_store_id' => $link['remote_store_id'], 'payload' => StoreSettingsPayload::build($name, $settings), 'key' => $key];
    }

    /** Piano di store_archive: app POS ancora collegate, payload col nome "archiviato", chiave; o niente da fare. */
    private function archivePlan(array $job): array
    {
        $link = $this->store->storeLink($job['entity_id']);
        if ($link !== null && $link['merchant_id'] !== $job['merchant_id']) throw new SettingsIneligible('scope_mismatch');
        // mai collegato, creazione rifiutata o già archiviato: nessuno store remoto da toccare
        if ($link === null || $link['remote_store_id'] === null && in_array($link['state'], ['pending', 'failed'], true)
            || $link['state'] === 'suspended') return ['nothing' => true];
        if ($link['state'] !== 'synced') throw new SettingsIneligible('store_link_uncertain');
        [$key, $waiting] = $this->currentKey($job);
        if ($waiting !== null) return ['nothing' => false, 'waiting' => $waiting];
        $apps = [];
        foreach ($this->db->createCommand("SELECT terminal_id, remote_app_id FROM {{%btcpay_pos_links}} WHERE instance=:i AND store_id=:s AND state='synced' AND remote_app_id IS NOT NULL ORDER BY id",
            [':i' => $this->instance, ':s' => $job['entity_id']])->queryAll() as $row) {
            $apps[(int) $row['terminal_id']] = (string) $row['remote_app_id'];
        }
        [$name, $settings] = $this->settings($job['entity_id']);
        $name = mb_substr(trim($name), 0, 50 - mb_strlen(self::ARCHIVED_SUFFIX)) . self::ARCHIVED_SUFFIX;
        return ['nothing' => false, 'waiting' => null, 'apps' => $apps, 'remote_store_id' => $link['remote_store_id'],
            'payload' => StoreSettingsPayload::build($name, $settings), 'key' => $key];
    }

    /** @return array{0: ?string, 1: ?string} [chiave corrente, codice di attesa] */
    private function currentKey(array $job): array
    {
        $account = $this->store->account($job['merchant_id']);
        if ($account === null || $account['state'] !== 'synced') return [null, 'account_not_ready'];
        $current = $this->store->currentKey($account);
        return $current === null ? [null, 'account_not_ready'] : [$current['key'], null];
    }

    /** @return array{0: string, 1: array} nome del negozio e impostazioni dello store */
    private function settings(int $storeId): array
    {
        $name = $this->db->createCommand('SELECT description FROM {{%stores}} WHERE id=:s', [':s' => $storeId])->queryScalar();
        $settings = $this->db->createCommand('SELECT ' . implode(', ', array_map(fn($c) => $this->db->quoteColumnName($c), StoreSettingsPayload::COLUMNS))
            . ' FROM {{%storesettings}} WHERE store_id=:s', [':s' => $storeId])->queryOne();
        if (!is_string($name) || $settings === false) throw new SettingsIneligible('settings_missing');
        return [$name, $settings];
    }

    /** Mapping chiuso dopo l'archiviazione (ID remoto conservato per lo storico). */
    private function close(string $kind, int $entityId): void
    {
        $link = $kind === 'store_link' ? $this->store->storeLink($entityId) : $this->store->posLink($entityId);
        if ($link === null || $link['state'] === 'suspended') return;
        $changes = ['state' => 'suspended', 'last_error_code' => 'store_archived'];
        $updated = $kind === 'store_link' ? $this->store->updateStoreLink($link, $changes) : $this->store->updatePosLink($link, $changes);
        if ($updated === null) throw new \UnexpectedValueException('link_changed');
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
            $decision['next_attempt_at'], $decision['deferred'], $m), null, $job['kind'] !== 'store_archive');
    }

    /**
     * Esito CAS con scope ricontrollato nella stessa transazione; scope non valido = lavoro fallito. Nuova versione o null.
     * $tenant=false (archiviazione): basta che il negozio sia del merchant, anche se il merchant non è più idoneo.
     */
    private function outcome(array $job, int $version, #[\SensitiveParameter] callable $cas, #[\SensitiveParameter] ?callable $mapping, bool $tenant = true): ?int
    {
        try {
            return $cas(function (#[\SensitiveParameter] Connection $ignoredConnection) use ($job, $mapping, $tenant) {
                $this->requireScope($job, $tenant);
                if ($mapping !== null) $mapping();
            });
        } catch (SettingsIneligible $error) {
            $this->jobs->fail($job['id'], $version, $error->getMessage());
            return null;
        }
    }

    /** Negozio del merchant (righe bloccate in lettura) e, se richiesto, merchant con gestore idoneo. */
    private function requireScope(array $job, bool $tenant): void
    {
        $merchant = $job['merchant_id'];
        if ($tenant) {
            $owner = $this->db->createCommand('SELECT user_id FROM {{%merchants}} WHERE id=:m FOR SHARE', [':m' => $merchant])->queryScalar();
            if ($owner === false || $owner === null) throw new SettingsIneligible('merchant_ineligible');
            $this->db->createCommand('SELECT id FROM {{%users}} WHERE id=:u FOR SHARE', [':u' => (int) $owner])->queryScalar();
            if (AccountEligibility::merchantForManager((int) $owner) !== $merchant) throw new SettingsIneligible('merchant_ineligible');
        }
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
