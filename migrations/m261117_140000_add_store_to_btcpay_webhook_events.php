<?php

use yii\db\Migration;

/**
 * SIP-022 passo 4: eventi dei webhook degli store dei negozi nella stessa tabella delle quote (stessa deduplica per
 * delivery), con il negozio di provenienza. NULL = webhook delle quote associative.
 */
class m261117_140000_add_store_to_btcpay_webhook_events extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%btcpay_webhook_events}}', 'store_id', $this->integer()->null());
        $this->createIndex('idx-btcpay-webhook-events-store', '{{%btcpay_webhook_events}}', ['store_id', 'received_at']);
    }

    public function safeDown()
    {
        echo "BTCPay webhook events rollback requires an approved backup restore.\n";
        return false;
    }
}
