<?php

use yii\db\Migration;

/**
 * SIP-022 B1b: schema del provisioning BTCPay (account tecnici, credenziali cifrate, mapping, outbox).
 * Tabelle additive e vuote; unico indice su tabella esistente: uq-btcpay-terminals-scope su pos_terminals.
 * Nessun utente DB, GRANT, backfill o segreto: bootstrap e worker restano al master.
 */
class m261113_120000_create_btcpay_provisioning_schema extends Migration
{
    public const TERMINAL_INDEX = 'uq-btcpay-terminals-scope';
    private const TERMINAL_COLUMNS = ['id', 'store_id', 'merchant_id'];
    private const ASCII = 'CHARACTER SET ascii COLLATE ascii_bin';

    public function up()
    {
        if ($this->db->driverName !== 'mysql' || !preg_match('/^8\.4\./', $this->db->getServerVersion())) {
            throw new RuntimeException('BTCPay provisioning schema requires MySQL 8.4.');
        }
        foreach (['users' => ['id'], 'merchants' => ['id'], 'stores' => ['id', 'merchant_id'], 'pos_terminals' => self::TERMINAL_COLUMNS] as $parent => $columns) {
            $engine = $this->db->createCommand('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t', [':t' => $this->table($parent)])->queryScalar();
            $schema = $this->db->schema->getTableSchema($this->table($parent), true);
            if ($engine !== 'InnoDB' || $schema === null) $this->incompatible();
            foreach ($columns as $column) {
                $c = $schema->columns[$column] ?? null;
                if (!$c || $c->dbType !== 'int' || $c->unsigned || $c->allowNull) $this->incompatible();
            }
        }
        // Preflight di tutto prima di qualunque DDL: si riprende solo su definizioni identiche.
        // C1 ammette un indice equivalente con altro nome: la FK verso stores richiede solo l'unicità.
        if (!in_array(['unique' => true, 'columns' => ['id', 'merchant_id']], $this->indexes('stores', null, false), true)) $this->incompatible();
        $terminals = $this->indexes('pos_terminals', self::TERMINAL_INDEX);
        if ($terminals !== [] && $terminals !== ['unique' => true, 'columns' => self::TERMINAL_COLUMNS]) $this->incompatible();
        $definitions = $this->definitions();
        foreach ($definitions as $name => $definition) {
            if ($this->db->schema->getTableSchema($this->table($name), true) !== null) $this->verifyTable($name, $definition);
        }
        if ($terminals === []) $this->createIndex(self::TERMINAL_INDEX, $this->table('pos_terminals'), self::TERMINAL_COLUMNS, true);
        foreach ($definitions as $name => $definition) {
            if ($this->db->schema->getTableSchema($this->table($name), true) === null) $this->execute($this->createSql($name, $definition));
            $this->verifyTable($name, $definition);
        }
        $this->db->schema->refresh();
    }

    public function down()
    {
        echo "BTCPay provisioning rollback requires an approved backup restore.\n";
        return false;
    }

