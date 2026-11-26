<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%merchants}}`.
 */
class m240310_142112_create_merchants_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%merchants}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),
            'description' => $this->string(256)->notNull(),
            'email' => $this->string(256)->notNull(),
            'vatNumber' => $this->string(16)->notNull(),
            'phone' => $this->string(30)->notNull(),
            'mobile' => $this->string(30)->notNull(),
            'addressStreet' => $this->string(512)->notNull(),
            'addressNumberHouse' => $this->string(20)->notNull(),
            'addressCity' => $this->string(256)->notNull(),
            'addressZip' => $this->string(20)->notNull(),
            'addressProvince' => $this->string(10)->notNull(),
            'addressCountry' => $this->string(10)->notNull(),
        ]);

        $this->addForeignKey(
            'fk-merchants-user_id',
            'merchants',
            'user_id', 
            'users', 
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
        $this->dropTable('{{%merchants}}');
    }
}
