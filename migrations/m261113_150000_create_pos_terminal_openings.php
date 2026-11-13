<?php

use yii\db\Migration;

/**
 * SIP-021 fase 2 (APERTURE-CONTRATTO): aperture esclusive dei terminali POS. Tabella additiva e vuota.
 * Al massimo una apertura `active` per terminale (colonna generata + UNIQUE); `generation` crescente per
 * terminale = prova di fencing. Nessuna modifica alle tabelle C1 o alle sessioni.
 */
class m261113_150000_create_pos_terminal_openings extends Migration
{
    private const TABLE = 'pos_terminal_openings';

    public function up()
    {
        if ($this->db->driverName !== 'mysql' || !preg_match('/^8\.4\./', $this->db->getServerVersion())) {
            throw new RuntimeException('POS openings require MySQL 8.4.');
        }
        foreach (['users', 'merchants', 'pos_terminals', 'pos_operator_sessions'] as $parent) {
            $engine = $this->db->createCommand('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t', [':t' => $this->table($parent)])->queryScalar();
            $id = $this->db->schema->getTableSchema($this->table($parent), true)->columns['id'] ?? null;
            if ($engine !== 'InnoDB' || !$id || $id->dbType !== 'int' || $id->unsigned || $id->allowNull) $this->incompatible();
        }
        // ripresa: solo una definizione identica viene accettata; nessuna riparazione automatica
        if ($this->db->schema->getTableSchema($this->table(self::TABLE), true) === null) $this->execute($this->createSql());
        $this->verify();
        $this->db->schema->refresh();
    }

    public function down()
    {
        echo "POS openings rollback requires an approved backup restore.\n";
        return false;
    }

    /** Colonna => [SQL, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLLATION_NAME, EXTRA, GENERATION_EXPRESSION canonica] */
    private function columns(): array
    {
        $ascii = 'CHARACTER SET ascii COLLATE ascii_bin';
        $int = ['INT NOT NULL', 'int', 'NO', null, null, '', ''];
        $uuid = ["CHAR(36) $ascii NOT NULL", 'char(36)', 'NO', null, 'ascii_bin', '', ''];
        $digest = ["CHAR(64) $ascii NOT NULL", 'char(64)', 'NO', null, 'ascii_bin', '', ''];
        $time = ['BIGINT NOT NULL', 'bigint', 'NO', null, null, '', ''];
        return [
            'id' => ['INT NOT NULL AUTO_INCREMENT', 'int', 'NO', null, null, 'auto_increment', ''],
            'public_id' => $uuid,
            'terminal_id' => $int, 'merchant_id' => $int, 'session_id' => $int, 'user_id' => $int,
            'instance_id' => $uuid,
            'generation' => ['BIGINT NOT NULL', 'bigint', 'NO', null, null, '', ''],
            'state' => ["VARCHAR(16) $ascii NOT NULL DEFAULT 'active'", 'varchar(16)', 'NO', 'active', 'ascii_bin', '', ''],
            'active_marker' => ["TINYINT GENERATED ALWAYS AS (CASE WHEN state = 'active' THEN 1 END) STORED NULL", 'tinyint', 'YES', null, null, 'STORED GENERATED', "(case when (state = _utf8mb4'active') then 1 end)"],
            'idempotency_sha256' => $digest, 'request_sha256' => $digest,
            'opened_at' => $time, 'last_seen_at' => $time,
            'closed_at' => ['BIGINT NULL', 'bigint', 'YES', null, null, '', ''],
            'closed_reason' => ["VARCHAR(24) $ascii NULL", 'varchar(24)', 'YES', null, 'ascii_bin', '', ''],
            'closed_by' => ['INT NULL', 'int', 'YES', null, null, '', ''],
        ];
    }

    private function indexes(): array
    {
        return [
            'PRIMARY' => ['unique' => true, 'columns' => ['id']],
            'uq-pos-openings-public' => ['unique' => true, 'columns' => ['public_id']],
            'uq-pos-openings-generation' => ['unique' => true, 'columns' => ['terminal_id', 'generation']],
            'uq-pos-openings-active' => ['unique' => true, 'columns' => ['terminal_id', 'active_marker']],
            'uq-pos-openings-idempotency' => ['unique' => true, 'columns' => ['session_id', 'idempotency_sha256']],
            'idx-pos-openings-session' => ['unique' => false, 'columns' => ['session_id', 'state']],
            'idx-pos-openings-merchant' => ['unique' => false, 'columns' => ['merchant_id']],
            'idx-pos-openings-user' => ['unique' => false, 'columns' => ['user_id']],
            'idx-pos-openings-closer' => ['unique' => false, 'columns' => ['closed_by']],
        ];
    }

