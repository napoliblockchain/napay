<?php

use yii\db\Migration;

/**
 * SIP-022 passo 3: lavoro `store_update` (nome, valuta e impostazioni fisse dello store BTCPay del negozio), eseguito
 * dal worker con la chiave del merchant; il web non chiama più BTCPay per i negozi collegati dal provisioning.
 */
class m261117_100000_add_store_update_job_kind extends Migration
{
    public function safeUp()
    {
        $table = $this->db->quoteTableName($this->db->tablePrefix . 'btcpay_provisioning_jobs');
        $this->execute("ALTER TABLE $table DROP CHECK `ck-btcpay-jobs-kind`");
        $this->execute("ALTER TABLE $table ADD CONSTRAINT `ck-btcpay-jobs-kind` CHECK (kind IN ('merchant_account','store_link','pos_link','store_update','key_rotation','key_revoke'))");
    }

    public function safeDown()
    {
        echo "BTCPay job kind rollback requires an approved backup restore.\n";
        return false;
    }
}
