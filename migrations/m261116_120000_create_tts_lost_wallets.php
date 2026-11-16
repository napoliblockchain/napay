<?php

use yii\db\Migration;

/**
 * SIP-031: indirizzi TTS segnalati come persi (seed perso) per merchant. Una segnalazione non si annulla:
 * se il seed viene ritrovato e l'indirizzo salvato di nuovo, la riga resta e riceve data e autore del ritrovamento.
 * Gli incassi pagati su un indirizzo perso e non ritrovato sono contabilizzati come fondi persi.
 */
class m261116_120000_create_tts_lost_wallets extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%tts_lost_wallets}}', [
            'id' => $this->primaryKey(),
            'merchant_id' => $this->integer()->notNull(),
            'store_id' => $this->integer()->notNull(),
            'address' => 'CHAR(42) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
            'reason' => $this->string(1000)->notNull(),
            'reported_by' => $this->integer()->notNull(),
            'reported_at' => $this->bigInteger()->notNull(),
            'recovered_by' => $this->integer()->null(),
            'recovered_at' => $this->bigInteger()->null(),
        ], 'ENGINE=InnoDB CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_bin');
        $this->createIndex('idx-tts-lost-wallets-address', '{{%tts_lost_wallets}}', ['merchant_id', 'address', 'recovered_at']);
        $this->addForeignKey('fk-tts-lost-wallets-merchant', '{{%tts_lost_wallets}}', 'merchant_id', '{{%merchants}}', 'id', 'RESTRICT', 'RESTRICT');
        $this->addForeignKey('fk-tts-lost-wallets-store', '{{%tts_lost_wallets}}', 'store_id', '{{%stores}}', 'id', 'RESTRICT', 'RESTRICT');
        $this->addForeignKey('fk-tts-lost-wallets-reporter', '{{%tts_lost_wallets}}', 'reported_by', '{{%users}}', 'id', 'RESTRICT', 'RESTRICT');
        $this->addForeignKey('fk-tts-lost-wallets-recoverer', '{{%tts_lost_wallets}}', 'recovered_by', '{{%users}}', 'id', 'RESTRICT', 'RESTRICT');
        $this->execute('ALTER TABLE ' . $this->db->quoteTableName($this->db->tablePrefix . 'tts_lost_wallets')
            . " ADD CONSTRAINT `ck-tts-lost-wallets-address` CHECK (REGEXP_LIKE(address, '^0x[0-9a-fA-F]{40}$', 'c')),"
            . " ADD CONSTRAINT `ck-tts-lost-wallets-recovered` CHECK ((recovered_at IS NULL) = (recovered_by IS NULL))");
    }

    public function safeDown()
    {
        echo "Lost wallets rollback requires an approved backup restore.\n";
        return false;
    }
}