    private function foreignKeys(): array
    {
        return [
            'fk-pos-openings-terminal' => [['terminal_id'], 'pos_terminals', ['id']],
            'fk-pos-openings-merchant' => [['merchant_id'], 'merchants', ['id']],
            'fk-pos-openings-session' => [['session_id'], 'pos_operator_sessions', ['id']],
            'fk-pos-openings-user' => [['user_id'], 'users', ['id']],
            'fk-pos-openings-closer' => [['closed_by'], 'users', ['id']],
        ];
    }

    private function checks(): array
    {
        $uuid = "'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'";
        return [
            'ck-pos-openings-public' => "REGEXP_LIKE(public_id, $uuid, 'c')",
            'ck-pos-openings-instance' => "REGEXP_LIKE(instance_id, $uuid, 'c')",
            'ck-pos-openings-digests' => "REGEXP_LIKE(idempotency_sha256, '^[0-9a-f]{64}$', 'c') AND REGEXP_LIKE(request_sha256, '^[0-9a-f]{64}$', 'c')",
            'ck-pos-openings-generation' => 'generation >= 1',
            'ck-pos-openings-state' => "state IN ('active','released','revoked')",
            // Chiusura coerente con lo stato; IS [NOT] NULL espliciti: un CHECK con esito NULL passerebbe.
            'ck-pos-openings-closed' => "(state = 'active') = (closed_at IS NULL) AND (state = 'active') = (closed_reason IS NULL)",
            'ck-pos-openings-reason' => "state = 'active' OR (state = 'released' AND closed_reason IN ('released','logout')) OR (state = 'revoked' AND closed_reason IN ('manager_unlock','owner_ineligible'))",
            'ck-pos-openings-closer' => "(closed_by IS NULL) = (closed_reason IS NULL OR closed_reason <> 'manager_unlock')",
            'ck-pos-openings-time' => 'opened_at >= 0 AND last_seen_at >= opened_at AND (closed_at IS NULL OR closed_at >= opened_at)',
        ];
    }

    private function createSql(): string
    {
        $parts = [];
        foreach ($this->columns() as $name => $column) $parts[] = $this->db->quoteColumnName($name) . ' ' . $column[0];
        foreach ($this->indexes() as $name => $index) {
            $parts[] = ($name === 'PRIMARY' ? 'PRIMARY KEY' : ($index['unique'] ? 'UNIQUE KEY ' : 'KEY ') . $this->db->quoteColumnName($name))
                . ' (' . $this->columnList($index['columns']) . ')';
        }
        foreach ($this->foreignKeys() as $name => [$columns, $parent, $referenced]) {
            $parts[] = 'CONSTRAINT ' . $this->db->quoteColumnName($name) . ' FOREIGN KEY (' . $this->columnList($columns) . ') REFERENCES '
                . $this->db->quoteTableName($this->table($parent)) . ' (' . $this->columnList($referenced) . ') ON DELETE RESTRICT ON UPDATE RESTRICT';
        }
        foreach ($this->checks() as $name => $expression) $parts[] = 'CONSTRAINT ' . $this->db->quoteColumnName($name) . ' CHECK (' . $expression . ')';
        return 'CREATE TABLE ' . $this->db->quoteTableName($this->table(self::TABLE)) . ' (' . implode(', ', $parts) . ') ENGINE=InnoDB CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_bin';
    }

