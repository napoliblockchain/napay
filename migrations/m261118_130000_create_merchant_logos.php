<?php

use yii\db\Migration;

/**
 * SIP-033: logo of the merchant shown on the BTCPay payment page of all its stores. One logo per merchant, kept in the
 * database like the other uploaded files (no volume): PNG re-encoded by Napay, addressed by its SHA-256 (public URL).
 */
class m261118_130000_create_merchant_logos extends Migration
{
    public function safeUp()
    {
        if ($this->db->schema->getTableSchema('{{%merchant_logos}}', true) !== null) return;
        $this->createTable('{{%merchant_logos}}', [
            'merchant_id' => $this->integer()->notNull(),
            'sha256' => 'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
            'content' => 'MEDIUMBLOB NOT NULL',
            'width' => $this->smallInteger()->unsigned()->notNull(),
            'height' => $this->smallInteger()->unsigned()->notNull(),
            'updated_by' => $this->integer()->notNull(),
            'updated_at' => $this->bigInteger()->notNull(),
            'PRIMARY KEY (merchant_id)',
            // stesso file per due merchant = stesso indirizzo: indice non univoco
            'KEY `idx-merchant_logos-sha256` (sha256)',
            'CONSTRAINT `ck-merchant_logos-sha256` CHECK (sha256 REGEXP \'^[0-9a-f]{64}$\')',
            'CONSTRAINT `ck-merchant_logos-size` CHECK (width BETWEEN 1 AND 600 AND height BETWEEN 1 AND 200 AND OCTET_LENGTH(content) BETWEEN 8 AND 1048576)',
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB');
        $this->addForeignKey('fk-merchant_logos-merchant_id', '{{%merchant_logos}}', 'merchant_id', '{{%merchants}}', 'id', 'CASCADE', 'CASCADE');
    }

    public function safeDown()
    {
        echo "Merchant logos rollback requires an approved backup restore.\n";
        return false;
    }
}
