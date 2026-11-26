<?php

use yii\db\Migration;

/** SIP-033 step 2: empty BTC payment ledger; no remote requests, legacy adoption or destructive rollback. */
class m261118_100000_create_pos_btc_payments extends Migration
{
    public function up()
    {
        if ($this->db->driverName !== 'mysql' || !preg_match('/^8\.4\./', $this->db->getServerVersion())) {
            throw new RuntimeException('POS BTC payments require MySQL 8.4.');
        }
        foreach (['users', 'merchants', 'stores', 'pos_terminals', 'pos_operator_sessions', 'pos_terminal_openings'] as $parent) {
            $engine = $this->db->createCommand('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t', [':t' => $this->table($parent)])->queryScalar();
            $id = $this->db->schema->getTableSchema($this->table($parent), true)->columns['id'] ?? null;
            if ($engine !== 'InnoDB' || !$id || $id->dbType !== 'int' || $id->unsigned || $id->allowNull) $this->incompatible();
        }
        foreach (array_keys(self::tables()) as $table) {
            if ($this->db->schema->getTableSchema($this->table($table), true) === null) $this->execute($this->createSql($table));
            $this->verify($table);
        }
        $this->db->schema->refresh();
    }

    public function down()
    {
        echo "POS BTC payments rollback requires an approved backup restore.\n";
        return false;
    }