    /** Colonna => [SQL, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLLATION_NAME, EXTRA, GENERATION_EXPRESSION canonica]. */
    private function definitions(): array
    {
        $a = self::ASCII;
        $id = ['INT NOT NULL AUTO_INCREMENT', 'int', 'NO', null, null, 'auto_increment', ''];
        $int = ['INT NOT NULL', 'int', 'NO', null, null, '', ''];
        $instance = ["CHAR(64) $a NOT NULL", 'char(64)', 'NO', null, 'ascii_bin', '', ''];
        $marker = ["CHAR(32) $a NOT NULL", 'char(32)', 'NO', null, 'ascii_bin', '', ''];
        $remote = ["VARCHAR(64) $a NULL", 'varchar(64)', 'YES', null, 'ascii_bin', '', ''];
        $state = ["VARCHAR(24) $a NOT NULL DEFAULT 'pending'", 'varchar(24)', 'NO', 'pending', 'ascii_bin', '', ''];
        $error = ["VARCHAR(64) $a NULL", 'varchar(64)', 'YES', null, 'ascii_bin', '', ''];
        $version = ['INT NOT NULL DEFAULT 0', 'int', 'NO', '0', null, '', ''];
        $time = ['BIGINT NOT NULL', 'bigint', 'NO', null, null, '', ''];
        $nullTime = ['BIGINT NULL', 'bigint', 'YES', null, null, '', ''];
        $nonce = ['VARBINARY(24) NULL', 'varbinary(24)', 'YES', null, null, '', ''];
        $uuid = ["CHAR(36) $a NULL", 'char(36)', 'YES', null, 'ascii_bin', '', ''];
        $stamps = ['created_at' => $time, 'updated_at' => $time];
        $link = ['remote' => $remote, 'state' => $state, 'last_error_code' => $error, 'version' => $version] + $stamps;
        return [
            'btcpay_merchant_accounts' => [
                'columns' => [
                    'id' => $id, 'instance' => $instance, 'merchant_id' => $int, 'ref' => $marker,
                    'technical_email' => ["VARCHAR(255) $a NOT NULL", 'varchar(255)', 'NO', null, 'ascii_bin', '', ''],
                    'remote_user_id' => $remote, 'state' => $state,
                    'key_generations_used' => ['SMALLINT NOT NULL DEFAULT 0', 'smallint', 'NO', '0', null, '', ''],
                    'last_error_code' => $error, 'version' => $version,
                ] + $stamps,
                'indexes' => [
                    'PRIMARY' => [true, ['id']],
                    'uq-btcpay-accounts-merchant' => [true, ['instance', 'merchant_id']],
                    'uq-btcpay-accounts-ref' => [true, ['ref']],
                    'uq-btcpay-accounts-email' => [true, ['instance', 'technical_email']],
                    'uq-btcpay-accounts-remote' => [true, ['instance', 'remote_user_id']],
                    'uq-btcpay-accounts-scope' => [true, ['id', 'instance', 'merchant_id']],
                    'idx-btcpay-accounts-merchant' => [false, ['merchant_id']],
                ],
                'foreign' => ['fk-btcpay-accounts-merchant' => [['merchant_id'], 'merchants', ['id']]],
            ],
            'btcpay_merchant_credentials' => [
                'columns' => [
                    'account_id' => $int, 'instance' => $instance, 'merchant_id' => $int,
                    'kind' => ["VARCHAR(32) $a NOT NULL", 'varchar(32)', 'NO', null, 'ascii_bin', '', ''],
                    'generation' => $int,
                    'state' => ["VARCHAR(16) $a NOT NULL", 'varchar(16)', 'NO', null, 'ascii_bin', '', ''],
                    'current_marker' => ["TINYINT GENERATED ALWAYS AS (CASE WHEN state = 'current' THEN 1 END) STORED NULL", 'tinyint', 'YES', null, null, 'STORED GENERATED', "(case when (state = _utf8mb4'current') then 1 end)"],
                    'envelope_version' => ['TINYINT NULL', 'tinyint', 'YES', null, null, '', ''],
                    'kek_version' => ["VARCHAR(64) $a NULL", 'varchar(64)', 'YES', null, 'ascii_bin', '', ''],
                    'secret_nonce' => $nonce,
                    'ciphertext' => ['VARBINARY(16400) NULL', 'varbinary(16400)', 'YES', null, null, '', ''],
                    'dek_nonce' => $nonce,
                    'wrapped_dek' => ['VARBINARY(48) NULL', 'varbinary(48)', 'YES', null, null, '', ''],
                    'key_fingerprint' => ["CHAR(64) $a NULL", 'char(64)', 'YES', null, 'ascii_bin', '', ''],
                    'lock_version' => $version,
                ] + $stamps,
                'indexes' => [
                    'PRIMARY' => [true, ['account_id', 'kind', 'generation']],
                    'uq-btcpay-credentials-current' => [true, ['account_id', 'kind', 'current_marker']],
                    'idx-btcpay-credentials-account' => [false, ['account_id', 'instance', 'merchant_id']],
                ],
                'foreign' => ['fk-btcpay-credentials-account' => [['account_id', 'instance', 'merchant_id'], 'btcpay_merchant_accounts', ['id', 'instance', 'merchant_id']]],
            ],
            'btcpay_store_links' => [
                'columns' => ['id' => $id, 'instance' => $instance, 'account_id' => $int, 'merchant_id' => $int, 'store_id' => $int, 'marker' => $marker]
                    + self::rename($link, 'remote', 'remote_store_id'),
                'indexes' => [
                    'PRIMARY' => [true, ['id']],
                    'uq-btcpay-stores-store' => [true, ['instance', 'store_id']],
                    'uq-btcpay-stores-remote' => [true, ['instance', 'remote_store_id']],
                    'uq-btcpay-stores-marker' => [true, ['marker']],
                    'uq-btcpay-stores-scope' => [true, ['id', 'instance', 'merchant_id', 'store_id']],
                    'idx-btcpay-stores-account' => [false, ['account_id', 'instance', 'merchant_id']],
                    'idx-btcpay-stores-napay' => [false, ['store_id', 'merchant_id']],
                ],
                'foreign' => [
                    'fk-btcpay-stores-account' => [['account_id', 'instance', 'merchant_id'], 'btcpay_merchant_accounts', ['id', 'instance', 'merchant_id']],
                    'fk-btcpay-stores-store' => [['store_id', 'merchant_id'], 'stores', ['id', 'merchant_id']],
                ],
            ],
            'btcpay_pos_links' => [
                'columns' => ['id' => $id, 'instance' => $instance, 'store_link_id' => $int, 'merchant_id' => $int, 'store_id' => $int, 'terminal_id' => $int, 'marker' => $marker]
                    + self::rename($link, 'remote', 'remote_app_id'),
                'indexes' => [
                    'PRIMARY' => [true, ['id']],
                    'uq-btcpay-pos-terminal' => [true, ['instance', 'terminal_id']],
                    'uq-btcpay-pos-remote' => [true, ['instance', 'remote_app_id']],
                    'uq-btcpay-pos-marker' => [true, ['marker']],
                    'idx-btcpay-pos-store-link' => [false, ['store_link_id', 'instance', 'merchant_id', 'store_id']],
                    'idx-btcpay-pos-napay' => [false, ['terminal_id', 'store_id', 'merchant_id']],
                ],
                'foreign' => [
                    'fk-btcpay-pos-store-link' => [['store_link_id', 'instance', 'merchant_id', 'store_id'], 'btcpay_store_links', ['id', 'instance', 'merchant_id', 'store_id']],
                    'fk-btcpay-pos-terminal' => [['terminal_id', 'store_id', 'merchant_id'], 'pos_terminals', self::TERMINAL_COLUMNS],
                ],
            ],
            'btcpay_provisioning_jobs' => [
                'columns' => [
                    'id' => ['BIGINT NOT NULL AUTO_INCREMENT', 'bigint', 'NO', null, null, 'auto_increment', ''],
                    'kind' => ["VARCHAR(32) $a NOT NULL", 'varchar(32)', 'NO', null, 'ascii_bin', '', ''],
                    'instance' => $instance, 'merchant_id' => $int, 'entity_id' => $int,
                    'generation' => ['INT NOT NULL DEFAULT 1', 'int', 'NO', '1', null, '', ''],
                    'requested_by' => ['INT NULL', 'int', 'YES', null, null, '', ''],
                    'state' => $state,
                    'attempts' => ['SMALLINT NOT NULL DEFAULT 0', 'smallint', 'NO', '0', null, '', ''],
                    'next_attempt_at' => ['BIGINT NOT NULL DEFAULT 0', 'bigint', 'NO', '0', null, '', ''],
                    'lease_owner' => $uuid, 'lease_until' => $nullTime,
                    'in_flight_op' => ["VARCHAR(32) $a NULL", 'varchar(32)', 'YES', null, 'ascii_bin', '', ''],
                    'in_flight_started_at' => $nullTime, 'in_flight_owner' => $uuid,
                    'version' => $version, 'last_error_code' => $error,
                ] + $stamps,
                'indexes' => [
                    'PRIMARY' => [true, ['id']],
                    'uq-btcpay-jobs-entity' => [true, ['kind', 'instance', 'entity_id', 'generation']],
                    'idx-btcpay-jobs-due' => [false, ['state', 'next_attempt_at']],
                    'idx-btcpay-jobs-merchant' => [false, ['instance', 'merchant_id', 'created_at']],
                    'idx-btcpay-jobs-requester' => [false, ['requested_by']],
                ],
                'foreign' => ['fk-btcpay-jobs-requester' => [['requested_by'], 'users', ['id']]],
            ],
        ];
    }

