<?php

use yii\db\Migration;

/**
 * SIP-030 punto 2: promemoria della quota. Registro degli invii per socio e per scadenza della quota (il conteggio
 * riparte dopo il rinnovo), primo automatico e successivi manuali; il socio può disattivare i promemoria.
 */
class m261116_130000_create_membership_reminders extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%membership_reminders}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),
            'expires_on' => $this->date()->notNull(),
            'kind' => $this->string(8)->notNull(),
            'sent_at' => $this->bigInteger()->notNull(),
            'sent_by' => $this->integer()->null(),
        ]);
        $this->createIndex('idx-membership-reminders-period', '{{%membership_reminders}}', ['user_id', 'expires_on', 'kind']);
        $this->addForeignKey('fk-membership-reminders-user', '{{%membership_reminders}}', 'user_id', '{{%users}}', 'id', 'CASCADE', 'RESTRICT');
        $this->execute('ALTER TABLE ' . $this->db->quoteTableName($this->db->tablePrefix . 'membership_reminders')
            . " ADD CONSTRAINT `ck-membership-reminders-kind` CHECK (kind IN ('auto','manual'))");
        $this->addColumn('{{%member_profiles}}', 'reminders_opt_out', $this->tinyInteger(1)->notNull()->defaultValue(0));
    }

    public function safeDown()
    {
        echo "Membership reminders rollback requires an approved backup restore.\n";
        return false;
    }
}
