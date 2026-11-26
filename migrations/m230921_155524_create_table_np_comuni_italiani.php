<?php

use yii\db\Migration;

class m230921_155524_create_table_np_comuni_italiani extends Migration
{
    public function safeUp()
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }

        $this->createTable(
            '{{%np_comuni_italiani}}',
            [
                'id' => $this->primaryKey(),
                'citta' => $this->string(200)->notNull(),
                'provincia' => $this->string(50)->notNull(),
                'sigla' => $this->string(2)->notNull(),
            ],
            $tableOptions
        );
    }

    public function safeDown()
    {
        $this->dropTable('{{%np_comuni_italiani}}');
    }
}