    private static function rename(array $columns, string $from, string $to): array
    {
        $result = [];
        foreach ($columns as $name => $spec) $result[$name === $from ? $to : $name] = $spec;
        return $result;
    }

    /** CHECK come scritti nel DDL; la verifica confronta la resa di MySQL 8.4 (CANONICAL_CHECKS). */
    private function checks(string $table): array
    {
        $hex64 = "REGEXP_LIKE(instance, '^[0-9a-f]{64}$', 'c')";
        $error = "last_error_code IS NULL OR REGEXP_LIKE(last_error_code, '^[a-z0-9_]{1,64}$', 'c')";
        $time = 'created_at >= 0 AND updated_at >= created_at';
        $marker = "REGEXP_LIKE(marker, '^[0-9a-f]{32}$', 'c')";
        $link = "'pending','synced','reconcile_required','failed','suspended'";
        return match ($table) {
            'btcpay_merchant_accounts' => [
                'ck-btcpay-accounts-instance' => $hex64,
                'ck-btcpay-accounts-ref' => "REGEXP_LIKE(ref, '^[0-9a-f]{32}$', 'c')",
                'ck-btcpay-accounts-email' => "technical_email = CONCAT('m-', ref, '@btcpay-merchants.jamprojects.it')",
                'ck-btcpay-accounts-remote' => 'remote_user_id IS NULL OR CHAR_LENGTH(remote_user_id) BETWEEN 1 AND 64',
                'ck-btcpay-accounts-state' => "state IN ('pending','user_requested','user_created','key_saved','synced','suspended','reconcile_required','failed')",
                'ck-btcpay-accounts-synced' => "state <> 'synced' OR remote_user_id IS NOT NULL",
                'ck-btcpay-accounts-generations' => 'key_generations_used BETWEEN 0 AND 1000',
                'ck-btcpay-accounts-error' => $error,
                'ck-btcpay-accounts-version' => 'version >= 0',
                'ck-btcpay-accounts-time' => $time,
            ],
            'btcpay_merchant_credentials' => [
                'ck-btcpay-credentials-instance' => $hex64,
                'ck-btcpay-credentials-kind' => "kind IN ('onboarding_password','api_key')",
                'ck-btcpay-credentials-generation' => 'generation >= 1',
                'ck-btcpay-credentials-state' => "state IN ('pending','verified','current','retiring','revoked')",
                // Busta completa finché il segreto vive; revoked = busta azzerata, riga conservata. IS [NOT] NULL: mai esito NULL.
                'ck-btcpay-credentials-envelope' => "(state = 'revoked' AND envelope_version IS NULL AND kek_version IS NULL AND secret_nonce IS NULL AND ciphertext IS NULL AND dek_nonce IS NULL AND wrapped_dek IS NULL AND key_fingerprint IS NULL)"
                    . " OR (state <> 'revoked' AND envelope_version IS NOT NULL AND kek_version IS NOT NULL AND secret_nonce IS NOT NULL AND ciphertext IS NOT NULL AND dek_nonce IS NOT NULL AND wrapped_dek IS NOT NULL)",
                'ck-btcpay-credentials-format' => 'envelope_version IS NULL OR envelope_version = 1',
                'ck-btcpay-credentials-kek' => "kek_version IS NULL OR REGEXP_LIKE(kek_version, '^[A-Za-z0-9_-]{1,64}$', 'c')",
                // VARBINARY: lunghezze esatte, BINARY completerebbe input corti con zeri.
                'ck-btcpay-credentials-lengths' => '(secret_nonce IS NULL OR LENGTH(secret_nonce) = 24) AND (dek_nonce IS NULL OR LENGTH(dek_nonce) = 24)'
                    . ' AND (wrapped_dek IS NULL OR LENGTH(wrapped_dek) = 48) AND (ciphertext IS NULL OR LENGTH(ciphertext) BETWEEN 17 AND 16400)',
                'ck-btcpay-credentials-fingerprint' => "(key_fingerprint IS NULL OR REGEXP_LIKE(key_fingerprint, '^[0-9a-f]{64}$', 'c'))"
                    . " AND ((kind = 'api_key' AND state <> 'revoked') = (key_fingerprint IS NOT NULL))",
                'ck-btcpay-credentials-version' => 'lock_version >= 0',
                'ck-btcpay-credentials-time' => $time,
            ],
            'btcpay_store_links' => [
                'ck-btcpay-stores-instance' => $hex64,
                'ck-btcpay-stores-marker' => $marker,
                'ck-btcpay-stores-remote' => 'remote_store_id IS NULL OR CHAR_LENGTH(remote_store_id) BETWEEN 1 AND 64',
                'ck-btcpay-stores-state' => "state IN ($link)",
                'ck-btcpay-stores-synced' => "state <> 'synced' OR remote_store_id IS NOT NULL",
                'ck-btcpay-stores-error' => $error,
                'ck-btcpay-stores-version' => 'version >= 0',
                'ck-btcpay-stores-time' => $time,
            ],
            'btcpay_pos_links' => [
                'ck-btcpay-pos-instance' => $hex64,
                'ck-btcpay-pos-marker' => $marker,
                'ck-btcpay-pos-remote' => 'remote_app_id IS NULL OR CHAR_LENGTH(remote_app_id) BETWEEN 1 AND 64',
                'ck-btcpay-pos-state' => "state IN ($link)",
                'ck-btcpay-pos-synced' => "state <> 'synced' OR remote_app_id IS NOT NULL",
                'ck-btcpay-pos-error' => $error,
                'ck-btcpay-pos-version' => 'version >= 0',
                'ck-btcpay-pos-time' => $time,
            ],
            'btcpay_provisioning_jobs' => [
                'ck-btcpay-jobs-kind' => "kind IN ('merchant_account','store_link','pos_link','key_rotation','key_revoke')",
                'ck-btcpay-jobs-instance' => $hex64,
                'ck-btcpay-jobs-ids' => 'merchant_id >= 1 AND entity_id >= 1 AND generation >= 1',
                'ck-btcpay-jobs-state' => "state IN ('pending','running','in_flight','retry_wait','deferred','reconcile_required','failed','done')",
                'ck-btcpay-jobs-attempts' => 'attempts BETWEEN 0 AND 1000 AND next_attempt_at >= 0',
                'ck-btcpay-jobs-lease' => '(lease_owner IS NULL) = (lease_until IS NULL) AND (lease_owner IS NULL OR CHAR_LENGTH(lease_owner) = 36)',
                'ck-btcpay-jobs-in-flight' => '(in_flight_op IS NULL) = (in_flight_started_at IS NULL) AND (in_flight_op IS NULL) = (in_flight_owner IS NULL)'
                    . " AND (state <> 'in_flight' OR in_flight_op IS NOT NULL) AND (in_flight_owner IS NULL OR CHAR_LENGTH(in_flight_owner) = 36)",
                'ck-btcpay-jobs-operation' => "in_flight_op IS NULL OR in_flight_op IN ('create_user','create_api_key','create_store','create_pos_app','revoke_api_key')",
                'ck-btcpay-jobs-version' => 'version >= 0',
                'ck-btcpay-jobs-error' => $error,
                'ck-btcpay-jobs-time' => $time,
            ],
        };
    }

