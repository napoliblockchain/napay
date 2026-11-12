<?php

use yii\db\Migration;

/** SIP-021 C1: additive POS schema. No users, stores or grants are provisioned. */
class m261112_130000_create_pos_catalog extends Migration
{
    private const STORE_INDEX = 'uq-pos-stores-id-merchant';

    public function up()
    {
        if ($this->db->driverName !== 'mysql' || !preg_match('/^8\.4\./', $this->db->getServerVersion())) {
            throw new RuntimeException('POS catalog requires MySQL 8.4.');
        }
        foreach (['users'=>['id'], 'merchants'=>['id'], 'stores'=>['id','merchant_id']] as $table=>$columns) {
            $this->verifyEngine($table, false);
            $schema = $this->db->schema->getTableSchema($this->table($table), true);
            foreach ($columns as $column) {
                $c = $schema->columns[$column] ?? null;
                if (!$c || $c->dbType !== 'int' || $c->unsigned || $c->allowNull) $this->incompatible();
            }
        }
        // Preflight every existing object before any DDL; resume only exact definitions.
        $definitions = $this->definitions();
        foreach ($definitions as $name=>$definition) {
            if ($this->db->schema->getTableSchema($this->table($name), true) !== null) $this->verifyTable($name, $definition);
        }
        $indexes = $this->indexes('stores', false);
        $wanted = ['unique'=>true, 'columns'=>['id','merchant_id']];
        if (isset($indexes[self::STORE_INDEX]) && $indexes[self::STORE_INDEX] !== $wanted) $this->incompatible();
        if (!in_array($wanted, $indexes, true)) $this->createIndex(self::STORE_INDEX, $this->table('stores'), ['id','merchant_id'], true);
        foreach ($definitions as $name=>$definition) {
            if ($this->db->schema->getTableSchema($this->table($name), true) === null) {
                $parts = [];
                foreach ($definition['columns'] as $column=>$sql) $parts[] = $this->db->quoteColumnName($column) . ' ' . $sql;
                foreach ($definition['indexes'] as $index=>$spec) {
                    $parts[] = ($index === 'PRIMARY' ? 'PRIMARY KEY' : ($spec['unique'] ? 'UNIQUE KEY ' : 'KEY ') . $this->db->quoteColumnName($index)) . ' (' . $this->columnList($spec['columns']) . ')';
                }
                foreach ($definition['foreign'] as $constraint=>$fk) {
                    $parts[] = 'CONSTRAINT ' . $this->db->quoteColumnName($constraint) . ' FOREIGN KEY (' . $this->columnList($fk[0]) . ') REFERENCES ' . $this->db->quoteTableName($this->table($fk[1])) . ' (' . $this->columnList($fk[2]) . ') ON DELETE RESTRICT ON UPDATE RESTRICT';
                }
                foreach ($definition['checks'] as $constraint=>$expression) $parts[] = 'CONSTRAINT ' . $this->db->quoteColumnName($constraint) . ' CHECK (' . $expression . ')';
                $this->execute('CREATE TABLE ' . $this->db->quoteTableName($this->table($name)) . ' (' . implode(', ', $parts) . ') ENGINE=InnoDB CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_bin');
            }
            $this->verifyTable($name, $definition);
        }
        $this->db->schema->refresh();
    }

    public function down()
    {
        echo "POS catalog rollback requires an approved backup restore.\n";
        return false;
    }