    private function verify(): void
    {
        $table = $this->table(self::TABLE);
        $row = $this->db->createCommand('SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t', [':t' => $table])->queryOne();
        if (!$row || $row['ENGINE'] !== 'InnoDB' || $row['TABLE_COLLATION'] !== 'utf8mb4_0900_bin') $this->incompatible();
        $columns = $this->db->createCommand('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t ORDER BY ORDINAL_POSITION', [':t' => $table])->queryAll();
        $expected = $this->columns();
        if (array_column($columns, 'COLUMN_NAME') !== array_keys($expected)) $this->incompatible();
        foreach ($columns as $column) {
            [, $type, $nullable, $default, $collation, $extra, $generation] = $expected[$column['COLUMN_NAME']];
            if ($column['COLUMN_TYPE'] !== $type || $column['IS_NULLABLE'] !== $nullable || $column['COLUMN_DEFAULT'] !== $default
                || $column['COLLATION_NAME'] !== $collation || $column['EXTRA'] !== $extra
                || self::clause(self::stored((string) $column['GENERATION_EXPRESSION'])) !== self::clause($generation)) $this->incompatible();
        }
        $indexes = [];
        foreach ($this->db->createCommand('SHOW INDEX FROM ' . $this->db->quoteTableName($table))->queryAll() as $index) {
            if ($index['Sub_part'] !== null || $index['Index_type'] !== 'BTREE' || $index['Visible'] !== 'YES') $this->incompatible();
            $indexes[$index['Key_name']]['unique'] = !(bool) $index['Non_unique'];
            $indexes[$index['Key_name']]['columns'][(int) $index['Seq_in_index']] = $index['Column_name'];
        }
        foreach ($indexes as &$index) { ksort($index['columns']); $index['columns'] = array_values($index['columns']); }
        unset($index);
        $wanted = $this->indexes();
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
        $wantedForeign = array_map(fn($fk) => [$fk[0], $this->table($fk[1]), $fk[2]], $this->foreignKeys());
        ksort($foreign); ksort($wantedForeign);
        if ($foreign !== $wantedForeign) $this->incompatible();
        $checks = [];
        foreach ($this->db->createCommand("SELECT t.CONSTRAINT_NAME,c.CHECK_CLAUSE,t.ENFORCED FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME=:t AND t.CONSTRAINT_TYPE='CHECK'", [':t' => $table])->queryAll() as $check) {
            if ($check['ENFORCED'] !== 'YES') $this->incompatible();
            $checks[$check['CONSTRAINT_NAME']] = self::clause(self::stored($check['CHECK_CLAUSE']));
        }
        $wantedChecks = array_map([self::class, 'clause'], self::CANONICAL_CHECKS);
        ksort($checks); ksort($wantedChecks);
        $declared = array_keys($this->checks()); sort($declared);
        if ($checks !== $wantedChecks || array_keys($wantedChecks) !== $declared) $this->incompatible();
    }

    /** Resa di MySQL 8.4.13 dei CHECK di checks(), dopo stored(); confronto con clause(). */
    private const CANONICAL_CHECKS = [
        'ck-pos-openings-closed' => '(((`state` = _utf8mb4\'active\') = (`closed_at` is null)) and ((`state` = _utf8mb4\'active\') = (`closed_reason` is null)))',
        'ck-pos-openings-closer' => '((`closed_by` is null) = ((`closed_reason` is null) or (`closed_reason` <> _utf8mb4\'manager_unlock\')))',
        'ck-pos-openings-digests' => '(regexp_like(`idempotency_sha256`,_utf8mb4\'^[0-9a-f]{64}$\',_utf8mb4\'c\') and regexp_like(`request_sha256`,_utf8mb4\'^[0-9a-f]{64}$\',_utf8mb4\'c\'))',
        'ck-pos-openings-generation' => '(`generation` >= 1)',
        'ck-pos-openings-instance' => 'regexp_like(`instance_id`,_utf8mb4\'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$\',_utf8mb4\'c\')',
        'ck-pos-openings-public' => 'regexp_like(`public_id`,_utf8mb4\'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$\',_utf8mb4\'c\')',
        'ck-pos-openings-reason' => '((`state` = _utf8mb4\'active\') or ((`state` = _utf8mb4\'released\') and (`closed_reason` in (_utf8mb4\'released\',_utf8mb4\'logout\'))) or ((`state` = _utf8mb4\'revoked\') and (`closed_reason` in (_utf8mb4\'manager_unlock\',_utf8mb4\'owner_ineligible\'))))',
        'ck-pos-openings-state' => '(`state` in (_utf8mb4\'active\',_utf8mb4\'released\',_utf8mb4\'revoked\'))',
        'ck-pos-openings-time' => '((`opened_at` >= 0) and (`last_seen_at` >= `opened_at`) and ((`closed_at` is null) or (`closed_at` >= `opened_at`)))',
    ];

    /** information_schema riporta il testo dell'espressione con un livello di escape in più (\' e \\): lo toglie. */
    private static function stored(string $sql): string
    {
        return preg_replace_callback('/\\\\([\\\\\'])/', fn($m) => $m[1], $sql);
    }

    /**
     * Normalizza solo fuori dai letterali: spazi, backtick e introducer _utf8mb4/_ascii (dopo un ALTER MySQL
     * riscrive l'uno nell'altro). I letterali restano byte per byte; letterale aperto = incompatibile (come B1b).
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
                if ($j >= $n) throw new RuntimeException('POS openings schema is incompatible; no automatic repair permitted.');
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
    private function incompatible(): void { throw new RuntimeException('POS openings schema is incompatible; no automatic repair permitted.'); }
}
