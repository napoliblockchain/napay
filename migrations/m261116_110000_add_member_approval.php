<?php

use yii\db\Migration;

/**
 * SIP-030 punto 1: iscrizione approvata dall'admin, come nel vecchio Napay. Dopo la verifica dell'email il socio
 * resta in attesa ("pending") e non entra finché un admin non approva; il rifiuto elimina l'account.
 * Profili esistenti (importati o già attivi) restano approvati.
 */
class m261116_110000_add_member_approval extends Migration
{
    public function safeUp()
    {
        $table = '{{%member_profiles}}';
        $this->addColumn($table, 'approval_state', $this->string(16)->notNull()->defaultValue('approved'));
        // registrazione (per l'ordine della lista e la scadenza della verifica email), approvazione e autore
        $this->addColumn($table, 'requested_at', $this->bigInteger()->null());
        $this->addColumn($table, 'approved_at', $this->bigInteger()->null());
        $this->addColumn($table, 'approved_by', $this->integer()->null());
        $this->createIndex('idx-member-profiles-approval', $table, ['approval_state', 'requested_at']);
        $this->execute('ALTER TABLE ' . $this->db->quoteTableName($this->db->tablePrefix . 'member_profiles')
            . " ADD CONSTRAINT `ck-member-profiles-approval` CHECK (approval_state IN ('pending','approved'))");
    }

    public function safeDown()
    {
        echo "Member approval rollback requires an approved backup restore.\n";
        return false;
    }
}