    private function createSql(string $name, array $definition): string
    {
        $parts = [];
        foreach ($definition['columns'] as $column => $spec) $parts[] = $this->db->quoteColumnName($column) . ' ' . $spec[0];
        foreach ($definition['indexes'] as $index => [$unique, $columns]) {
            $parts[] = ($index === 'PRIMARY' ? 'PRIMARY KEY' : ($unique ? 'UNIQUE KEY ' : 'KEY ') . $this->db->quoteColumnName($index)) . ' (' . $this->columnList($columns) . ')';
        }
        foreach ($definition['foreign'] as $constraint => [$columns, $parent, $referenced]) {
            $parts[] = 'CONSTRAINT ' . $this->db->quoteColumnName($constraint) . ' FOREIGN KEY (' . $this->columnList($columns) . ') REFERENCES '
                . $this->db->quoteTableName($this->table($parent)) . ' (' . $this->columnList($referenced) . ') ON DELETE RESTRICT ON UPDATE RESTRICT';
        }
        foreach ($this->checks($name) as $constraint => $expression) $parts[] = 'CONSTRAINT ' . $this->db->quoteColumnName($constraint) . ' CHECK (' . $expression . ')';
        return 'CREATE TABLE ' . $this->db->quoteTableName($this->table($name)) . ' (' . implode(', ', $parts) . ') ENGINE=InnoDB CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_bin';
    }

