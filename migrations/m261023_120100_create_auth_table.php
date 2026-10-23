<?php

use yii\db\Migration;

class m261023_120100_create_auth_table extends Migration
{
    public function safeUp()
    {
        $options = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin ENGINE=InnoDB' : null;
        $this->createTable('{{%auth}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),
            'source' => $this->string(20)->notNull(),
            'source_id' => $this->string(128)->notNull(),
        ], $options);
        // Opaque provider IDs: case and trailing spaces must remain significant.
        $this->createIndex('uq-auth-source-source_id', '{{%auth}}', ['source', 'source_id'], true);
        $this->createIndex('idx-auth-user_id', '{{%auth}}', 'user_id');
        $this->addForeignKey('fk-auth-user_id', '{{%auth}}', 'user_id', '{{%users}}', 'id', 'CASCADE', 'CASCADE');
    }

    public function safeDown()
    {
        echo "Identity schema rollback requires an approved backup restore.\n";
        return false;
    }
}
