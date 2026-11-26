<?php

use yii\db\Migration;

/**
 * TTS/EUR rate of POS TTS payments: fiat_cents = price typed by the cashier in EUR, rate_cents = EUR cents per 1 TTS
 * used for the request (amount_cents stays the TTS amount paid on chain). Existing payments were 1 TTS = 1 EUR.
 */
class m261118_170000_add_pos_tts_payment_rates extends Migration
{
    private const TABLE = '{{%pos_tts_payments}}';

    public function up()
    {
        $schema = $this->db->schema->getTableSchema(self::TABLE, true);
        if ($schema === null) throw new RuntimeException('pos_tts_payments missing.');
        foreach (['fiat_cents', 'rate_cents'] as $column) {
            if (!isset($schema->columns[$column])) $this->execute('ALTER TABLE ' . self::TABLE . ' ADD COLUMN `' . $column . '` INT NULL');
        }
        $this->execute('UPDATE ' . self::TABLE . ' SET fiat_cents = amount_cents, rate_cents = 100 WHERE fiat_cents IS NULL OR rate_cents IS NULL');
        $this->execute('ALTER TABLE ' . self::TABLE . ' MODIFY `fiat_cents` INT NOT NULL, MODIFY `rate_cents` INT NOT NULL');
        $checks = $this->db->createCommand('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND CONSTRAINT_TYPE=\'CHECK\'',
            [':t' => $this->db->schema->getRawTableName(self::TABLE)])->queryColumn();
        if (!in_array('ck-pos-tts-payments-rate', $checks, true)) {
            $this->execute('ALTER TABLE ' . self::TABLE . ' ADD CONSTRAINT `ck-pos-tts-payments-rate` CHECK (fiat_cents BETWEEN 1 AND 100000 AND rate_cents BETWEEN 1 AND 100000000)');
        }
        $this->db->schema->refresh();
    }

    public function down()
    {
        echo "POS TTS payment rates rollback requires an approved backup restore.\n";
        return false;
    }
}
