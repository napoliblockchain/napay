<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%user_consensus}}`.
 */
class m240315_142026_create_user_consensus_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%user_consensus}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),
            'consenso_statuto' => $this->integer()->notNull()->defaultValue(0),
            'consenso_privacy' => $this->integer()->notNull()->defaultValue(0),
            'consenso_condizioni' => $this->integer()->notNull()->defaultValue(0),
            
            'consenso_condizioni_pos' => $this->integer()->defaultValue(null),
            'consenso_marketing' => $this->integer()->notNull()->defaultValue(0),
            
            'timestamp_statuto' => $this->integer()->notNull(),
            'timestamp_privacy' => $this->integer()->notNull(),
            'timestamp_condizioni' => $this->integer()->notNull(),
            
            'timestamp_condizioni_pos' => $this->integer()->defaultValue(null),
            'timestamp_marketing' => $this->integer()->notNull(),
        ]);


        $this->addForeignKey(
            'fk-user_consensus-user_id',
            'user_consensus',
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
        $this->dropTable('{{%user_consensus}}');
    }
}
