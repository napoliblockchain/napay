<?php

use yii\db\Migration;

/**
 * Associazione (TIP-020): cariche, quote, tipi di pagamento, profili soci, pagamenti quote, verbali.
 * legacy_id = chiave della vecchia app Yii1: import ripetibile senza doppioni (components/legacy/LegacyImporter).
 */
class m261111_100000_create_association_tables extends Migration
{
    public function safeUp()
    {
        $options = $this->db->driverName === 'mysql'
            ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB' : null;

        $this->createTable('{{%association_roles}}', [
            'id' => $this->primaryKey(),
            'legacy_id' => $this->integer()->null()->unique(),
            'description' => $this->string(50)->notNull(),
        ], $options);

        $this->createTable('{{%membership_fees}}', [
            'id' => $this->primaryKey(),
            'legacy_id' => $this->integer()->null()->unique(),
            'description' => $this->string(50)->notNull(),
            'duration' => $this->integer()->notNull()->defaultValue(0),
            'amount' => $this->decimal(10, 2)->notNull()->defaultValue(0),
        ], $options);

        $this->createTable('{{%payment_methods}}', [
            'id' => $this->primaryKey(),
            'legacy_id' => $this->integer()->null()->unique(),
            'description' => $this->string(50)->notNull(),
            // livello minimo del privilegio per registrare a mano un pagamento con questo metodo
            'min_level' => $this->integer()->notNull()->defaultValue(0),
        ], $options);

        $this->createTable('{{%member_profiles}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull()->unique(),
            'legacy_user_id' => $this->integer()->null()->unique(),
            'legacy_user_type' => $this->integer()->null(),
            // 1 = utente creato dall'import (aggiornato dal legacy), 0 = utente v2 preesistente solo collegato
            'user_imported' => $this->tinyInteger()->notNull()->defaultValue(0),
            'association_role_id' => $this->integer()->null(),
            'is_corporate' => $this->tinyInteger()->notNull()->defaultValue(0),
            'denomination' => $this->string(250)->null(),
            'vat' => $this->string(250)->null(),
            'address' => $this->string(250)->null(),
            'cap' => $this->string(250)->null(),
            'city' => $this->string(250)->null(),
            'country' => $this->string(250)->null(),
        ], $options);
        $this->addForeignKey('fk-member_profiles-user_id', '{{%member_profiles}}', 'user_id', '{{%users}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk-member_profiles-association_role_id', '{{%member_profiles}}', 'association_role_id', '{{%association_roles}}', 'id', 'SET NULL', 'CASCADE');

        $this->createTable('{{%membership_payments}}', [
            'id' => $this->primaryKey(),
            'legacy_id' => $this->integer()->null()->unique(),
            'user_id' => $this->integer()->notNull(),
            'fee_id' => $this->integer()->notNull(),
            'payment_method_id' => $this->integer()->notNull(),
            'year' => $this->integer()->null(),
            'receipt_number' => $this->integer()->null(),
            'amount' => $this->decimal(10, 2)->notNull(),
            'registered_on' => $this->date()->notNull(),
            'starts_on' => $this->date()->notNull(),
            'expires_on' => $this->date()->notNull(),
            'btcpay_invoice_id' => $this->string(60)->null(),
            'paypal_txn_id' => $this->string(60)->null(),
            // paid | pending | expired | invalid (altri stati legacy conservati così come sono)
            'status' => $this->string(32)->notNull(),
        ], $options);
        $this->createIndex('idx-membership_payments-user_id-expires_on', '{{%membership_payments}}', ['user_id', 'expires_on']);
        $this->addForeignKey('fk-membership_payments-user_id', '{{%membership_payments}}', 'user_id', '{{%users}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk-membership_payments-fee_id', '{{%membership_payments}}', 'fee_id', '{{%membership_fees}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk-membership_payments-payment_method_id', '{{%membership_payments}}', 'payment_method_id', '{{%payment_methods}}', 'id', 'RESTRICT', 'CASCADE');

        $this->createTable('{{%assembly_minutes}}', [
            'id' => $this->primaryKey(),
            'legacy_id' => $this->integer()->null()->unique(),
            'meeting_date' => $this->date()->notNull(),
            'description' => $this->string(500)->notNull(),
            'file_name' => $this->string(250)->null(),
            'file_sha256' => $this->char(64)->null(),
            'file_content' => $this->getDb()->driverName === 'mysql' ? 'LONGBLOB NULL' : $this->binary()->null(),
        ], $options);
    }

    public function safeDown()
    {
        echo "Association schema rollback requires an approved backup restore.\n";
        return false;
    }
}
