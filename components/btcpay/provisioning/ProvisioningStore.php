<?php
namespace app\components\btcpay\provisioning;

use yii\db\Connection;
use yii\db\IntegrityException;

/**
 * SIP-022 B1 W1: accesso del worker ai mapping BTCPay (account tecnico, credenziali cifrate, store e app POS).
 * Nessuna chiamata remota, nessun DELETE. Ogni modifica è un CAS sulla versione della riga; le operazioni su più
 * righe usano transazioni annidabili (savepoint), così W3 potrà eseguirle dentro la transazione del lavoro con la
 * sua guardia di lease. Credenziali solo come buste CredentialEnvelope legate a account/merchant/istanza/tipo/generazione.
 */
final class ProvisioningStore
{
    public const ACCOUNT_STATES = ['pending', 'user_requested', 'user_created', 'key_saved', 'synced', 'suspended', 'reconcile_required', 'failed'];
    public const LINK_STATES = ['pending', 'synced', 'reconcile_required', 'failed', 'suspended'];
    private const EMAIL_DOMAIN = '@btcpay-merchants.jamprojects.it';
    private const ACCOUNTS = '{{%btcpay_merchant_accounts}}';
    private const CREDENTIALS = '{{%btcpay_merchant_credentials}}';
    private const STORES = '{{%btcpay_store_links}}';
    private const APPS = '{{%btcpay_pos_links}}';
    private const INT_MAX = 2147483647;

