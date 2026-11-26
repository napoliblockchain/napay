<?php

namespace app\models;

use Yii;

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
    public function rules()
    {
        return [
            [['description'], 'filter', 'filter' => static fn($v) => is_string($v) ? trim($v) : ''],
            [['description', 'duration', 'amount'], 'required'],
            [['description'], 'string', 'max' => 50],
            [['duration'], 'integer', 'min' => 0, 'max' => 10],
            [['amount'], 'number', 'min' => 0, 'max' => 99999999.99],
        ];
    }

    public function attributeLabels()
    {
        return [
            'description' => Yii::t('app', 'Descrizione'),
            'duration' => Yii::t('app', 'Durata (anni)'),
            'amount' => Yii::t('app', 'Importo'),
        ];
    }

    public static function tableName()
    {
        return 'membership_fees';
    }
}