    private function definitions(): array
    {
        $common = ['is_enabled'=>'TINYINT NOT NULL DEFAULT 0', 'created_at'=>'BIGINT NOT NULL', 'updated_at'=>'BIGINT NOT NULL'];
        $specs = [
            'pos_operators'=>[
                'columns'=>['user_id'=>'INT NOT NULL', 'merchant_id'=>'INT NOT NULL'] + $common,
                'indexes'=>['PRIMARY'=>[true,['user_id']], 'uq-pos-operators-merchant'=>[true,['user_id','merchant_id']], 'idx-pos-operators-merchant'=>[false,['merchant_id']]],
                'foreign'=>['fk-pos-operators-user'=>[['user_id'],'users',['id']], 'fk-pos-operators-merchant'=>[['merchant_id'],'merchants',['id']]],
            ],
            'pos_terminals'=>[
                'columns'=>['id'=>'INT NOT NULL AUTO_INCREMENT', 'public_id'=>'CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL', 'merchant_id'=>'INT NOT NULL', 'store_id'=>'INT NOT NULL', 'name'=>'VARCHAR(256) NOT NULL'] + $common,
                'indexes'=>['PRIMARY'=>[true,['id']], 'uq-pos-terminals-public'=>[true,['public_id']], 'idx-pos-terminals-store'=>[false,['store_id','merchant_id']], 'idx-pos-terminals-enabled'=>[false,['store_id','is_enabled','id']]],
                'foreign'=>['fk-pos-terminals-store'=>[['store_id','merchant_id'],'stores',['id','merchant_id']]],
            ],
            'pos_store_operators'=>[
                'columns'=>['user_id'=>'INT NOT NULL', 'merchant_id'=>'INT NOT NULL', 'store_id'=>'INT NOT NULL'] + $common,
                'indexes'=>['PRIMARY'=>[true,['user_id','store_id']], 'idx-pos-grants-operator'=>[false,['user_id','merchant_id']], 'idx-pos-grants-store'=>[false,['store_id','merchant_id']]],
                'foreign'=>['fk-pos-grants-operator'=>[['user_id','merchant_id'],'pos_operators',['user_id','merchant_id']], 'fk-pos-grants-store'=>[['store_id','merchant_id'],'stores',['id','merchant_id']]],
            ],
        ];
        foreach ($specs as $name=>&$spec) {
            foreach ($spec['indexes'] as &$index) $index = ['unique'=>$index[0], 'columns'=>$index[1]];
            unset($index);
            $short = ['pos_operators'=>'operators','pos_terminals'=>'terminals','pos_store_operators'=>'grants'][$name];
            $spec['checks'] = ['ck-pos-'.$short.'-enabled'=>'is_enabled IN (0,1)', 'ck-pos-'.$short.'-time'=>'created_at >= 0 AND updated_at >= created_at'];
            if ($name === 'pos_terminals') $spec['checks'] += ['ck-pos-terminals-uuid'=>'CHAR_LENGTH(public_id) = 36', 'ck-pos-terminals-name'=>'CHAR_LENGTH(TRIM(name)) > 0'];
        }
        unset($spec);
        return $specs;
    }

