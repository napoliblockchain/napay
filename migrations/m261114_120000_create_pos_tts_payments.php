<?php

use yii\db\Migration;

/**
 * SIP-021 fase 3 (PAGAMENTO-TTS-CONTRATTO): indirizzo wallet TTS del merchant e richieste di pagamento POS.
 * Tabelle additive e vuote. Una transazione paga al massimo una richiesta (tx_hash UNIQUE); riferimento
 * casuale UNIQUE; stato finale coerente con le colonne di esito (CHECK). Nessuna modifica a tabelle esistenti.
 */
class m261114_120000_create_pos_tts_payments extends Migration
{
    public function up()
    {
        if ($this->db->driverName !== 'mysql' || !preg_match('/^8\.4\./', $this->db->getServerVersion())) {
            throw new RuntimeException('POS TTS payments require MySQL 8.4.');
        }
        foreach (['users', 'merchants', 'stores', 'pos_terminals', 'pos_operator_sessions', 'pos_terminal_openings'] as $parent) {
            $engine = $this->db->createCommand('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t', [':t' => $this->table($parent)])->queryScalar();
            $id = $this->db->schema->getTableSchema($this->table($parent), true)->columns['id'] ?? null;
            if ($engine !== 'InnoDB' || !$id || $id->dbType !== 'int' || $id->unsigned || $id->allowNull) $this->incompatible();
        }
        // ripresa: solo una definizione identica viene accettata; nessuna riparazione automatica
        foreach (array_keys(self::tables()) as $table) {
            if ($this->db->schema->getTableSchema($this->table($table), true) === null) $this->execute($this->createSql($table));
            $this->verify($table);
        }
        $this->db->schema->refresh();
    }

    public function down()
    {
        echo "POS TTS payments rollback requires an approved backup restore.\n";
        return false;
    }

