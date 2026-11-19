<?php

use yii\db\Migration;

/**
 * SIP-030 step 5b: v1 POS (np_pos) imported as v2 tills. Technical key of the one-time import only (safe rerun, links the
 * v1 history in step 5c); never used by the app. Separate table: pos_terminals keeps its verified C1 schema.
 */
class m261119_110000_create_legacy_pos_terminals extends Migration
{
    public function up()
    {
        if ($this->db->schema->getTableSchema('{{%legacy_pos_terminals}}', true) === null) {
            $this->execute('CREATE TABLE {{%legacy_pos_terminals}} ('
                . 'legacy_id INT NOT NULL,'
                . 'terminal_id INT NOT NULL,'
                . 'PRIMARY KEY (legacy_id),'
                . 'UNIQUE KEY `uq-legacy-pos-terminals-terminal` (terminal_id),'
                . 'CONSTRAINT `fk-legacy-pos-terminals-terminal` FOREIGN KEY (terminal_id) REFERENCES {{%pos_terminals}} (id),'
                . 'CONSTRAINT `ck-legacy-pos-terminals-id` CHECK (legacy_id >= 1)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin');
        }
        $this->db->schema->refresh();
    }

    public function down()
    {
        echo "Legacy POS terminals rollback requires an approved backup restore.\n";
        return false;
    }
}