    private static function tables(): array
    {
        $ascii = 'CHARACTER SET ascii COLLATE ascii_bin';
        $int = ['INT NOT NULL', 'int', 'NO', null, null, ''];
        $time = ['BIGINT NOT NULL', 'bigint', 'NO', null, null, ''];
        $optionalTime = ['BIGINT NULL', 'bigint', 'YES', null, null, ''];
        $digest = ["CHAR(64) $ascii NOT NULL", 'char(64)', 'NO', null, 'ascii_bin', ''];
        $remote = ["VARCHAR(64) $ascii NOT NULL", 'varchar(64)', 'NO', null, 'ascii_bin', ''];
        return ['pos_btc_payments' => [
            'columns' => [
                'id' => ['INT NOT NULL AUTO_INCREMENT', 'int', 'NO', null, null, 'auto_increment'],
                'public_id' => ["CHAR(36) $ascii NOT NULL", 'char(36)', 'NO', null, 'ascii_bin', ''],
                'merchant_id' => $int, 'store_id' => $int, 'terminal_id' => $int,
                'opening_id' => $int, 'session_id' => $int, 'user_id' => $int,
                'instance' => $digest, 'remote_store_id' => $remote, 'remote_app_id' => $remote,
                'invoice_id' => ["VARCHAR(64) $ascii NULL", 'varchar(64)', 'YES', null, 'ascii_bin', ''],
                'amount_cents' => $time,
                'currency' => ["CHAR(3) $ascii NOT NULL", 'char(3)', 'NO', null, 'ascii_bin', ''],
                'state' => ["VARCHAR(24) $ascii NOT NULL DEFAULT 'creating'", 'varchar(24)', 'NO', 'creating', 'ascii_bin', ''],
                'btc_address' => ["VARCHAR(90) $ascii NULL", 'varchar(90)', 'YES', null, 'ascii_bin', ''],
                'amount_satoshis' => $optionalTime,
                'confirmations' => ['INT NOT NULL DEFAULT 0', 'int', 'NO', '0', null, ''],
                'creation_started_at' => $optionalTime, 'processing_at' => $optionalTime, 'settled_at' => $optionalTime, 'closed_at' => $optionalTime,
                'idempotency_sha256' => $digest, 'request_sha256' => $digest,
                'last_error_code' => ["VARCHAR(64) $ascii NULL", 'varchar(64)', 'YES', null, 'ascii_bin', ''],
                'version' => ['INT NOT NULL DEFAULT 0', 'int', 'NO', '0', null, ''],
                'created_at' => $time, 'updated_at' => $time, 'expires_at' => $time,
            ],
            'indexes' => [
                'PRIMARY' => ['unique' => true, 'columns' => ['id']],
                'uq-pos-btc-payments-public' => ['unique' => true, 'columns' => ['public_id']],
                'uq-pos-btc-payments-invoice' => ['unique' => true, 'columns' => ['instance', 'invoice_id']],
                'uq-pos-btc-payments-idempotency' => ['unique' => true, 'columns' => ['session_id', 'idempotency_sha256']],
                'idx-pos-btc-payments-state' => ['unique' => false, 'columns' => ['state', 'expires_at']],
                'idx-pos-btc-payments-merchant' => ['unique' => false, 'columns' => ['merchant_id', 'created_at']],
                'idx-pos-btc-payments-store' => ['unique' => false, 'columns' => ['store_id', 'merchant_id', 'created_at']],
                'idx-pos-btc-payments-terminal' => ['unique' => false, 'columns' => ['terminal_id', 'store_id', 'merchant_id', 'created_at']],
                'idx-pos-btc-payments-opening' => ['unique' => false, 'columns' => ['opening_id']],
                'idx-pos-btc-payments-user' => ['unique' => false, 'columns' => ['user_id']],
            ],
            'foreignKeys' => [
                'fk-pos-btc-payments-merchant' => [['merchant_id'], 'merchants', ['id']],
                'fk-pos-btc-payments-store' => [['store_id', 'merchant_id'], 'stores', ['id', 'merchant_id']],
                'fk-pos-btc-payments-terminal' => [['terminal_id', 'store_id', 'merchant_id'], 'pos_terminals', ['id', 'store_id', 'merchant_id']],
                'fk-pos-btc-payments-opening' => [['opening_id'], 'pos_terminal_openings', ['id']],
                'fk-pos-btc-payments-session' => [['session_id'], 'pos_operator_sessions', ['id']],
                'fk-pos-btc-payments-user' => [['user_id'], 'users', ['id']],
            ],
            // Fully grouped, lowercase expressions match MySQL's canonical rendering (same verifier as TTS).
            'checks' => [
                'ck-pos-btc-payments-public' => "regexp_like(public_id, '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$', 'c')",
                'ck-pos-btc-payments-digests' => "(regexp_like(instance, '^[0-9a-f]{64}$', 'c') and regexp_like(idempotency_sha256, '^[0-9a-f]{64}$', 'c') and regexp_like(request_sha256, '^[0-9a-f]{64}$', 'c'))",
                'ck-pos-btc-payments-remote' => "(regexp_like(remote_store_id, '^[A-Za-z0-9_-]{1,64}$', 'c') and regexp_like(remote_app_id, '^[A-Za-z0-9_-]{1,64}$', 'c') and ((invoice_id is null) or regexp_like(invoice_id, '^[A-Za-z0-9_-]{1,64}$', 'c')))",
                'ck-pos-btc-payments-amount' => "((amount_cents >= 1) and (amount_cents <= 100000) and regexp_like(currency, '^[A-Z]{3}$', 'c'))",
                'ck-pos-btc-payments-state' => "(state in ('creating','pending','processing','settled','expired','invalid','failed','reconcile_required'))",
                'ck-pos-btc-payments-invoice' => "((state in ('pending','processing','settled','expired','invalid')) = (invoice_id is not null))",
                'ck-pos-btc-payments-checkpoint' => "((state in ('creating','failed')) or (creation_started_at is not null))",
                'ck-pos-btc-payments-btc' => "(((btc_address is null) = (amount_satoshis is null)) and ((btc_address is null) or regexp_like(btc_address, '^[A-Za-z0-9]{14,90}$', 'c')) and ((amount_satoshis is null) or ((amount_satoshis >= 1) and (amount_satoshis <= 2100000000000000))) and (confirmations >= 0))",
                'ck-pos-btc-payments-settled' => "((state = 'settled') = (settled_at is not null))",
                'ck-pos-btc-payments-processing' => "((state <> 'processing') or (processing_at is not null))",
                'ck-pos-btc-payments-closed' => "((state in ('expired','invalid','failed')) = (closed_at is not null))",
                'ck-pos-btc-payments-error' => "((last_error_code is null) or regexp_like(last_error_code, '^[a-z0-9_]{1,64}$', 'c'))",
                'ck-pos-btc-payments-time' => "((created_at >= 0) and (updated_at >= created_at) and (expires_at > created_at) and (version >= 0) and ((creation_started_at is null) or ((creation_started_at >= created_at) and (creation_started_at <= updated_at))) and ((processing_at is null) or ((processing_at >= created_at) and (processing_at <= updated_at))) and ((settled_at is null) or ((settled_at >= created_at) and (settled_at <= updated_at))) and ((closed_at is null) or ((closed_at >= created_at) and (closed_at <= updated_at))))",
            ],
        ]];
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

    /** SIP-033 passo 5 (m261118_150000, m261118_160000): cambio, dettaglio BTC e transazioni, colonne opzionali in coda e vincoli. */
    private const STEP5_COLUMNS = [
        'rate_cents' => ['BIGINT NULL', 'bigint', 'YES', null, null, ''],
        'order_satoshis' => ['BIGINT NULL', 'bigint', 'YES', null, null, ''],
        'paid_satoshis' => ['BIGINT NULL', 'bigint', 'YES', null, null, ''],
        // m261118_160000: transazioni dalla ricevuta BTCPay
        'txids' => ["VARCHAR(650) CHARACTER SET ascii COLLATE ascii_bin NULL", 'varchar(650)', 'YES', null, 'ascii_bin', ''],
    ];
    private const STEP5_CHECKS = [
        'ck-pos-btc-payments-rates' => '(((rate_cents is null) or (rate_cents between 1 and 10000000000000)) and ((order_satoshis is null) or ((amount_satoshis is not null) and (order_satoshis between 1 and amount_satoshis))) and ((paid_satoshis is null) or (paid_satoshis between 0 and 2100000000000000)))',
        'ck-pos-btc-payments-txids' => "((txids is null) or regexp_like(txids,'^[0-9a-f]{64}(,[0-9a-f]{64}){0,9}$','c'))",
    ];

    /**
     * SIP-030 passo 5c (m261119_120000): storico v1 nella stessa tabella; apertura, sessione, utente e app POS facoltativi
     * solo tutti insieme e solo per pagamenti chiusi. Accettato solo esattamente così, dopo il passo 5.
     */
    private const LEGACY_COLUMNS = [
        'opening_id' => ['INT NULL', 'int', 'YES', null, null, ''],
        'session_id' => ['INT NULL', 'int', 'YES', null, null, ''],
        'user_id' => ['INT NULL', 'int', 'YES', null, null, ''],
        'remote_app_id' => ["VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL", 'varchar(64)', 'YES', null, 'ascii_bin', ''],
    ];
    private const LEGACY_CHECKS = [
        'ck-pos-btc-payments-origin' => "(((opening_id is null) = (session_id is null)) and ((opening_id is null) = (user_id is null)) and ((opening_id is null) = (remote_app_id is null)) and ((opening_id is not null) or (state in ('settled','expired','invalid'))) and regexp_like(remote_store_id,'^[A-Za-z0-9_-]{1,64}$','c'))",
    ];

    private function verify(string $name): void
    {
        $definition = self::tables()[$name];
        $table = $this->table($name);
        $row = $this->db->createCommand('SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t', [':t' => $table])->queryOne();
        if (!$row || $row['ENGINE'] !== 'InnoDB' || $row['TABLE_COLLATION'] !== 'utf8mb4_0900_bin') $this->incompatible();
        $columns = $this->db->createCommand('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,COLLATION_NAME,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t ORDER BY ORDINAL_POSITION', [':t' => $table])->queryAll();
        $expected = $definition['columns'];
        // estensione additiva del passo 5 (m261118_150000): accettata solo esattamente com'è, in coda
        if (array_column($columns, 'COLUMN_NAME') === array_merge(array_keys($expected), array_keys(self::STEP5_COLUMNS))) {
            $expected += self::STEP5_COLUMNS;
            $definition['checks'] += self::STEP5_CHECKS;
            $live = array_column($columns, 'IS_NULLABLE', 'COLUMN_NAME');
            if ($live['opening_id'] === 'YES') {
                $expected = array_replace($expected, self::LEGACY_COLUMNS);
                $definition['checks'] += self::LEGACY_CHECKS;
            }
        }
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
        $wantedChecks = array_map([self::class, 'clause'], $definition['checks']);
        ksort($checks); ksort($wantedChecks);
        $declared = array_keys($definition['checks']); sort($declared);
        if ($checks !== $wantedChecks || array_keys($wantedChecks) !== $declared) $this->incompatible();
    }

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
                if ($j >= $n) throw new RuntimeException('POS BTC payments schema is incompatible; no automatic repair permitted.');
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
    private function incompatible(): void { throw new RuntimeException('POS BTC payments schema is incompatible; no automatic repair permitted.'); }
}