    private function verifyTable(string $name, array $definition): void
    {
        $this->verifyEngine($name, true);
        $columns = $this->db->createCommand('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t ORDER BY ORDINAL_POSITION', [':t'=>$this->table($name)])->queryAll();
        if (array_column($columns, 'COLUMN_NAME') !== array_keys($definition['columns'])) $this->incompatible();
        foreach ($columns as $column) {
            $sql = $definition['columns'][$column['COLUMN_NAME']];
            preg_match('/^[A-Z]+(?:\(\d+\))?/', $sql, $m);
            $collation = str_starts_with($sql, 'CHAR') ? 'ascii_bin' : (str_starts_with($sql, 'VARCHAR') ? 'utf8mb4_0900_bin' : null);
            if ($column['COLUMN_TYPE'] !== strtolower($m[0]) || $column['IS_NULLABLE'] !== 'NO'
                || $column['COLUMN_DEFAULT'] !== (str_contains($sql, 'DEFAULT 0') ? '0' : null)
                || $column['EXTRA'] !== (str_contains($sql, 'AUTO_INCREMENT') ? 'auto_increment' : '')
                || $column['COLLATION_NAME'] !== $collation) $this->incompatible();
        }
        $actual = $this->indexes($name); $expected = $definition['indexes'];
        ksort($actual); ksort($expected);
        if ($actual !== $expected) $this->incompatible();
        $database = $this->db->createCommand('SELECT DATABASE()')->queryScalar();
        $rows = $this->db->createCommand('SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=:t ORDER BY k.ORDINAL_POSITION', [':t'=>$this->table($name)])->queryAll();
        $foreign = [];
        foreach ($rows as $row) {
            if ($row['REFERENCED_TABLE_SCHEMA'] !== $database || $row['UPDATE_RULE'] !== 'RESTRICT' || $row['DELETE_RULE'] !== 'RESTRICT') $this->incompatible();
            $key = $row['CONSTRAINT_NAME'];
            if (!isset($foreign[$key])) $foreign[$key] = [[], $row['REFERENCED_TABLE_NAME'], []];
            $foreign[$key][0][] = $row['COLUMN_NAME']; $foreign[$key][2][] = $row['REFERENCED_COLUMN_NAME'];
        }
        $expectedForeign = $definition['foreign'];
        foreach ($expectedForeign as &$fk) $fk[1] = $this->table($fk[1]);
        unset($fk); ksort($foreign); ksort($expectedForeign);
        if ($foreign !== $expectedForeign) $this->incompatible();
        $rows = $this->db->createCommand("SELECT t.CONSTRAINT_NAME,c.CHECK_CLAUSE,t.ENFORCED FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME=:t AND t.CONSTRAINT_TYPE='CHECK'", [':t'=>$this->table($name)])->queryAll();
        $checks = [];
        foreach ($rows as $row) { if ($row['ENFORCED'] !== 'YES') $this->incompatible(); $checks[$row['CONSTRAINT_NAME']] = $this->normalize($row['CHECK_CLAUSE']); }
        $expectedChecks = array_map([$this, 'normalize'], $definition['checks']);
        ksort($checks); ksort($expectedChecks);
        if ($checks !== $expectedChecks) $this->incompatible();
    }

    private function verifyEngine(string $name, bool $collation): void
    {
        $row = $this->db->createCommand('SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t', [':t'=>$this->table($name)])->queryOne();
        if (!$row || $row['ENGINE'] !== 'InnoDB' || ($collation && $row['TABLE_COLLATION'] !== 'utf8mb4_0900_bin')) $this->incompatible();
    }

    private function indexes(string $name, bool $strict = true): array
    {
        $result = [];
        foreach ($this->db->createCommand('SHOW INDEX FROM ' . $this->db->quoteTableName($this->table($name)))->queryAll() as $row) {
            if ($row['Sub_part'] !== null || $row['Index_type'] !== 'BTREE' || $row['Visible'] !== 'YES') {
                if ($strict || $row['Key_name'] === self::STORE_INDEX) $this->incompatible();
                continue;
            }
            $result[$row['Key_name']]['unique'] = !(bool)$row['Non_unique'];
            $result[$row['Key_name']]['columns'][(int)$row['Seq_in_index']] = $row['Column_name'];
        }
        foreach ($result as &$index) { ksort($index['columns']); $index['columns'] = array_values($index['columns']); }
        return $result;
    }

    private function table(string $name): string { return $this->db->tablePrefix . $name; }
    private function columnList(array $columns): string { return implode(', ', array_map([$this->db, 'quoteColumnName'], $columns)); }
    private function normalize(string $sql): string
    {
        $sql = strtolower(preg_replace('/[\s`]+/', '', $sql));
        // MySQL wraps CHECK clauses/comparisons. Never discard function/grouping parentheses.
        $sql = preg_replace('/\((created_at>=0|updated_at>=created_at)\)/', '$1', $sql);
        while (str_starts_with($sql, '(') && str_ends_with($sql, ')')) {
            $depth = 0;
            for ($i = 0, $last = strlen($sql)-1; $i < $last; ++$i) {
                if ($sql[$i] === '(') ++$depth;
                if ($sql[$i] === ')') --$depth;
                if ($depth === 0) return $sql;
            }
            $sql = substr($sql, 1, -1);
        }
        return $sql;
    }
    private function incompatible(): void { throw new RuntimeException('POS catalog schema is incompatible; no automatic repair permitted.'); }
}