    private function verifyTable(string $name, array $definition): void
    {
        $table = $this->table($name);
        $row = $this->db->createCommand('SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t', [':t' => $table])->queryOne();
        if (!$row || $row['ENGINE'] !== 'InnoDB' || $row['TABLE_COLLATION'] !== 'utf8mb4_0900_bin') $this->incompatible();
        $columns = $this->db->createCommand('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t ORDER BY ORDINAL_POSITION', [':t' => $table])->queryAll();
        if (array_column($columns, 'COLUMN_NAME') !== array_keys($definition['columns'])) $this->incompatible();
        foreach ($columns as $column) {
            [, $type, $nullable, $default, $collation, $extra, $generation] = $definition['columns'][$column['COLUMN_NAME']];
            if ($column['COLUMN_TYPE'] !== $type || $column['IS_NULLABLE'] !== $nullable || $column['COLUMN_DEFAULT'] !== $default
                || $column['COLLATION_NAME'] !== $collation || $column['EXTRA'] !== $extra
                || self::clause(self::stored((string) $column['GENERATION_EXPRESSION'])) !== self::clause($generation)) $this->incompatible();
        }
        $indexes = $this->indexes($name);
        $wanted = array_map(fn($index) => ['unique' => $index[0], 'columns' => $index[1]], $definition['indexes']);
        ksort($indexes); ksort($wanted);
        if ($indexes !== $wanted) $this->incompatible();
        $database = $this->db->createCommand('SELECT DATABASE()')->queryScalar();
        $foreign = [];
        foreach ($this->db->createCommand('SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=:t ORDER BY k.ORDINAL_POSITION', [':t' => $table])->queryAll() as $fk) {
            if ($fk['REFERENCED_TABLE_SCHEMA'] !== $database || $fk['UPDATE_RULE'] !== 'RESTRICT' || $fk['DELETE_RULE'] !== 'RESTRICT') $this->incompatible();
            $foreign[$fk['CONSTRAINT_NAME']] ??= [[], $fk['REFERENCED_TABLE_NAME'], []];
            $foreign[$fk['CONSTRAINT_NAME']][0][] = $fk['COLUMN_NAME'];
            $foreign[$fk['CONSTRAINT_NAME']][2][] = $fk['REFERENCED_COLUMN_NAME'];
        }
        $wantedForeign = array_map(fn($fk) => [$fk[0], $this->table($fk[1]), $fk[2]], $definition['foreign']);
        ksort($foreign); ksort($wantedForeign);
        if ($foreign !== $wantedForeign) $this->incompatible();
        $checks = [];
        foreach ($this->db->createCommand("SELECT t.CONSTRAINT_NAME,c.CHECK_CLAUSE,t.ENFORCED FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME=:t AND t.CONSTRAINT_TYPE='CHECK'", [':t' => $table])->queryAll() as $check) {
            if ($check['ENFORCED'] !== 'YES') $this->incompatible();
            $checks[$check['CONSTRAINT_NAME']] = self::clause(self::stored($check['CHECK_CLAUSE']));
        }
        $wantedChecks = array_map([self::class, 'clause'], array_intersect_key(self::CANONICAL_CHECKS, $this->checks($name)));
        if (count($wantedChecks) !== count($this->checks($name))) $this->incompatible();
        ksort($checks); ksort($wantedChecks);
        if ($checks !== $wantedChecks) $this->incompatible();
    }

