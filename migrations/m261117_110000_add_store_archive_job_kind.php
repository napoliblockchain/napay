<?php

use yii\db\Migration;

/**
 * SIP-022 passo 3b: lavoro `store_archive` (negozio eliminato in Napay): il worker rimuove le app POS dello store BTCPay,
 * lo rinomina come archiviato e chiude i collegamenti. Lo store non viene cancellato (resta lo storico delle fatture).
 */
class m261117_110000_add_store_archive_job_kind extends Migration
{
    public function safeUp()
    {
        $table = $this->db->quoteTableName($this->db->tablePrefix . 'btcpay_provisioning_jobs');
        $this->execute("ALTER TABLE $table DROP CHECK `ck-btcpay-jobs-kind`");
        $this->execute("ALTER TABLE $table ADD CONSTRAINT `ck-btcpay-jobs-kind` CHECK (kind IN ('merchant_account','store_link','pos_link','store_update','store_archive','key_rotation','key_revoke'))");
    }

    public function safeDown()
    {
        echo "BTCPay job kind rollback requires an approved backup restore.\n";
        return false;
    }
}
