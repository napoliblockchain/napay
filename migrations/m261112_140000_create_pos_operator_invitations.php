<?php

use yii\db\Migration;

/**
 * SIP-021 C4 parte D: inviti operatore POS. Tabella additiva, nessuna modifica a users o alle tabelle C1.
 * Token solo come SHA-256; un solo invito attivo per merchant/email garantito dal lock del merchant nei writer.
 */
class m261112_140000_create_pos_operator_invitations extends Migration
{
    private const TABLE = 'pos_operator_invitations';

    public function up()
    {
        if ($this->db->driverName !== 'mysql' || !preg_match('/^8\.4\./', $this->db->getServerVersion())) {
            throw new RuntimeException('POS invitations require MySQL 8.4.');
        }
        foreach (['users', 'merchants'] as $parent) {
            $engine = $this->db->createCommand('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t', [':t' => $this->table($parent)])->queryScalar();
            $id = $this->db->schema->getTableSchema($this->table($parent), true)->columns['id'] ?? null;
            if ($engine !== 'InnoDB' || !$id || $id->dbType !== 'int' || $id->unsigned || $id->allowNull) $this->incompatible();
        }
        // ripresa: solo una definizione identica viene accettata; nessuna riparazione automatica
        if ($this->db->schema->getTableSchema($this->table(self::TABLE), true) === null) {
            $this->execute($this->createSql());
        }
        $this->verify();
        $this->db->schema->refresh();
    }

    public function down()
    {
        echo "POS invitations rollback requires an approved backup restore.\n";
        return false;
    }

    /** Colonna => [tipo SQL, COLUMN_TYPE, nullable, default, collation] */
    private function columns(): array
    {
        return [
            'id' => ['INT NOT NULL AUTO_INCREMENT', 'int', 'NO', null, null],
            'merchant_id' => ['INT NOT NULL', 'int', 'NO', null, null],
            'email' => ['VARCHAR(255) NOT NULL', 'varchar(255)', 'NO', null, 'utf8mb4_0900_bin'],
            'token_sha256' => ['CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL', 'char(64)', 'NO', null, 'ascii_bin'],
            'created_by' => ['INT NOT NULL', 'int', 'NO', null, null],
            'created_at' => ['BIGINT NOT NULL', 'bigint', 'NO', null, null],
            'updated_at' => ['BIGINT NOT NULL', 'bigint', 'NO', null, null],
            'expires_at' => ['BIGINT NOT NULL', 'bigint', 'NO', null, null],
            'sent_at' => ['BIGINT NULL', 'bigint', 'YES', null, null],
            'send_failures' => ['SMALLINT NOT NULL DEFAULT 0', 'smallint', 'NO', '0', null],
            'consumed_at' => ['BIGINT NULL', 'bigint', 'YES', null, null],
            'consumed_user_id' => ['INT NULL', 'int', 'YES', null, null],
            'revoked_at' => ['BIGINT NULL', 'bigint', 'YES', null, null],
        ];
    }

    private function indexes(): array
    {
        return [
            'PRIMARY' => ['unique' => true, 'columns' => ['id']],
            'uq-pos-invitations-token' => ['unique' => true, 'columns' => ['token_sha256']],
            'idx-pos-invitations-merchant' => ['unique' => false, 'columns' => ['merchant_id', 'created_at']],
            'idx-pos-invitations-email' => ['unique' => false, 'columns' => ['merchant_id', 'email']],
            'idx-pos-invitations-creator' => ['unique' => false, 'columns' => ['created_by']],
            'idx-pos-invitations-consumer' => ['unique' => false, 'columns' => ['consumed_user_id']],
        ];
    }

    private function foreignKeys(): array
    {
        return [
            'fk-pos-invitations-merchant' => [['merchant_id'], 'merchants', ['id']],
            'fk-pos-invitations-creator' => [['created_by'], 'users', ['id']],
            'fk-pos-invitations-consumer' => [['consumed_user_id'], 'users', ['id']],
        ];
    }

    private function checks(): array
    {
        return [
            'ck-pos-invitations-email' => "CHAR_LENGTH(email) BETWEEN 3 AND 255",
            'ck-pos-invitations-token' => 'CHAR_LENGTH(token_sha256) = 64',
            'ck-pos-invitations-time' => 'created_at >= 0 AND updated_at >= created_at AND expires_at > created_at',
            'ck-pos-invitations-failures' => 'send_failures >= 0',
            'ck-pos-invitations-consumed' => '(consumed_at IS NULL) = (consumed_user_id IS NULL)',
            'ck-pos-invitations-final' => 'consumed_at IS NULL OR revoked_at IS NULL',
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
        $columns = $this->db->createCommand('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLLATION_NAME,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t ORDER BY ORDINAL_POSITION', [':t' => $table])->queryAll();
        $expected = $this->columns();
        if (array_column($columns, 'COLUMN_NAME') !== array_keys($expected)) $this->incompatible();
        foreach ($columns as $column) {
            [$sql, $type, $nullable, $default, $collation] = $expected[$column['COLUMN_NAME']];
            if ($column['COLUMN_TYPE'] !== $type || $column['IS_NULLABLE'] !== $nullable || $column['COLUMN_DEFAULT'] !== $default
                || $column['EXTRA'] !== (str_contains($sql, 'AUTO_INCREMENT') ? 'auto_increment' : '')
                || $column['COLLATION_NAME'] !== $collation || (string) $column['GENERATION_EXPRESSION'] !== '') $this->incompatible();
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
            $checks[$check['CONSTRAINT_NAME']] = self::clause($check['CHECK_CLAUSE']);
        }
        $wantedChecks = array_map([self::class, 'clause'], self::CANONICAL_CHECKS);
        ksort($checks); ksort($wantedChecks);
        if ($checks !== $wantedChecks) $this->incompatible();
    }

    /** Forma con cui MySQL 8.4 restituisce i CHECK creati da checks(); confronto senza spazi e backtick, parentesi intatte. */
    private const CANONICAL_CHECKS = [
        'ck-pos-invitations-email' => '(char_length(`email`) between 3 and 255)',
        'ck-pos-invitations-token' => '(char_length(`token_sha256`) = 64)',
        'ck-pos-invitations-time' => '((`created_at` >= 0) and (`updated_at` >= `created_at`) and (`expires_at` > `created_at`))',
        'ck-pos-invitations-failures' => '(`send_failures` >= 0)',
        'ck-pos-invitations-consumed' => '((`consumed_at` is null) = (`consumed_user_id` is null))',
        'ck-pos-invitations-final' => '((`consumed_at` is null) or (`revoked_at` is null))',
    ];

    private static function clause(string $sql): string
    {
        return strtolower(preg_replace('/[\s`]+/', '', $sql));
    }

    private function table(string $name): string { return $this->db->tablePrefix . $name; }
    private function columnList(array $columns): string { return implode(', ', array_map([$this->db, 'quoteColumnName'], $columns)); }
    private function incompatible(): void { throw new RuntimeException('POS invitations schema is incompatible; no automatic repair permitted.'); }
}
