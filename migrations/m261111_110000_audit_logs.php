<?php

use yii\db\Migration;

/**
 * Registro attività come in Aura (TIP-020): tipo di evento, utente facoltativo (azioni anonime senza id fittizio),
 * IP fino a 45 caratteri (IPv6). Solo aggiunte/allargamenti: i dati esistenti non cambiano.
 */
class m261111_110000_audit_logs extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%logs}}', 'event_type', $this->string(16)->notNull()->defaultValue('activity')->after('timestamp'));
        $this->alterColumn('{{%logs}}', 'user_id', $this->integer()->null());
        $this->alterColumn('{{%logs}}', 'remote_address', $this->string(45)->null());
        $this->alterColumn('{{%logs}}', 'browser', $this->string(256)->null());
        $this->createIndex('idx-logs-event_type-timestamp', '{{%logs}}', ['event_type', 'timestamp']);
    }

    public function safeDown()
    {
        echo "Audit log schema rollback requires an approved backup restore.\n";
        return false;
    }
}
