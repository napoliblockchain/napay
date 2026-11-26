<?php

use yii\db\Migration;

/**
 * Webhook PayPal (TIP-020 passo 5b-bis): ogni evento elaborato una sola volta (ID evento univoco).
 * Nessun payload salvato: solo tipo, risorsa ed esito.
 */
class m261112_110000_create_paypal_webhook_events extends Migration
{
    public function safeUp()
    {
        $options = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin ENGINE=InnoDB' : null;
        $this->createTable('{{%paypal_webhook_events}}', [
            'id' => $this->primaryKey(),
            'event_id' => $this->string(64)->notNull()->unique(),
            'event_type' => $this->string(64)->notNull(),
            'resource_id' => $this->string(64)->null(),
            'received_at' => $this->integer()->notNull(),
            'processed_at' => $this->integer()->null(),
            'result' => $this->string(32)->null(),
        ], $options);
    }

    public function safeDown()
    {
        echo "Webhook schema rollback requires an approved backup restore.\n";
        return false;
    }
}