    /** Indici BTREE visibili e senza prefisso (altri: incompatibile, o ignorati se !$strict). Con $only: solo quell'indice ([] se assente). */
    private function indexes(string $name, ?string $only = null, bool $strict = true): array
    {
        $result = [];
        foreach ($this->db->createCommand('SHOW INDEX FROM ' . $this->db->quoteTableName($this->table($name)))->queryAll() as $row) {
            if ($only !== null && $row['Key_name'] !== $only) continue;
            if ($row['Sub_part'] !== null || $row['Index_type'] !== 'BTREE' || $row['Visible'] !== 'YES') {
                if ($strict) $this->incompatible();
                continue;
            }
            $result[$row['Key_name']]['unique'] = !(bool) $row['Non_unique'];
            $result[$row['Key_name']]['columns'][(int) $row['Seq_in_index']] = $row['Column_name'];
        }
        foreach ($result as &$index) { ksort($index['columns']); $index['columns'] = array_values($index['columns']); }
        unset($index);
        return $only === null ? $result : ($result[$only] ?? []);
    }

    /** Resa di MySQL 8.4.13 dei CHECK di checks(), dopo stored(); confronto con clause(). */
    private const CANONICAL_CHECKS = [
        'ck-btcpay-accounts-email' => "(technical_email = concat(_utf8mb4'm-',ref,_utf8mb4'@btcpay-merchants.jamprojects.it'))",
        'ck-btcpay-accounts-error' => "((last_error_code is null) or regexp_like(last_error_code,_utf8mb4'^[a-z0-9_]{1,64}\$',_utf8mb4'c'))",
        'ck-btcpay-accounts-generations' => "(key_generations_used between 0 and 1000)",
        'ck-btcpay-accounts-instance' => "regexp_like(instance,_utf8mb4'^[0-9a-f]{64}\$',_utf8mb4'c')",
        'ck-btcpay-accounts-ref' => "regexp_like(ref,_utf8mb4'^[0-9a-f]{32}\$',_utf8mb4'c')",
        'ck-btcpay-accounts-remote' => "((remote_user_id is null) or (char_length(remote_user_id) between 1 and 64))",
        'ck-btcpay-accounts-state' => "(state in (_utf8mb4'pending',_utf8mb4'user_requested',_utf8mb4'user_created',_utf8mb4'key_saved',_utf8mb4'synced',_utf8mb4'suspended',_utf8mb4'reconcile_required',_utf8mb4'failed'))",
        'ck-btcpay-accounts-synced' => "((state <> _utf8mb4'synced') or (remote_user_id is not null))",
        'ck-btcpay-accounts-time' => "((created_at >= 0) and (updated_at >= created_at))",
        'ck-btcpay-accounts-version' => "(version >= 0)",
        'ck-btcpay-credentials-envelope' => "(((state = _utf8mb4'revoked') and (envelope_version is null) and (kek_version is null) and (secret_nonce is null) and (ciphertext is null) and (dek_nonce is null) and (wrapped_dek is null) and (key_fingerprint is null)) or ((state <> _utf8mb4'revoked') and (envelope_version is not null) and (kek_version is not null) and (secret_nonce is not null) and (ciphertext is not null) and (dek_nonce is not null) and (wrapped_dek is not null)))",
        'ck-btcpay-credentials-fingerprint' => "(((key_fingerprint is null) or regexp_like(key_fingerprint,_utf8mb4'^[0-9a-f]{64}\$',_utf8mb4'c')) and (((kind = _utf8mb4'api_key') and (state <> _utf8mb4'revoked')) = (key_fingerprint is not null)))",
        'ck-btcpay-credentials-format' => "((envelope_version is null) or (envelope_version = 1))",
        'ck-btcpay-credentials-generation' => "(generation >= 1)",
        'ck-btcpay-credentials-instance' => "regexp_like(instance,_utf8mb4'^[0-9a-f]{64}\$',_utf8mb4'c')",
        'ck-btcpay-credentials-kek' => "((kek_version is null) or regexp_like(kek_version,_utf8mb4'^[A-Za-z0-9_-]{1,64}\$',_utf8mb4'c'))",
        'ck-btcpay-credentials-kind' => "(kind in (_utf8mb4'onboarding_password',_utf8mb4'api_key'))",
        'ck-btcpay-credentials-lengths' => "(((secret_nonce is null) or (length(secret_nonce) = 24)) and ((dek_nonce is null) or (length(dek_nonce) = 24)) and ((wrapped_dek is null) or (length(wrapped_dek) = 48)) and ((ciphertext is null) or (length(ciphertext) between 17 and 16400)))",
        'ck-btcpay-credentials-state' => "(state in (_utf8mb4'pending',_utf8mb4'verified',_utf8mb4'current',_utf8mb4'retiring',_utf8mb4'revoked'))",
        'ck-btcpay-credentials-time' => "((created_at >= 0) and (updated_at >= created_at))",
        'ck-btcpay-credentials-version' => "(lock_version >= 0)",
        'ck-btcpay-jobs-attempts' => "((attempts between 0 and 1000) and (next_attempt_at >= 0))",
        'ck-btcpay-jobs-error' => "((last_error_code is null) or regexp_like(last_error_code,_utf8mb4'^[a-z0-9_]{1,64}\$',_utf8mb4'c'))",
        'ck-btcpay-jobs-ids' => "((merchant_id >= 1) and (entity_id >= 1) and (generation >= 1))",
        'ck-btcpay-jobs-in-flight' => "(((in_flight_op is null) = (in_flight_started_at is null)) and ((in_flight_op is null) = (in_flight_owner is null)) and ((state <> _utf8mb4'in_flight') or (in_flight_op is not null)) and ((in_flight_owner is null) or (char_length(in_flight_owner) = 36)))",
        'ck-btcpay-jobs-instance' => "regexp_like(instance,_utf8mb4'^[0-9a-f]{64}\$',_utf8mb4'c')",
        'ck-btcpay-jobs-kind' => "(kind in (_utf8mb4'merchant_account',_utf8mb4'store_link',_utf8mb4'pos_link',_utf8mb4'key_rotation',_utf8mb4'key_revoke'))",
        'ck-btcpay-jobs-lease' => "(((lease_owner is null) = (lease_until is null)) and ((lease_owner is null) or (char_length(lease_owner) = 36)))",
        'ck-btcpay-jobs-operation' => "((in_flight_op is null) or (in_flight_op in (_utf8mb4'create_user',_utf8mb4'create_api_key',_utf8mb4'create_store',_utf8mb4'create_pos_app',_utf8mb4'revoke_api_key')))",
        'ck-btcpay-jobs-state' => "(state in (_utf8mb4'pending',_utf8mb4'running',_utf8mb4'in_flight',_utf8mb4'retry_wait',_utf8mb4'deferred',_utf8mb4'reconcile_required',_utf8mb4'failed',_utf8mb4'done'))",
        'ck-btcpay-jobs-time' => "((created_at >= 0) and (updated_at >= created_at))",
        'ck-btcpay-jobs-version' => "(version >= 0)",
        'ck-btcpay-pos-error' => "((last_error_code is null) or regexp_like(last_error_code,_utf8mb4'^[a-z0-9_]{1,64}\$',_utf8mb4'c'))",
        'ck-btcpay-pos-instance' => "regexp_like(instance,_utf8mb4'^[0-9a-f]{64}\$',_utf8mb4'c')",
        'ck-btcpay-pos-marker' => "regexp_like(marker,_utf8mb4'^[0-9a-f]{32}\$',_utf8mb4'c')",
        'ck-btcpay-pos-remote' => "((remote_app_id is null) or (char_length(remote_app_id) between 1 and 64))",
        'ck-btcpay-pos-state' => "(state in (_utf8mb4'pending',_utf8mb4'synced',_utf8mb4'reconcile_required',_utf8mb4'failed',_utf8mb4'suspended'))",
        'ck-btcpay-pos-synced' => "((state <> _utf8mb4'synced') or (remote_app_id is not null))",
        'ck-btcpay-pos-time' => "((created_at >= 0) and (updated_at >= created_at))",
        'ck-btcpay-pos-version' => "(version >= 0)",
        'ck-btcpay-stores-error' => "((last_error_code is null) or regexp_like(last_error_code,_utf8mb4'^[a-z0-9_]{1,64}\$',_utf8mb4'c'))",
        'ck-btcpay-stores-instance' => "regexp_like(instance,_utf8mb4'^[0-9a-f]{64}\$',_utf8mb4'c')",
        'ck-btcpay-stores-marker' => "regexp_like(marker,_utf8mb4'^[0-9a-f]{32}\$',_utf8mb4'c')",
        'ck-btcpay-stores-remote' => "((remote_store_id is null) or (char_length(remote_store_id) between 1 and 64))",
        'ck-btcpay-stores-state' => "(state in (_utf8mb4'pending',_utf8mb4'synced',_utf8mb4'reconcile_required',_utf8mb4'failed',_utf8mb4'suspended'))",
        'ck-btcpay-stores-synced' => "((state <> _utf8mb4'synced') or (remote_store_id is not null))",
        'ck-btcpay-stores-time' => "((created_at >= 0) and (updated_at >= created_at))",
        'ck-btcpay-stores-version' => "(version >= 0)",
    ];

