<?php

use yii\db\Migration;

/**
 * Sessioni operatore POS (SIP-021): token opaco conservato solo come SHA-256, scadenza e revoca.
 * Legate al client POS (prefisso del digest della chiave) e all'impronta della password.
 */
class m261111_120000_create_pos_operator_sessions extends Migration
{
    public function safeUp()
    {
        $options = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin ENGINE=InnoDB' : null;
        $this->createTable('{{%pos_operator_sessions}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),
            'token_sha256' => $this->char(64)->notNull()->unique(),
            'client_key_prefix' => $this->char(16)->notNull(),
            'credential_fingerprint' => $this->char(64)->notNull(),
            'created_at' => $this->integer()->notNull(),
            'expires_at' => $this->integer()->notNull(),
            'last_used_at' => $this->integer()->null(),
            'revoked_at' => $this->integer()->null(),
            'revoked_reason' => $this->string(32)->null(),
            'created_ip' => $this->string(45)->null(),
        ], $options);
        $this->createIndex('idx-pos_operator_sessions-user_id', '{{%pos_operator_sessions}}', 'user_id');
        $this->addForeignKey('fk-pos_operator_sessions-user_id', '{{%pos_operator_sessions}}', 'user_id', '{{%users}}', 'id', 'CASCADE', 'CASCADE');
    }

    public function safeDown()
    {
        echo "POS session schema rollback requires an approved backup restore.\n";
        return false;
    }
}
