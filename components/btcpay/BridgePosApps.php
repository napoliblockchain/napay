<?php

namespace app\components\btcpay;

use app\components\Log;
use app\components\btcpay\provisioning\ProvisioningHttpClient;
use app\components\btcpay\provisioning\ProvisioningLinkWorker;
use app\components\btcpay\provisioning\ProvisioningOutbox;
use app\components\btcpay\provisioning\RetryPolicy;
use app\components\btcpay\provisioning\steps\PosLinkStep;
use app\components\btcpay\provisioning\steps\StepOutcome;
use app\components\btcpay\provisioning\steps\StoreWebhookStep;
use yii\base\InvalidConfigException;
use yii\db\Connection;
use yii\db\IntegrityException;

/**
 * SIP-030 passo 5: incassi BTC dal POS v2 per i negozi v1 rimasti sugli store BTCPay del ponte (BTCPAY_STORES_API_KEY,
 * utente membro degli store v1), fino alla migrazione al modello per merchant (SIP-022). Comando one-shot del cron.
 * - Negozio v1 = `storesettings.bps_storeid` non vuoto e negozio non archiviato: riga in btcpay_bridge_stores.
 * - Webhook Napay sullo store (stesso passo e segreto derivato dei negozi del provisioning), se configurato.
 * - Un'app POS per ogni terminale abilitato (stessi passo e configurazione del provisioning): checkpoint `creating`
 *   salvato prima di un solo POST con marker nel nome; esito ignoto = solo GET con adozione del marker unico; marker
 *   assente dopo QUIET secondi = nuovo POST con lo stesso marker (un'app tardiva verrebbe adottata, mai due).
 * - Valuta del negozio cambiata: app riconfigurata (PUT completo); app rimossa da BTCPay (404) = nuova app.
 * Stesso server delle fatture dei terminali (BTCPAY_PROVISION_ORIGIN = BTCPAY_URL), proxy di uscita obbligatorio.
 * Mai chiave, segreti o risposte remote in DB, log o output.
 */
final class BridgePosApps
{
    public const QUIET = 900;
    private const LOCK = 'napay-btcpay-bridge';
    private const KEY = '/^[A-Za-z0-9]{20,128}$/D';

    private readonly \Closure $clock;
    private readonly PosLinkStep $posStep;
    private readonly ?StoreWebhookStep $webhookStep;

