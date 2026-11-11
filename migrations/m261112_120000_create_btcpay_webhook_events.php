<?php

use yii\db\Migration;

/**
 * Webhook BTCPay (TIP-020 passo 5c): ogni consegna elaborata una sola volta (ID consegna originale univoco).
 * Indice sull'ID fattura dei pagamenti quota per i webhook. Nessun payload salvato.
 */
class m261112_120000_create_btcpay_webhook_events extends Migration
{
    public function safeUp()
    {
        $options = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin ENGINE=InnoDB' : null;
        $this->createTable('{{%btcpay_webhook_events}}', [
            'id' => $this->primaryKey(),
            'delivery_id' => $this->string(64)->notNull()->unique(),
            'event_type' => $this->string(64)->notNull(),
            'invoice_id' => $this->string(64)->null(),
            'received_at' => $this->integer()->notNull(),
            'processed_at' => $this->integer()->null(),
            'result' => $this->string(32)->null(),
        ], $options);
        $this->createIndex('idx-membership_payments-btcpay_invoice_id', '{{%membership_payments}}', 'btcpay_invoice_id');
    }

    public function safeDown()
    {
        echo "Webhook schema rollback requires an approved backup restore.\n";
        return false;
    }
}
