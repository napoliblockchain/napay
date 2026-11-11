<?php

use yii\db\Migration;

/**
 * Pagamento quota con PayPal Orders v2 (TIP-020 passo 5b): ID ordine PayPal, univoco.
 * paypal_txn_id resta l'ID della capture (come nel vecchio sistema).
 */
class m261112_100000_add_paypal_order_to_membership_payments extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%membership_payments}}', 'paypal_order_id', $this->string(36)->null()->unique()->after('paypal_txn_id'));
    }

    public function safeDown()
    {
        echo "Payment schema rollback requires an approved backup restore.\n";
        return false;
    }
}
