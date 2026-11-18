<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%settings}}`.
 */
class m240318_163521_create_settings_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%settings}}', [
            'id' => $this->primaryKey(),
            'description' => $this->string(256)->notNull(),
            'code' => $this->string(256)->notNull(),
            'value' => $this->string(2048)
        ]);
        
        $this->insert('settings', [
            'description' => 'Btcpayserver API Key',
            'code' => 'btcpayApiKey',
            'value' => '',
        ]);

        $this->insert('settings', [
            'description' => 'Btcpayserver host url',
            'code' => 'btcpayHost',
            'value' => '',
        ]);

        // Aggiorna settings con un secret
        $this->insert('settings', [
            'description' => 'Btcpayserver WebHook secret for callback',
            'code' => 'webhookSecret',
            'value' => \Yii::$app->security->generateRandomString(),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropTable('{{%settings}}');
    }
}
