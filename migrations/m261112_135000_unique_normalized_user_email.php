<?php

use yii\db\Migration;

/** SIP-023 R2: preserve source email; enforce one binary-normalized identity. */
class m261112_135000_unique_normalized_user_email extends Migration
{
    private const INDEX = 'uq-users-email-normalized';
    private const CHECK = 'ck-users-email-normalized-present';
    private const FAILURE = 'Normalized email migration refused; verify schema and legacy counts before retry.';

    public function up()
    {
        try {
            $this->applySchema();
        } catch (\Throwable $error) {
            // Duplicate-key errors can contain email addresses. Never retain the SQL exception.
            throw new \RuntimeException(self::FAILURE);
        }
    }

    public function down()
    {
        echo "Normalized email rollback requires an approved backup restore.\n";
        return false;
    }

    private function applySchema(): void
    {
        if ($this->db->driverName !== 'mysql' || !preg_match('/^8\.4\./', $this->db->getServerVersion())
            || $this->db->getTransaction() !== null) $this->refuse();
        $table = $this->db->tablePrefix . 'users';
        $params = [':t'=>$table];
        $engine = $this->db->createCommand('SELECT ENGINE,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t', $params)->queryOne();
        if (!$engine || $engine['ENGINE'] !== 'InnoDB' || $engine['TABLE_TYPE'] !== 'BASE TABLE') $this->refuse();
        $columns = $this->db->createCommand('SELECT COLUMN_NAME,COLUMN_TYPE,CHARACTER_SET_NAME,COLLATION_NAME,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t', $params)->queryAll();
        $columns = array_column($columns, null, 'COLUMN_NAME');
        $source = $columns['email'] ?? null;
        if (!$source || $source['COLUMN_TYPE'] !== 'varchar(255)' || $source['CHARACTER_SET_NAME'] !== 'utf8mb4'
            || $source['IS_NULLABLE'] !== 'NO' || $source['EXTRA'] !== '' || $source['GENERATION_EXPRESSION'] !== '') $this->refuse();
        $normalized = $columns['email_normalized'] ?? null;
        if ($normalized !== null && ($normalized['COLUMN_TYPE'] !== 'varchar(255)'
            || $normalized['CHARACTER_SET_NAME'] !== 'utf8mb4' || $normalized['COLLATION_NAME'] !== 'utf8mb4_0900_bin'
            || $normalized['IS_NULLABLE'] !== 'YES' || $normalized['COLUMN_DEFAULT'] !== null || $normalized['EXTRA'] !== 'STORED GENERATED'
            || !in_array($this->expression($normalized['GENERATION_EXPRESSION']), ['lower(trim(email)collateutf8mb4_0900_bin)','lcase(trim(email)collateutf8mb4_0900_bin)','lower((trim(email)collateutf8mb4_0900_bin))','lcase((trim(email)collateutf8mb4_0900_bin))'], true))) $this->refuse();
        $quoted = $this->db->quoteTableName($table);
        $indexes = $this->db->createCommand('SHOW INDEX FROM ' . $quoted)->queryAll();
        $index = array_values(array_filter($indexes, static fn($row)=>$row['Key_name'] === self::INDEX));
        if ($index !== []) {
            $row = $index[0];
            if (count($index) !== 1 || $normalized === null || (int)$row['Non_unique'] !== 0
                || (int)$row['Seq_in_index'] !== 1 || $row['Column_name'] !== 'email_normalized'
                || $row['Sub_part'] !== null || $row['Index_type'] !== 'BTREE' || $row['Visible'] !== 'YES'
                || $row['Collation'] !== 'A' || ($row['Expression'] ?? null) !== null) $this->refuse();
        }
        $constraints = $this->db->createCommand('SELECT t.CONSTRAINT_TYPE,t.ENFORCED,c.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS t LEFT JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME=:t AND t.CONSTRAINT_NAME=:c', $params + [':c'=>self::CHECK])->queryAll();
        if ($constraints !== []) {
            $check = $constraints[0];
            if (count($constraints) !== 1 || $normalized === null || $check['CONSTRAINT_TYPE'] !== 'CHECK' || $check['ENFORCED'] !== 'YES'
                || $this->expression((string)$check['CHECK_CLAUSE']) !== 'char_length(email_normalized)>0') $this->refuse();
        }
        // Counts only; collate BEFORE LOWER to avoid legacy Unicode case mappings.
        $invalid = $this->db->createCommand('SELECT COUNT(*) FROM ' . $quoted . ' WHERE email IS NULL OR CHAR_LENGTH(TRIM(email))=0')->queryScalar();
        $duplicates = $this->db->createCommand('SELECT COUNT(*) FROM (SELECT 1 FROM ' . $quoted . ' GROUP BY LOWER(TRIM(email) COLLATE utf8mb4_0900_bin) HAVING COUNT(*)>1) AS email_collisions')->queryScalar();
        if ((int)$invalid !== 0 || (int)$duplicates !== 0) $this->refuse();
        $parts = [];
        if ($normalized === null) $parts[] = 'ADD COLUMN `email_normalized` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin GENERATED ALWAYS AS (LOWER(TRIM(`email`) COLLATE utf8mb4_0900_bin)) STORED';
        if ($index === []) $parts[] = 'ADD UNIQUE INDEX `' . self::INDEX . '` (`email_normalized`)';
        if ($constraints === []) $parts[] = 'ADD CONSTRAINT `' . self::CHECK . '` CHECK (CHAR_LENGTH(`email_normalized`)>0) ENFORCED';
        // One InnoDB atomic DDL statement; racing writes are checked again by MySQL.
        if ($parts !== []) $this->db->createCommand('ALTER TABLE ' . $quoted . ' ' . implode(', ', $parts))->execute();
        $this->db->schema->refresh();
    }

    private function expression(string $sql): string
    {
        $sql = strtolower($sql);
        $sql = str_replace(['`email`', '`email_normalized`'], ['email', 'email_normalized'], $sql);
        if (preg_match('/[^a-z0-9_()<>\s]/', $sql)) return '';
        $sql = preg_replace('/\s+/', '', $sql);
        // Remove only whole-expression wrappers, never function/grouping parentheses.
        while (str_starts_with($sql, '(') && str_ends_with($sql, ')')) {
            $depth = 0;
            for ($i=0, $last=strlen($sql)-1; $i<$last; ++$i) {
                if ($sql[$i] === '(') ++$depth;
                elseif ($sql[$i] === ')') --$depth;
                if ($depth === 0) return $sql;
            }
            $sql = substr($sql, 1, -1);
        }
        return $sql;
    }

    private function refuse(): void { throw new \RuntimeException(self::FAILURE); }
}
