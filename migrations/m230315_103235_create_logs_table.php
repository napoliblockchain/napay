<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%logs}}`.
 */
class m230315_103235_create_logs_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%logs}}', [
            'id' => $this->primaryKey(),
            'timestamp' => $this->integer()->notNull(),
            'user_id' => $this->integer()->notNull(),
            'remote_address' => $this->string(20)->notNull(),
            'browser' => $this->string(256)->notNull(),
            'controller' => $this->string(60)->notNull(),
            'action' => $this->string(60)->notNull(),
            'description' => $this->text()->notNull(),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropTable('{{%logs}}');
    }
}
