<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%users}}`.
 */
class m231113_093348_create_users_table extends Migration
{
    public function safeUp()
    {
        // Earlier v2 releases recorded this migration under a different name.
        // Recognize only that verified schema; never recreate restored users.
        if ($this->db->schema->getTableSchema('{{%users}}', true) !== null) {
            \app\components\db\V2UsersSchema::assertHistoricalAlias($this->db);
            return;
        }

        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }

        $this->createTable(
            '{{%users}}',
            [
                'id' => $this->primaryKey(),
                'username' => $this->string()->notNull(),
                'email' => $this->string()->notNull(),
                'password' => $this->string()->notNull(),
                'first_name' => $this->string(256)->notNull(),
                'last_name' => $this->string(256)->notNull(),
                'oauth_provider' => $this->string(20)->notNull(),
                'oauth_uid' => $this->string(128)->notNull(),
                'authKey' => $this->string(256)->notNull(),
                'accessToken' => $this->string(2048)->notNull(),
                'activationCode' => $this->string(256)->defaultValue(NULL),
                'picture' => $this->string(512)->notNull(),
                'privilege_id' => $this->integer()->notNull(),
                'is_merchant' => $this->integer()->defaultValue(0),
                'is_active' => $this->integer()->notNull(),
            ],
            $tableOptions
        );

        $this->addForeignKey(
            'fk-user-privilege_id', 
            'users', 
            'privilege_id', 
            'privileges', 
            'id', 
            'CASCADE', 
            'CASCADE'
        );

    }

    public function safeDown()
    {
        $this->dropTable('{{%users}}');
    }
}