    /** information_schema riporta il testo dell'espressione con un livello di escape in più (\' e \\): lo toglie. */
    private static function stored(string $sql): string
    {
        return preg_replace_callback('/\\\\([\\\\\'])/', fn($m) => $m[1], $sql);
    }

    /**
     * Normalizza solo fuori dai letterali: spazi, backtick e introducer _utf8mb4/_ascii (dopo un ALTER MySQL
     * riscrive l'uno nell'altro). I letterali restano byte per byte, escape compresi; letterale aperto = incompatibile.
     */
    private static function clause(string $sql): string
    {
        $out = '';
        for ($i = 0, $n = strlen($sql); $i < $n; ++$i) {
            $c = $sql[$i];
            if ($c === "'") {
                for ($j = $i + 1; $j < $n; ++$j) {
                    if ($sql[$j] === '\\') { ++$j; continue; }
                    if ($sql[$j] === "'") {
                        if (($sql[$j + 1] ?? '') !== "'") break;
                        ++$j;
                    }
                }
                if ($j >= $n) throw new RuntimeException('BTCPay provisioning schema is incompatible; no automatic repair permitted.');
                $out .= substr($sql, $i, $j - $i + 1);
                $i = $j;
            } elseif (preg_match("/\\G_(?:utf8mb4|ascii)(?=')/", $sql, $m, 0, $i) === 1) {
                $i += strlen($m[0]) - 1;
            } elseif ($c !== '`' && !ctype_space($c)) {
                $out .= $c;
            }
        }
        return $out;
    }

    private function table(string $name): string { return $this->db->tablePrefix . $name; }
    private function columnList(array $columns): string { return implode(', ', array_map([$this->db, 'quoteColumnName'], $columns)); }
    private function incompatible(): void { throw new RuntimeException('BTCPay provisioning schema is incompatible; no automatic repair permitted.'); }
}
