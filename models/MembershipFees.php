<?php

namespace app\models;

/**
 * Quote associative: importo e durata (TIP-020).
 * Scritture solo dall'import legacy finché è attivo (components/legacy/LegacyImporter).
 *
 * @property mixed $id
 * @property mixed $legacy_id
 * @property mixed $description
 * @property mixed $duration
 * @property mixed $amount
 */
class MembershipFees extends \yii\db\ActiveRecord
{
    public static function tableName()
    {
        return 'membership_fees';
    }
}
