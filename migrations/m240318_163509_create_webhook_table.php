<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%webhook}}`.
 */
class m240318_163509_create_webhook_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%webhooks}}', [
            'id' => $this->primaryKey(),
            'store_id' => $this->integer()->notNull(),
            'bps_storeid' => $this->string(512)->notNull(),
            'webhookId' => $this->string(512)->notNull(),
            'url' => $this->string(512)->notNull()
        ]);

        $this->createIndex('{{%idx-webhooks-store_id}}', '{{%webhooks}}', 'store_id');
        $this->addForeignKey(
            '{{%fk-webhooks-store_id}}',
            '{{%webhooks}}',
            'store_id',
            '{{%stores}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
       
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropTable('{{%webhooks}}');
    }
}
