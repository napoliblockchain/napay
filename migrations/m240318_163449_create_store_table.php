<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%store}}`.
 */
class m240318_163449_create_store_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%stores}}', [
            'id' => $this->primaryKey(),
            'merchant_id' => $this->integer()->notNull(),
            'description' => $this->string(256)->notNull(),
            'email' => $this->string(256)->notNull(),
            'phone' => $this->string(30)->notNull(),
            'mobile' => $this->string(30)->notNull(),
            'addressStreet' => $this->string(512)->notNull(),
            'addressNumberHouse' => $this->string(20)->notNull(),
            'addressCity' => $this->string(256)->notNull(),
            'addressZip' => $this->string(20)->notNull(),
            'addressProvince' => $this->string(10)->notNull(),
            'addressCountry' => $this->string(10)->notNull(),

        ]);

        $this->createIndex('{{%idx-stores-merchant_id}}', '{{%stores}}', 'merchant_id');

        $this->addForeignKey(
            '{{%fk-stores-merchant_id}}',
            '{{%stores}}',
            'merchant_id',
            '{{%merchants}}',
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
        $this->dropTable('{{%store}}');
    }
}
