<?php

use yii\db\Migration;

/**
 * SIP-033 step 5: Bitcoin transactions of a received POS BTC payment (txid list, comma separated, at most 10), read from
 * the public BTCPay receipt of the invoice. Optional; never the invoice ID or remote IDs.
 */
class m261118_160000_add_pos_btc_payment_txids extends Migration
{
    private const TABLE = '{{%pos_btc_payments}}';

    public function up()
    {
        $schema = $this->db->schema->getTableSchema(self::TABLE, true);
        if ($schema === null) throw new RuntimeException('pos_btc_payments missing.');
        if (!isset($schema->columns['txids'])) $this->execute('ALTER TABLE ' . self::TABLE . ' ADD COLUMN `txids` VARCHAR(650) CHARACTER SET ascii COLLATE ascii_bin NULL');
        $checks = $this->db->createCommand('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND CONSTRAINT_TYPE=\'CHECK\'',
            [':t' => $this->db->schema->getRawTableName(self::TABLE)])->queryColumn();
        if (!in_array('ck-pos-btc-payments-txids', $checks, true)) {
            $this->execute('ALTER TABLE ' . self::TABLE . " ADD CONSTRAINT `ck-pos-btc-payments-txids` CHECK (txids IS NULL OR REGEXP_LIKE(txids, '^[0-9a-f]{64}(,[0-9a-f]{64}){0,9}$', 'c'))");
        }
        $this->db->schema->refresh();
    }

    public function down()
    {
        echo "POS BTC payment txids rollback requires an approved backup restore.\n";
        return false;
    }
}
