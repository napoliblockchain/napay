<?php

use yii\db\Migration;

/**
 * SIP-030 step 5c: v1 POS history in the same payment tables as v2 (no separate archive). v1 payments have no v2 opening,
 * operator session or user (and v1 BTC invoices no BTCPay POS app): those columns become optional, but only all together
 * and only for closed payments. Every payment created by the v2 POS keeps them (service code always sets them).
 */
class m261119_120000_allow_legacy_pos_payments extends Migration
{
    public const BTC_CHECK = 'ck-pos-btc-payments-origin';
    public const TTS_CHECK = 'ck-pos-tts-payments-origin';

    public function up()
    {
        $ascii = 'CHARACTER SET ascii COLLATE ascii_bin';
        $btc = $this->db->schema->getTableSchema('{{%pos_btc_payments}}', true);
        $tts = $this->db->schema->getTableSchema('{{%pos_tts_payments}}', true);
        if ($btc === null || $tts === null) throw new RuntimeException('POS payment tables missing.');
        if (!$btc->columns['opening_id']->allowNull) {
            $this->execute('ALTER TABLE {{%pos_btc_payments}} MODIFY `opening_id` INT NULL, MODIFY `session_id` INT NULL, MODIFY `user_id` INT NULL,'
                . " MODIFY `remote_app_id` VARCHAR(64) $ascii NULL");
        }
        if (!$tts->columns['opening_id']->allowNull) {
            $this->execute('ALTER TABLE {{%pos_tts_payments}} MODIFY `opening_id` INT NULL, MODIFY `session_id` INT NULL, MODIFY `user_id` INT NULL');
        }
        $checks = static fn(string $table) => (new yii\db\Query())->select('CONSTRAINT_NAME')->from('information_schema.TABLE_CONSTRAINTS')
            ->where(['TABLE_SCHEMA' => new yii\db\Expression('DATABASE()'), 'CONSTRAINT_TYPE' => 'CHECK', 'TABLE_NAME' => $table])->column();
        if (!in_array(self::BTC_CHECK, $checks($this->db->schema->getRawTableName('{{%pos_btc_payments}}')), true)) {
            $this->execute('ALTER TABLE {{%pos_btc_payments}} ADD CONSTRAINT `' . self::BTC_CHECK . '` CHECK (' . self::btcClause() . ')');
        }
        if (!in_array(self::TTS_CHECK, $checks($this->db->schema->getRawTableName('{{%pos_tts_payments}}')), true)) {
            $this->execute('ALTER TABLE {{%pos_tts_payments}} ADD CONSTRAINT `' . self::TTS_CHECK . '` CHECK (' . self::ttsClause() . ')');
        }
        $this->db->schema->refresh();
    }

    /** Apertura, sessione e utente tutti presenti (POS v2) o tutti assenti (storico v1, solo pagamenti chiusi, senza app POS). */
    public static function btcClause(): string
    {
        return "(((opening_id is null) = (session_id is null)) and ((opening_id is null) = (user_id is null)) and ((opening_id is null) = (remote_app_id is null))"
            . " and ((opening_id is not null) or (state in ('settled','expired','invalid'))) and regexp_like(remote_store_id,'^[A-Za-z0-9_-]{1,64}$','c'))";
    }

    public static function ttsClause(): string
    {
        return "(((opening_id is null) = (session_id is null)) and ((opening_id is null) = (user_id is null)) and ((opening_id is not null) or (state in ('paid','expired','cancelled'))))";
    }

    public function down()
    {
        echo "Legacy POS payments rollback requires an approved backup restore.\n";
        return false;
    }
}