    public function __construct(private readonly Connection $db, \Closure $withKey, #[\SensitiveParameter] private readonly string $key,
        private readonly string $instance, private readonly ?string $webhookUrl = null, #[\SensitiveParameter] private readonly ?string $webhookMaster = null,
        ?\Closure $clock = null, private readonly RetryPolicy $retry = new RetryPolicy())
    {
        if (preg_match(self::KEY, $key) !== 1 || preg_match('/^[0-9a-f]{64}$/D', $instance) !== 1) throw new \InvalidArgumentException('invalid_bridge_config');
        if (($webhookUrl === null) !== ($webhookMaster === null)
            || ($webhookUrl !== null && (preg_match('#^https://[^\s/]+(/[^\s]*)?$#D', $webhookUrl) !== 1 || !StoreWebhookSecret::valid($webhookMaster)))) {
            throw new \InvalidArgumentException('invalid_webhook_config');
        }
        $this->posStep = new PosLinkStep($withKey);
        $this->webhookStep = $webhookUrl === null ? null : new StoreWebhookStep($withKey);
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * Configurazione dall'ambiente del cron. null = funzione spenta (chiave ponte o istanza assenti).
     * @throws InvalidConfigException configurazione incoerente (fail closed)
     */
    public static function fromEnv(Connection $db): ?self
    {
        $key = trim((string) getenv('BTCPAY_STORES_API_KEY'));
        $instance = ProvisioningOutbox::instance();
        if ($key === '' || $instance === null) return null;
        $origin = (string) getenv('BTCPAY_PROVISION_ORIGIN');
        // gli store v1 vivono su BTCPAY_URL: deve essere lo stesso server delle fatture dei terminali
        if (preg_match(self::KEY, $key) !== 1 || $origin === '' || !hash_equals($instance, hash('sha256', $origin))
            || rtrim(trim((string) getenv('BTCPAY_URL')), '/') !== $origin) throw new InvalidConfigException('invalid_bridge_config');
        $url = trim((string) getenv('BTCPAY_STORE_WEBHOOK_URL'));
        $master = trim((string) getenv('BTCPAY_STORE_WEBHOOK_KEY'));
        if (($url === '') !== ($master === '')) throw new InvalidConfigException('invalid_webhook_config');
        $ca = (string) getenv('BTCPAY_PROVISION_CA_FILE');
        $http = new ProvisioningHttpClient($origin, $ca === '' ? null : $ca);
        try {
            return new self($db, $http->withKey(...), $key, $instance, $url === '' ? null : $url, $master === '' ? null : $master);
        } catch (\InvalidArgumentException $error) {
            throw new InvalidConfigException($error->getMessage());
        }
    }

    /** @return array<string,int> conteggi della passata (busy=1: un'altra passata in corso, nulla fatto) */
    public function run(int $limit = 25): array
    {
        $report = ['busy' => 0, 'stores' => 0, 'stores_conflict' => 0, 'terminals' => 0, 'webhooks' => 0, 'created' => 0, 'adopted' => 0,
            'reconfigured' => 0, 'waiting' => 0, 'failed' => 0];
        $limit = max(1, min(100, $limit));
        if ((int) $this->db->createCommand('SELECT GET_LOCK(:l, 0)', [':l' => self::LOCK])->queryScalar() !== 1) return ['busy' => 1] + $report;
        try {
            $this->discover($report);
            if ($this->webhookStep !== null) $this->webhooks($report, $limit);
            $this->apps($report, $limit);
            $this->currencies($report, $limit);
        } finally {
            $this->db->createCommand('SELECT RELEASE_LOCK(:l)', [':l' => self::LOCK])->queryScalar();
        }
        return $report;
    }

    /** Negozi v1 non archiviati e terminali abilitati ancora senza riga ponte. Nessuna chiamata remota. */
    private function discover(array &$report): void
    {
        $now = $this->now();
        $stores = $this->db->createCommand('SELECT s.id, s.merchant_id, TRIM(ss.bps_storeid) AS remote FROM {{%stores}} s
            JOIN {{%storesettings}} ss ON ss.store_id=s.id
            LEFT JOIN {{%btcpay_bridge_stores}} b ON b.store_id=s.id AND b.instance=:i
            WHERE s.archived_at IS NULL AND ss.bps_storeid IS NOT NULL AND TRIM(ss.bps_storeid)<>\'\' AND b.id IS NULL ORDER BY s.id', [':i' => $this->instance])->queryAll();
        foreach ($stores as $store) {
            if (preg_match(StepOutcome::REMOTE_ID, (string) $store['remote']) !== 1) { ++$report['stores_conflict']; continue; }
            try {
                $this->db->createCommand()->insert('{{%btcpay_bridge_stores}}', ['instance' => $this->instance, 'merchant_id' => (int) $store['merchant_id'],
                    'store_id' => (int) $store['id'], 'remote_store_id' => $store['remote'], 'webhook_state' => $this->webhookStep === null ? 'disabled' : 'pending',
                    'created_at' => $now, 'updated_at' => $now])->execute();
                ++$report['stores'];
            } catch (IntegrityException $error) {
                // stesso store BTCPay su due negozi Napay: nessuno dei due lo usa finché l'admin non corregge
                if ((int) ($error->errorInfo[1] ?? 0) !== 1062) throw $error;
                ++$report['stores_conflict'];
                Log::security(sprintf('Ponte BTCPay: store del negozio %d già collegato a un altro negozio', (int) $store['id']));
            }
        }
        // webhook configurato dopo la scoperta: gli store "disabled" tornano da registrare
        if ($this->webhookStep !== null) {
            $this->db->createCommand("UPDATE {{%btcpay_bridge_stores}} SET webhook_state='pending', next_attempt_at=0, updated_at=:n WHERE instance=:i AND webhook_state='disabled'",
                [':i' => $this->instance, ':n' => $now])->execute();
        }
        $terminals = $this->db->createCommand('SELECT t.id, t.store_id, t.merchant_id, b.id AS bridge FROM {{%pos_terminals}} t
            JOIN {{%btcpay_bridge_stores}} b ON b.store_id=t.store_id AND b.merchant_id=t.merchant_id AND b.instance=:i
            JOIN {{%stores}} s ON s.id=t.store_id AND s.archived_at IS NULL
            LEFT JOIN {{%btcpay_bridge_pos_links}} p ON p.terminal_id=t.id AND p.instance=:i
            WHERE t.is_enabled=1 AND p.id IS NULL ORDER BY t.id', [':i' => $this->instance])->queryAll();
        foreach ($terminals as $terminal) {
            $this->db->createCommand()->insert('{{%btcpay_bridge_pos_links}}', ['instance' => $this->instance, 'bridge_store_id' => (int) $terminal['bridge'],
                'merchant_id' => (int) $terminal['merchant_id'], 'store_id' => (int) $terminal['store_id'], 'terminal_id' => (int) $terminal['id'],
                'marker' => bin2hex(random_bytes(16)), 'state' => 'pending', 'created_at' => $now, 'updated_at' => $now])->execute();
            ++$report['terminals'];
        }
    }

    /** Webhook Napay sugli store del ponte (GET per URL, poi PUT o POST: idempotente). */
    private function webhooks(array &$report, int $limit): void
    {
        $rows = $this->db->createCommand('SELECT b.* FROM {{%btcpay_bridge_stores}} b JOIN {{%stores}} s ON s.id=b.store_id AND s.archived_at IS NULL
            WHERE b.instance=:i AND b.webhook_state=\'pending\' AND b.next_attempt_at<=:n ORDER BY b.next_attempt_at, b.id LIMIT ' . $limit,
            [':i' => $this->instance, ':n' => $this->now()])->queryAll();
        foreach ($rows as $row) {
            $result = $this->webhookStep->ensure($row['remote_store_id'], (string) $this->webhookUrl,
                StoreWebhookSecret::derive((string) $this->webhookMaster, $this->instance, (int) $row['store_id']), $this->key);
            if ($result->kind === StepOutcome::DONE) {
                $this->update('{{%btcpay_bridge_stores}}', $row, ['webhook_state' => 'synced', 'last_error_code' => null, 'attempts' => 0, 'next_attempt_at' => 0]);
                ++$report['webhooks'];
            } elseif ($result->kind === StepOutcome::FAILED && $result->code !== 'request_not_sent') {
                $this->update('{{%btcpay_bridge_stores}}', $row, ['webhook_state' => 'failed', 'last_error_code' => self::code($result->code)]);
                ++$report['failed'];
            } else {
                $this->later('{{%btcpay_bridge_stores}}', $row, $result);
                ++$report['waiting'];
            }
        }
    }

    /** App POS dei terminali: creazione (un POST) o riconciliazione GET-only. */
    private function apps(array &$report, int $limit): void
    {
        $rows = $this->db->createCommand('SELECT p.*, b.remote_store_id, ss.defaultCurrency AS store_currency FROM {{%btcpay_bridge_pos_links}} p
            JOIN {{%btcpay_bridge_stores}} b ON b.id=p.bridge_store_id
            JOIN {{%stores}} s ON s.id=p.store_id AND s.archived_at IS NULL
            JOIN {{%pos_terminals}} t ON t.id=p.terminal_id AND t.is_enabled=1
            JOIN {{%storesettings}} ss ON ss.store_id=p.store_id
            WHERE p.instance=:i AND p.state IN (\'pending\',\'creating\',\'reconcile_required\') AND p.next_attempt_at<=:n
            ORDER BY p.next_attempt_at, p.id LIMIT ' . $limit, [':i' => $this->instance, ':n' => $this->now()])->queryAll();
        foreach ($rows as $row) {
            $currency = self::currency($row['store_currency']);
            $snapshot = ['marker' => $row['marker'], 'remote_app_id' => null, 'in_flight' => false, 'account_state' => 'synced',
                'remote_store_id' => $row['remote_store_id'], 'terminal_name' => ProvisioningLinkWorker::terminalName((int) $row['terminal_id']), 'currency' => $currency];
            if ($row['state'] === 'pending') {
                // checkpoint prima della rete: da qui un esito ignoto non porta mai a un secondo POST immediato
                $now = $this->now();
                if (!$this->update('{{%btcpay_bridge_pos_links}}', $row, ['state' => 'creating', 'creating_at' => $now])) continue;
                $row = ['state' => 'creating', 'creating_at' => $now, 'updated_at' => $now] + $row;
                $result = $this->posStep->create(['state' => 'pending'] + $snapshot, $this->key);
                $this->applyApp($report, $row, $result, $currency, true);
            } else {
                $result = $this->posStep->reconcile(['state' => 'reconcile_required'] + $snapshot, $this->key);
                $this->applyApp($report, $row, $result, $currency, false);
            }
        }
    }

    private function applyApp(array &$report, array $row, StepOutcome $result, ?string $currency, bool $posted): void
    {
        $table = '{{%btcpay_bridge_pos_links}}';
        $code = (string) $result->code;
        if ($result->kind === StepOutcome::DONE) {
            $this->update($table, $row, ['state' => 'synced', 'remote_app_id' => $result->data['remote_app_id'], 'currency' => $currency,
                'last_error_code' => null, 'attempts' => 0, 'next_attempt_at' => 0]);
            ++$report[$posted ? 'created' : 'adopted'];
            return;
        }
        if ($result->kind === StepOutcome::FAILED && $code !== 'request_not_sent') {
            $this->update($table, $row, ['state' => 'failed', 'last_error_code' => self::code($code)]);
            ++$report['failed'];
            Log::save('btcpay', 'bridge', sprintf('App POS del ponte per il terminale %d non creata: %s', (int) $row['terminal_id'], self::code($code)));
            return;
        }
        if ($posted && ($result->kind === StepOutcome::RETRY || $result->kind === StepOutcome::FAILED)) {
            // 429 o richiesta mai inviata: il POST non è avvenuto, si torna a pending (stesso marker)
            $this->later($table, $row, $result, ['state' => 'pending', 'creating_at' => null]);
        } elseif ($result->kind === StepOutcome::RECONCILE && in_array($code, ['app_ambiguous', 'app_lookup_malformed'], true)) {
            $this->update($table, $row, ['state' => 'failed', 'last_error_code' => $code]);
            ++$report['failed'];
            return;
        } elseif (!$posted && $code === 'app_not_found' && (int) $row['creating_at'] <= $this->now() - self::QUIET) {
            // nessuna app con il marker molto dopo l'ultimo POST: il POST non è arrivato, nuovo tentativo con lo stesso marker
            $this->update($table, $row, ['state' => 'pending', 'creating_at' => null, 'last_error_code' => 'app_not_found', 'next_attempt_at' => 0]);
        } else {
            $this->later($table, $row, $result, ['state' => 'reconcile_required']);
        }
        ++$report['waiting'];
    }

    /** App già collegate con valuta diversa da quella del negozio: configurazione completa (PUT idempotente). */
    private function currencies(array &$report, int $limit): void
    {
        $rows = $this->db->createCommand('SELECT p.*, ss.defaultCurrency AS store_currency FROM {{%btcpay_bridge_pos_links}} p
            JOIN {{%stores}} s ON s.id=p.store_id AND s.archived_at IS NULL
            JOIN {{%storesettings}} ss ON ss.store_id=p.store_id
            WHERE p.instance=:i AND p.state=\'synced\' AND NOT (ss.defaultCurrency <=> p.currency) ORDER BY p.id LIMIT ' . $limit,
            [':i' => $this->instance])->queryAll();
        foreach ($rows as $row) {
            $currency = self::currency($row['store_currency']);
            if ($currency === null) continue;
            $result = $this->posStep->configure($row['remote_app_id'], ProvisioningLinkWorker::terminalName((int) $row['terminal_id']), $currency, $this->key);
            if ($result->kind === StepOutcome::DONE) {
                $this->update('{{%btcpay_bridge_pos_links}}', $row, ['currency' => $currency]);
                ++$report['reconfigured'];
            } elseif ($result->code === 'app_missing') {
                // app rimossa da BTCPay (404, esito certo): nuova app con un nuovo marker alla prossima passata
                $this->update('{{%btcpay_bridge_pos_links}}', $row, ['state' => 'pending', 'remote_app_id' => null, 'currency' => null, 'creating_at' => null,
                    'marker' => bin2hex(random_bytes(16)), 'last_error_code' => 'app_missing', 'next_attempt_at' => 0]);
                ++$report['waiting'];
            } elseif ($result->kind === StepOutcome::FAILED && $result->code !== 'request_not_sent') {
                $this->update('{{%btcpay_bridge_pos_links}}', $row, ['last_error_code' => self::code($result->code)]);
                ++$report['failed'];
            }
        }
    }

    /** Nuovo tentativo con RetryPolicy (Retry-After del server se valido). */
    private function later(string $table, array $row, StepOutcome $result, array $changes = []): void
    {
        $attempts = min(RetryPolicy::MAX_ATTEMPT, (int) $row['attempts'] + 1);
        $decision = $this->retry->decide($result->retryAfter, $attempts, $this->now());
        $this->update($table, $row, $changes + ['attempts' => $attempts, 'next_attempt_at' => $decision['next_attempt_at'],
            'last_error_code' => self::code($result->code)]);
    }

    /** CAS su updated_at e stato letti: un'altra passata nel frattempo = nessuna modifica. */
    private function update(string $table, array $row, array $changes): bool
    {
        $condition = ['id' => (int) $row['id'], 'updated_at' => (int) $row['updated_at']];
        if (isset($row['state'])) $condition['state'] = $row['state'];
        $now = max($this->now(), (int) $row['updated_at']);
        return $this->db->createCommand()->update($table, $changes + ['updated_at' => $now], $condition)->execute() === 1;
    }

    private static function currency($value): ?string
    {
        return is_string($value) && preg_match('/^[A-Z]{3}$/D', $value) === 1 ? $value : null;
    }

    private static function code(?string $code): ?string
    {
        return is_string($code) && preg_match('/^[a-z0-9_]{1,64}$/D', $code) === 1 ? $code : null;
    }

    private function now(): int
    {
        $now = ($this->clock)();
        if (!is_int($now) || $now < 0) throw new \UnexpectedValueException('invalid_clock');
        return $now;
    }
}