    /**
     * Tabella => columns (Colonna => [SQL, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLLATION_NAME, EXTRA]),
     * indexes, foreign keys, checks.
     */
    private static function tables(): array
    {
        $ascii = 'CHARACTER SET ascii COLLATE ascii_bin';
        $int = ['INT NOT NULL', 'int', 'NO', null, null, ''];
        $time = ['BIGINT NOT NULL', 'bigint', 'NO', null, null, ''];
        $optionalTime = ['BIGINT NULL', 'bigint', 'YES', null, null, ''];
        $address = ["CHAR(42) $ascii NOT NULL", 'char(42)', 'NO', null, 'ascii_bin', ''];
        $digest = ["CHAR(64) $ascii NOT NULL", 'char(64)', 'NO', null, 'ascii_bin', ''];
        $uuid = "'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'";
        return [
            'merchant_tts_wallets' => [
                'columns' => ['merchant_id' => $int, 'address' => $address, 'updated_by' => $int, 'updated_at' => $time],
                'indexes' => [
                    'PRIMARY' => ['unique' => true, 'columns' => ['merchant_id']],
                    'idx-merchant-tts-wallets-updater' => ['unique' => false, 'columns' => ['updated_by']],
                ],
                'foreignKeys' => [
                    'fk-merchant-tts-wallets-merchant' => [['merchant_id'], 'merchants', ['id']],
                    'fk-merchant-tts-wallets-updater' => [['updated_by'], 'users', ['id']],
                ],
                'checks' => [
                    'ck-merchant-tts-wallets-address' => "REGEXP_LIKE(address, '^0x[0-9a-fA-F]{40}$', 'c')",
                    'ck-merchant-tts-wallets-time' => 'updated_at >= 0',
                ],
            ],
            'pos_tts_payments' => [
                'columns' => [
                    'id' => ['INT NOT NULL AUTO_INCREMENT', 'int', 'NO', null, null, 'auto_increment'],
                    'public_id' => ["CHAR(36) $ascii NOT NULL", 'char(36)', 'NO', null, 'ascii_bin', ''],
                    'merchant_id' => $int, 'store_id' => $int, 'terminal_id' => $int, 'opening_id' => $int, 'session_id' => $int, 'user_id' => $int,
                    'amount_cents' => $time,
                    'to_address' => $address,
                    'reference' => ["CHAR(32) $ascii NOT NULL", 'char(32)', 'NO', null, 'ascii_bin', ''],
                    'start_block' => $time,
                    'state' => ["VARCHAR(16) $ascii NOT NULL DEFAULT 'pending'", 'varchar(16)', 'NO', 'pending', 'ascii_bin', ''],
                    'tx_hash' => ["CHAR(66) $ascii NULL", 'char(66)', 'YES', null, 'ascii_bin', ''],
                    'paid_block' => $optionalTime, 'paid_at' => $optionalTime, 'closed_at' => $optionalTime,
                    'idempotency_sha256' => $digest, 'request_sha256' => $digest,
                    'created_at' => $time, 'expires_at' => $time,
                ],
                'indexes' => [
                    'PRIMARY' => ['unique' => true, 'columns' => ['id']],
                    'uq-pos-tts-payments-public' => ['unique' => true, 'columns' => ['public_id']],
                    'uq-pos-tts-payments-reference' => ['unique' => true, 'columns' => ['reference']],
                    'uq-pos-tts-payments-tx' => ['unique' => true, 'columns' => ['tx_hash']],
                    'uq-pos-tts-payments-idempotency' => ['unique' => true, 'columns' => ['session_id', 'idempotency_sha256']],
                    'idx-pos-tts-payments-pending' => ['unique' => false, 'columns' => ['state', 'expires_at']],
                    'idx-pos-tts-payments-merchant' => ['unique' => false, 'columns' => ['merchant_id']],
                    'idx-pos-tts-payments-store' => ['unique' => false, 'columns' => ['store_id']],
                    'idx-pos-tts-payments-terminal' => ['unique' => false, 'columns' => ['terminal_id']],
                    'idx-pos-tts-payments-opening' => ['unique' => false, 'columns' => ['opening_id']],
                    'idx-pos-tts-payments-user' => ['unique' => false, 'columns' => ['user_id']],
                ],
                'foreignKeys' => [
                    'fk-pos-tts-payments-merchant' => [['merchant_id'], 'merchants', ['id']],
                    'fk-pos-tts-payments-store' => [['store_id'], 'stores', ['id']],
                    'fk-pos-tts-payments-terminal' => [['terminal_id'], 'pos_terminals', ['id']],
                    'fk-pos-tts-payments-opening' => [['opening_id'], 'pos_terminal_openings', ['id']],
                    'fk-pos-tts-payments-session' => [['session_id'], 'pos_operator_sessions', ['id']],
                    'fk-pos-tts-payments-user' => [['user_id'], 'users', ['id']],
                ],
                'checks' => [
                    'ck-pos-tts-payments-public' => "REGEXP_LIKE(public_id, $uuid, 'c')",
                    'ck-pos-tts-payments-address' => "REGEXP_LIKE(to_address, '^0x[0-9a-fA-F]{40}$', 'c')",
                    'ck-pos-tts-payments-reference' => "REGEXP_LIKE(reference, '^[0-9a-f]{32}$', 'c')",
                    'ck-pos-tts-payments-tx' => "tx_hash IS NULL OR REGEXP_LIKE(tx_hash, '^0x[0-9a-f]{64}$', 'c')",
                    'ck-pos-tts-payments-digests' => "REGEXP_LIKE(idempotency_sha256, '^[0-9a-f]{64}$', 'c') AND REGEXP_LIKE(request_sha256, '^[0-9a-f]{64}$', 'c')",
                    'ck-pos-tts-payments-amount' => 'amount_cents >= 1 AND amount_cents <= 100000',
                    'ck-pos-tts-payments-state' => "state IN ('pending','paid','expired','cancelled')",
                    // Esito coerente con lo stato; IS [NOT] NULL espliciti: un CHECK con esito NULL passerebbe.
                    'ck-pos-tts-payments-paid' => "(state = 'paid') = (tx_hash IS NOT NULL) AND (state = 'paid') = (paid_block IS NOT NULL) AND (state = 'paid') = (paid_at IS NOT NULL)",
                    'ck-pos-tts-payments-closed' => "(state IN ('expired','cancelled')) = (closed_at IS NOT NULL)",
                    'ck-pos-tts-payments-time' => 'created_at >= 0 AND expires_at > created_at AND start_block >= 0 AND (paid_block IS NULL OR paid_block >= start_block) AND (closed_at IS NULL OR closed_at >= created_at)',
                ],
            ],
        ];
    }