    /** @param array<string,string> $keyring versione KEK => chiave; $kekVersion cifra le nuove buste */
    public function __construct(#[\SensitiveParameter] private readonly Connection $db, private readonly string $instance,
        #[\SensitiveParameter] private readonly array $keyring, private readonly string $kekVersion)
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $instance) !== 1) throw new \InvalidArgumentException('invalid_instance');
        if (!isset($keyring[$kekVersion]) || !is_string($keyring[$kekVersion])) throw new \InvalidArgumentException('unknown_kek_version');
    }

    // --- account tecnico

    /** Account dell'istanza per il merchant, creato `pending` se manca; gare risolte dal vincolo UNIQUE. */
    public function ensureAccount(int $merchantId): array
    {
        self::id($merchantId);
        $existing = $this->account($merchantId);
        if ($existing !== null) return $existing;
        $ref = bin2hex(random_bytes(16));
        $now = time();
        try {
            $this->db->createCommand()->insert(self::ACCOUNTS, ['instance' => $this->instance, 'merchant_id' => $merchantId, 'ref' => $ref,
                'technical_email' => 'm-' . $ref . self::EMAIL_DOMAIN, 'created_at' => $now, 'updated_at' => $now])->execute();
        } catch (IntegrityException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) throw $e;
        }
        return $this->account($merchantId) ?? throw new \UnexpectedValueException('account_unavailable');
    }

    public function account(int $merchantId): ?array
    {
        $row = $this->db->createCommand('SELECT * FROM ' . self::ACCOUNTS . ' WHERE instance=:i AND merchant_id=:m', [':i' => $this->instance, ':m' => $merchantId])->queryOne();
        return $row === false ? null : self::ints($row, ['id', 'merchant_id', 'key_generations_used', 'version', 'created_at', 'updated_at']);
    }

    /** CAS su versione: restituisce la riga aggiornata, o null se nel frattempo è cambiata. */
    public function updateAccount(array $account, array $changes): ?array
    {
        $this->own($account);
        $allowed = ['state', 'remote_user_id', 'key_generations_used', 'last_error_code'];
        if ($changes === [] || array_diff(array_keys($changes), $allowed) !== []) throw new \InvalidArgumentException('invalid_change');
        if (array_key_exists('state', $changes) && !in_array($changes['state'], self::ACCOUNT_STATES, true)) throw new \InvalidArgumentException('invalid_state');
        if (array_key_exists('remote_user_id', $changes)) self::remoteId($changes['remote_user_id']);
        if (array_key_exists('key_generations_used', $changes) && (!is_int($changes['key_generations_used']) || $changes['key_generations_used'] < 0 || $changes['key_generations_used'] > 1000)) {
            throw new \InvalidArgumentException('invalid_generations');
        }
        if (array_key_exists('last_error_code', $changes)) self::errorCode($changes['last_error_code']);
        return $this->cas(self::ACCOUNTS, $account, $changes) ? $this->account($account['merchant_id']) : null;
    }

    // --- credenziali (busta cifrata, mai in chiaro nel DB)

    /** Password tecnica (decisione B): salvata una volta come `current`; un nuovo tentativo non la riscrive mai. */
    public function storePassword(array $account, #[\SensitiveParameter] string $password): bool
    {
        $this->own($account);
        return $this->insertCredential($account, 'onboarding_password', 1, 'current', $password);
    }

    public function password(array $account): ?string
    {
        $this->own($account);
        $row = $this->credential($account, 'onboarding_password', 1);
        return $row === null || $row['state'] !== 'current' ? null : $this->open($account, $row);
    }

    /** Nuova chiave API della generazione indicata: `pending`, poi `verified` (verifyKey) e infine `current` (promoteKey). */
    public function storeKey(array $account, int $generation, #[\SensitiveParameter] string $apiKey): bool
    {
        $this->own($account);
        self::id($generation);
        return $this->insertCredential($account, 'api_key', $generation, 'pending', $apiKey);
    }

    /**
     * Chiave di una generazione non revocata (ripresa dopo un crash tra salvataggio e verifica remota).
     * @return array{generation:int, state:string, lock_version:int, key:string}|null
     */
    public function key(array $account, int $generation): ?array
    {
        $this->own($account);
        self::id($generation);
        $row = $this->credential($account, 'api_key', $generation);
        if ($row === null || $row['state'] === 'revoked') return null;
        $key = $this->open($account, $row);
        if (!hash_equals((string) $row['key_fingerprint'], hash('sha256', $key))) throw new \UnexpectedValueException('fingerprint_mismatch');
        return ['generation' => $generation, 'state' => (string) $row['state'], 'lock_version' => (int) $row['lock_version'], 'key' => $key];
    }

    /** pending -> verified dopo la verifica remota (B1 §6 R2/R3), CAS su stato e lock_version letti. */
    public function verifyKey(array $account, int $generation, int $lockVersion): bool
    {
        $this->own($account);
        self::id($generation);
        return $this->db->createCommand()->update(self::CREDENTIALS, ['state' => 'verified', 'lock_version' => $lockVersion + 1, 'updated_at' => time()],
            ['account_id' => $account['id'], 'instance' => $this->instance, 'kind' => 'api_key', 'generation' => $generation, 'state' => 'pending', 'lock_version' => $lockVersion])->execute() === 1;
    }

    /** Solo una generazione `verified` diventa l'unica `current`; la precedente passa a `retiring` (revocata dopo, R4). */
    public function promoteKey(array $account, int $generation): bool
    {
        $this->own($account);
        self::id($generation);
        return (bool) $this->db->transaction(function () use ($account, $generation) {
            $rows = $this->db->createCommand('SELECT generation, state, lock_version FROM ' . self::CREDENTIALS
                . " WHERE account_id=:a AND kind='api_key' AND state IN ('verified','current') FOR UPDATE", [':a' => $account['id']])->queryAll();
            $target = current(array_filter($rows, fn($r) => (int) $r['generation'] === $generation && $r['state'] === 'verified'));
            if ($target === false) return false;
            foreach ($rows as $row) {
                if ($row['state'] === 'current') $this->credentialCas($account, (int) $row['generation'], (int) $row['lock_version'], ['state' => 'retiring']);
            }
            $this->credentialCas($account, $generation, (int) $target['lock_version'], ['state' => 'current']);
            return true;
        });
    }

    /** @return array{generation:int, key:string}|null chiave corrente, con impronta verificata */
    public function currentKey(array $account): ?array
    {
        $this->own($account);
        $row = $this->db->createCommand('SELECT * FROM ' . self::CREDENTIALS . " WHERE account_id=:a AND kind='api_key' AND state='current'", [':a' => $account['id']])->queryOne();
        if ($row === false) return null;
        $key = $this->open($account, $row);
        if (!hash_equals((string) $row['key_fingerprint'], hash('sha256', $key))) throw new \UnexpectedValueException('fingerprint_mismatch');
        return ['generation' => (int) $row['generation'], 'key' => $key];
    }

    /**
     * Busta azzerata, riga conservata per l'audit. CAS su stato e lock_version letti: una revoca su dati vecchi non
     * azzera nulla. La chiave `current` non si revoca: prima se ne promuove un'altra.
     */
    public function revokeKey(array $account, int $generation, int $lockVersion): bool
    {
        $this->own($account);
        self::id($generation);
        return $this->db->createCommand()->update(self::CREDENTIALS, ['state' => 'revoked', 'envelope_version' => null, 'kek_version' => null, 'secret_nonce' => null,
            'ciphertext' => null, 'dek_nonce' => null, 'wrapped_dek' => null, 'key_fingerprint' => null, 'lock_version' => $lockVersion + 1, 'updated_at' => time()],
            ['account_id' => $account['id'], 'instance' => $this->instance, 'kind' => 'api_key', 'generation' => $generation,
                'state' => ['pending', 'verified', 'retiring'], 'lock_version' => $lockVersion])->execute() === 1;
    }

    // --- store e app POS

    /** Mapping del negozio (marker casuale), creato `pending`; il vincolo composito impone negozio del merchant. */
    public function ensureStoreLink(array $account, int $storeId): array
    {
        $this->own($account);
        self::id($storeId);
        return $this->ensureLink(self::STORES, ['account_id' => $account['id'], 'merchant_id' => $account['merchant_id'], 'store_id' => $storeId],
            fn() => $this->storeLink($storeId), $account['merchant_id']);
    }

    public function storeLink(int $storeId): ?array
    {
        $row = $this->db->createCommand('SELECT * FROM ' . self::STORES . ' WHERE instance=:i AND store_id=:s', [':i' => $this->instance, ':s' => $storeId])->queryOne();
        return $row === false ? null : self::ints($row, ['id', 'account_id', 'merchant_id', 'store_id', 'version', 'created_at', 'updated_at']);
    }

    public function updateStoreLink(array $link, array $changes): ?array
    {
        return $this->updateLink(self::STORES, $link, $changes, 'remote_store_id') ? $this->storeLink($link['store_id']) : null;
    }

    public function ensurePosLink(array $storeLink, int $terminalId): array
    {
        if (($storeLink['instance'] ?? null) !== $this->instance) throw new \InvalidArgumentException('foreign_instance');
        self::id($terminalId);
        return $this->ensureLink(self::APPS, ['store_link_id' => $storeLink['id'], 'merchant_id' => $storeLink['merchant_id'], 'store_id' => $storeLink['store_id'], 'terminal_id' => $terminalId],
            fn() => $this->posLink($terminalId), $storeLink['merchant_id']);
    }

    public function posLink(int $terminalId): ?array
    {
        $row = $this->db->createCommand('SELECT * FROM ' . self::APPS . ' WHERE instance=:i AND terminal_id=:t', [':i' => $this->instance, ':t' => $terminalId])->queryOne();
        return $row === false ? null : self::ints($row, ['id', 'store_link_id', 'merchant_id', 'store_id', 'terminal_id', 'version', 'created_at', 'updated_at']);
    }

    public function updatePosLink(array $link, array $changes): ?array
    {
        return $this->updateLink(self::APPS, $link, $changes, 'remote_app_id') ? $this->posLink($link['terminal_id']) : null;
    }

    // --- interni

    private function ensureLink(string $table, array $columns, callable $reload, int $merchantId): array
    {
        $existing = $reload();
        if ($existing === null) {
            $now = time();
            try {
                $this->db->createCommand()->insert($table, ['instance' => $this->instance] + $columns + ['marker' => bin2hex(random_bytes(16)), 'created_at' => $now, 'updated_at' => $now])->execute();
            } catch (IntegrityException $e) {
                $code = (int) ($e->errorInfo[1] ?? 0);
                // FK composita: negozio o terminale di un altro merchant o negozio.
                if ($code === 1452) throw new \InvalidArgumentException('scope_mismatch');
                if ($code !== 1062) throw $e;
            }
            $existing = $reload() ?? throw new \UnexpectedValueException('link_unavailable');
        }
        if ($existing['merchant_id'] !== $merchantId) throw new \InvalidArgumentException('scope_mismatch');
        return $existing;
    }

    private function updateLink(string $table, array $link, array $changes, string $remote): bool
    {
        if (($link['instance'] ?? null) !== $this->instance) throw new \InvalidArgumentException('foreign_instance');
        if ($changes === [] || array_diff(array_keys($changes), ['state', $remote, 'last_error_code']) !== []) throw new \InvalidArgumentException('invalid_change');
        if (array_key_exists('state', $changes) && !in_array($changes['state'], self::LINK_STATES, true)) throw new \InvalidArgumentException('invalid_state');
        if (array_key_exists($remote, $changes)) self::remoteId($changes[$remote]);
        if (array_key_exists('last_error_code', $changes)) self::errorCode($changes['last_error_code']);
        return $this->cas($table, $link, $changes);
    }

    private function cas(string $table, array $row, array $changes): bool
    {
        if (!isset($row['id'], $row['version']) || !is_int($row['version'])) throw new \InvalidArgumentException('invalid_row');
        return $this->db->createCommand()->update($table, $changes + ['version' => $row['version'] + 1, 'updated_at' => time()],
            ['id' => $row['id'], 'instance' => $this->instance, 'version' => $row['version']])->execute() === 1;
    }

    private function credentialCas(array $account, int $generation, int $lockVersion, array $changes): void
    {
        $changed = $this->db->createCommand()->update(self::CREDENTIALS, $changes + ['lock_version' => $lockVersion + 1, 'updated_at' => time()],
            ['account_id' => $account['id'], 'instance' => $this->instance, 'kind' => 'api_key', 'generation' => $generation, 'lock_version' => $lockVersion])->execute();
        if ($changed !== 1) throw new \UnexpectedValueException('credential_changed');
    }

    private function insertCredential(array $account, string $kind, int $generation, string $state, #[\SensitiveParameter] string $secret): bool
    {
        $envelope = CredentialEnvelope::encrypt($secret, $this->context($account, $kind, $generation), $this->kekVersion, $this->keyring[$this->kekVersion]);
        $now = time();
        try {
            $this->db->createCommand()->insert(self::CREDENTIALS, ['account_id' => $account['id'], 'instance' => $this->instance, 'merchant_id' => $account['merchant_id'],
                'kind' => $kind, 'generation' => $generation, 'state' => $state, 'envelope_version' => $envelope['version'], 'kek_version' => $envelope['kek_version'],
                'secret_nonce' => base64_decode($envelope['secret_nonce']), 'ciphertext' => base64_decode($envelope['ciphertext']),
                'dek_nonce' => base64_decode($envelope['dek_nonce']), 'wrapped_dek' => base64_decode($envelope['wrapped_dek']),
                'key_fingerprint' => $kind === 'api_key' ? hash('sha256', $secret) : null, 'created_at' => $now, 'updated_at' => $now])->execute();
            return true;
        } catch (IntegrityException $e) {
            // Stessa generazione già presente: mai sovrascritta (la password tecnica in particolare resta quella salvata).
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) return false;
            throw $e;
        }
    }

    private function credential(array $account, string $kind, int $generation): ?array
    {
        $row = $this->db->createCommand('SELECT * FROM ' . self::CREDENTIALS . ' WHERE account_id=:a AND instance=:i AND kind=:k AND generation=:g',
            [':a' => $account['id'], ':i' => $this->instance, ':k' => $kind, ':g' => $generation])->queryOne();
        return $row === false ? null : $row;
    }

    private function open(array $account, array $row): string
    {
        $envelope = ['version' => (int) $row['envelope_version'], 'kek_version' => (string) $row['kek_version']];
        foreach (['secret_nonce', 'ciphertext', 'dek_nonce', 'wrapped_dek'] as $field) $envelope[$field] = base64_encode((string) $row[$field]);
        return CredentialEnvelope::decrypt($envelope, $this->context($account, (string) $row['kind'], (int) $row['generation']), $this->keyring);
    }

    private function context(array $account, string $kind, int $generation): array
    {
        return ['account_id' => $account['id'], 'merchant_id' => $account['merchant_id'], 'instance' => $this->instance, 'kind' => $kind, 'generation' => $generation];
    }

    /** L'account deve venire da questo store: stessa istanza, identificativi interi. */
    private function own(array $account): void
    {
        if (($account['instance'] ?? null) !== $this->instance || !is_int($account['id'] ?? null) || !is_int($account['merchant_id'] ?? null)) {
            throw new \InvalidArgumentException('foreign_instance');
        }
    }

    private static function id(int $value): void
    {
        if ($value < 1 || $value > self::INT_MAX) throw new \InvalidArgumentException('invalid_id');
    }

    private static function remoteId($value): void
    {
        if ($value !== null && (!is_string($value) || preg_match('/^[\x21-\x7e]{1,64}$/D', $value) !== 1)) throw new \InvalidArgumentException('invalid_remote_id');
    }

    private static function errorCode($value): void
    {
        if ($value !== null && (!is_string($value) || preg_match('/^[a-z0-9_]{1,64}$/D', $value) !== 1)) throw new \InvalidArgumentException('invalid_error_code');
    }

    private static function ints(array $row, array $fields): array
    {
        foreach ($fields as $field) $row[$field] = (int) $row[$field];
        return $row;
    }
}
