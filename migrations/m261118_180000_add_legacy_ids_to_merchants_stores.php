<?php

use yii\db\Migration;

/** SIP-030 step 5: v1 IDs of merchants and stores, technical key of the one-time import only (maps v1 POS and history, safe rerun); never used by the app. */
class m261118_180000_add_legacy_ids_to_merchants_stores extends Migration
{
    public function up()
    {
        foreach (['merchants', 'stores'] as $table) {
            $schema = $this->db->schema->getTableSchema('{{%' . $table . '}}', true);
            if ($schema === null) throw new RuntimeException($table . ' missing.');
            if (!isset($schema->columns['legacy_id'])) {
                $this->addColumn('{{%' . $table . '}}', 'legacy_id', $this->integer()->null());
                $this->createIndex('uq-' . $table . '-legacy_id', '{{%' . $table . '}}', 'legacy_id', true);
            }
        }
        $this->db->schema->refresh();
    }

    public function down()
    {
        echo "Legacy IDs rollback requires an approved backup restore.\n";
        return false;
    }
}
