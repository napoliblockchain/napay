<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%privileges}}`.
 */
class m231113_093319_create_privileges_table extends Migration
{
    /**
     * ### Ruoli utente dal README
     * 
     * 1 - Administrator    ROLE_ADMIN       50  => Full control su applicazione
     * 2 - User             ROLE_USER         0  => Full control sui dati di tutti gli esercenti
     * 3 - Merchant         ROLE_MERCHANT    30  => Visualizza tutti i propri negozi/pos/invoices
     */

    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%privileges}}', [
            'id' => $this->primaryKey(),
            'description' => $this->string(50)->notNull(),
            'level' => $this->integer()->notNull(),
            'codice_ruolo' => $this->string(50)->notNull(),
        ]);

        $this->insert('privileges', [
            'description' => 'Administrator',
            'level' => 50,
            'codice_ruolo' => 'ROLE_ADMIN'
        ]);

        $this->insert('privileges', [
            'description' => 'User',
            'level' => 0,
            'codice_ruolo' => 'ROLE_USER'
        ]);

        $this->insert('privileges', [
            'description' => 'Merchant',
            'level' => 30,
            'codice_ruolo' => 'ROLE_MERCHANT'
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropTable('{{%privileges}}');
    }
}