    private function createSql(string $table): string
    {
        $definition = self::tables()[$table];
        $parts = [];
        foreach ($definition['columns'] as $name => $column) $parts[] = $this->db->quoteColumnName($name) . ' ' . $column[0];
        foreach ($definition['indexes'] as $name => $index) {
            $parts[] = ($name === 'PRIMARY' ? 'PRIMARY KEY' : ($index['unique'] ? 'UNIQUE KEY ' : 'KEY ') . $this->db->quoteColumnName($name))
                . ' (' . $this->columnList($index['columns']) . ')';
        }
        foreach ($definition['foreignKeys'] as $name => [$columns, $parent, $referenced]) {
            $parts[] = 'CONSTRAINT ' . $this->db->quoteColumnName($name) . ' FOREIGN KEY (' . $this->columnList($columns) . ') REFERENCES '
                . $this->db->quoteTableName($this->table($parent)) . ' (' . $this->columnList($referenced) . ') ON DELETE RESTRICT ON UPDATE RESTRICT';
        }
        foreach ($definition['checks'] as $name => $expression) $parts[] = 'CONSTRAINT ' . $this->db->quoteColumnName($name) . ' CHECK (' . $expression . ')';
        return 'CREATE TABLE ' . $this->db->quoteTableName($this->table($table)) . ' (' . implode(', ', $parts) . ') ENGINE=InnoDB CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_bin';
    }

    private function verify(string $name): void
    {
        $definition = self::tables()[$name];
        $table = $this->table($name);
        $row = $this->db->createCommand('SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t', [':t' => $table])->queryOne();
        if (!$row || $row['ENGINE'] !== 'InnoDB' || $row['TABLE_COLLATION'] !== 'utf8mb4_0900_bin') $this->incompatible();
        $columns = $this->db->createCommand('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,COLLATION_NAME,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t ORDER BY ORDINAL_POSITION', [':t' => $table])->queryAll();
        $expected = $definition['columns'];
        if (array_column($columns, 'COLUMN_NAME') !== array_keys($expected)) $this->incompatible();
        foreach ($columns as $column) {
            [, $type, $nullable, $default, $collation, $extra] = $expected[$column['COLUMN_NAME']];
            if ($column['COLUMN_TYPE'] !== $type || $column['IS_NULLABLE'] !== $nullable || $column['COLUMN_DEFAULT'] !== $default
                || $column['COLLATION_NAME'] !== $collation || $column['EXTRA'] !== $extra) $this->incompatible();
        }
        $indexes = [];
        foreach ($this->db->createCommand('SHOW INDEX FROM ' . $this->db->quoteTableName($table))->queryAll() as $index) {
            if ($index['Sub_part'] !== null || $index['Index_type'] !== 'BTREE' || $index['Visible'] !== 'YES') $this->incompatible();
            $indexes[$index['Key_name']]['unique'] = !(bool) $index['Non_unique'];
            $indexes[$index['Key_name']]['columns'][(int) $index['Seq_in_index']] = $index['Column_name'];
        }
        foreach ($indexes as &$index) { ksort($index['columns']); $index['columns'] = array_values($index['columns']); }
        unset($index);
        $wanted = $definition['indexes'];
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
        $wantedForeign = array_map(fn($fk) => [$fk[0], $this->table($fk[1]), $fk[2]], $definition['foreignKeys']);
        ksort($foreign); ksort($wantedForeign);
        if ($foreign !== $wantedForeign) $this->incompatible();
        $checks = [];
        foreach ($this->db->createCommand("SELECT t.CONSTRAINT_NAME,c.CHECK_CLAUSE,t.ENFORCED FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME=:t AND t.CONSTRAINT_TYPE='CHECK'", [':t' => $table])->queryAll() as $check) {
            if ($check['ENFORCED'] !== 'YES') $this->incompatible();
            $checks[$check['CONSTRAINT_NAME']] = self::clause(self::stored($check['CHECK_CLAUSE']));
        }
        $wantedChecks = array_map([self::class, 'clause'], self::CANONICAL_CHECKS[$name]);
        ksort($checks); ksort($wantedChecks);
        $declared = array_keys($definition['checks']); sort($declared);
        if ($checks !== $wantedChecks || array_keys($wantedChecks) !== $declared) $this->incompatible();
    }

