<?php

use yii\db\Migration;

/**
 * SIP-033 step 5: exchange rate and BTC detail of POS BTC payments, read from the public BTCPay status.
 * rate_cents = fiat cents per 1 BTC used by BTCPay for the invoice; order_satoshis = order amount without the network fee
 * (amount_satoshis keeps the total requested in the QR); paid_satoshis = BTC received so far. All optional, integers only.
 */
class m261118_150000_add_pos_btc_payment_rates extends Migration
{
    private const TABLE = '{{%pos_btc_payments}}';

    public function up()
    {
        $schema = $this->db->schema->getTableSchema(self::TABLE, true);
        if ($schema === null) throw new RuntimeException('pos_btc_payments missing.');
        foreach (['rate_cents', 'order_satoshis', 'paid_satoshis'] as $column) {
            if (!isset($schema->columns[$column])) $this->execute('ALTER TABLE ' . self::TABLE . ' ADD COLUMN `' . $column . '` BIGINT NULL');
        }
        $checks = $this->db->createCommand('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND CONSTRAINT_TYPE=\'CHECK\'',
            [':t' => $this->db->schema->getRawTableName(self::TABLE)])->queryColumn();
        if (!in_array('ck-pos-btc-payments-rates', $checks, true)) {
            $this->execute('ALTER TABLE ' . self::TABLE . ' ADD CONSTRAINT `ck-pos-btc-payments-rates` CHECK ('
                . '(rate_cents IS NULL OR rate_cents BETWEEN 1 AND 10000000000000)'
                . ' AND (order_satoshis IS NULL OR (amount_satoshis IS NOT NULL AND order_satoshis BETWEEN 1 AND amount_satoshis))'
                . ' AND (paid_satoshis IS NULL OR paid_satoshis BETWEEN 0 AND 2100000000000000))');
        }
        $this->db->schema->refresh();
    }

    public function down()
    {
        echo "POS BTC payment rates rollback requires an approved backup restore.\n";
        return false;
    }
}
