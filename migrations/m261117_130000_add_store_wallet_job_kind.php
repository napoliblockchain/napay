<?php

use yii\db\Migration;

/**
 * SIP-022 xpub (decisione A): lavoro `store_wallet`, il worker imposta o rimuove sullo store BTCPay del negozio il metodo
 * di pagamento on-chain con la xpub fornita dal merchant (chiave del merchant). Napay non custodisce chiavi private.
 */
class m261117_130000_add_store_wallet_job_kind extends Migration
{
    public function safeUp()
    {
        $table = $this->db->quoteTableName($this->db->tablePrefix . 'btcpay_provisioning_jobs');
        $this->execute("ALTER TABLE $table DROP CHECK `ck-btcpay-jobs-kind`");
        $this->execute("ALTER TABLE $table ADD CONSTRAINT `ck-btcpay-jobs-kind` CHECK (kind IN ('merchant_account','store_link','pos_link','store_update','store_archive','store_wallet','key_rotation','key_revoke'))");
    }

    public function safeDown()
    {
        echo "BTCPay job kind rollback requires an approved backup restore.\n";
        return false;
    }
}