    /** Resa di MySQL 8.4.13 dei CHECK di tables(), dopo stored(); confronto con clause(). */
    private const CANONICAL_CHECKS = [
        'merchant_tts_wallets' => [
            'ck-merchant-tts-wallets-address' => 'regexp_like(`address`,_utf8mb4\'^0x[0-9a-fA-F]{40}$\',_utf8mb4\'c\')',
            'ck-merchant-tts-wallets-time' => '(`updated_at` >= 0)',
        ],
        'pos_tts_payments' => [
            'ck-pos-tts-payments-address' => 'regexp_like(`to_address`,_utf8mb4\'^0x[0-9a-fA-F]{40}$\',_utf8mb4\'c\')',
            'ck-pos-tts-payments-amount' => '((`amount_cents` >= 1) and (`amount_cents` <= 100000))',
            'ck-pos-tts-payments-closed' => '((`state` in (_utf8mb4\'expired\',_utf8mb4\'cancelled\')) = (`closed_at` is not null))',
            'ck-pos-tts-payments-digests' => '(regexp_like(`idempotency_sha256`,_utf8mb4\'^[0-9a-f]{64}$\',_utf8mb4\'c\') and regexp_like(`request_sha256`,_utf8mb4\'^[0-9a-f]{64}$\',_utf8mb4\'c\'))',
            'ck-pos-tts-payments-paid' => '(((`state` = _utf8mb4\'paid\') = (`tx_hash` is not null)) and ((`state` = _utf8mb4\'paid\') = (`paid_block` is not null)) and ((`state` = _utf8mb4\'paid\') = (`paid_at` is not null)))',
            'ck-pos-tts-payments-public' => 'regexp_like(`public_id`,_utf8mb4\'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$\',_utf8mb4\'c\')',
            'ck-pos-tts-payments-reference' => 'regexp_like(`reference`,_utf8mb4\'^[0-9a-f]{32}$\',_utf8mb4\'c\')',
            'ck-pos-tts-payments-state' => '(`state` in (_utf8mb4\'pending\',_utf8mb4\'paid\',_utf8mb4\'expired\',_utf8mb4\'cancelled\'))',
            'ck-pos-tts-payments-time' => '((`created_at` >= 0) and (`expires_at` > `created_at`) and (`start_block` >= 0) and ((`paid_block` is null) or (`paid_block` >= `start_block`)) and ((`closed_at` is null) or (`closed_at` >= `created_at`)))',
            'ck-pos-tts-payments-tx' => '((`tx_hash` is null) or regexp_like(`tx_hash`,_utf8mb4\'^0x[0-9a-f]{64}$\',_utf8mb4\'c\'))',
        ],
    ];

    /** information_schema riporta il testo dell'espressione con un livello di escape in più (\' e \\): lo toglie. */
    private static function stored(string $sql): string
    {
        return preg_replace_callback('/\\\\([\\\\\'])/', fn($m) => $m[1], $sql);
    }

    /**
     * Normalizza solo fuori dai letterali: spazi, backtick e introducer _utf8mb4/_ascii (dopo un ALTER MySQL
     * riscrive l'uno nell'altro). I letterali restano byte per byte; letterale aperto = incompatibile.
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
                if ($j >= $n) throw new RuntimeException('POS TTS payments schema is incompatible; no automatic repair permitted.');
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
    private function incompatible(): void { throw new RuntimeException('POS TTS payments schema is incompatible; no automatic repair permitted.'); }
}
