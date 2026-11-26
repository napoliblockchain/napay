<?php

namespace app\models;

use Yii;

/**
 * Metodi di pagamento delle quote (TIP-020).
 * Scritture solo dall'import legacy finché è attivo (components/legacy/LegacyImporter).
 *
 * @property mixed $id
 * @property mixed $legacy_id
 * @property mixed $description
 * @property mixed $min_level
 */
class PaymentMethods extends \yii\db\ActiveRecord
{
    public function rules()
    {
        return [
            [['description'], 'filter', 'filter' => static fn($v) => is_string($v) ? trim($v) : ''],
            [['description', 'min_level'], 'required'],
            [['description'], 'string', 'max' => 50],
            [['min_level'], 'in', 'range' => [0, 20], 'strict' => false],
        ];
    }

    public function attributeLabels()
    {
        return [
            'description' => Yii::t('app', 'Metodo'),
            'min_level' => Yii::t('app', 'Uso'),
        ];
    }

    public static function tableName()
    {
        return 'payment_methods';
    }
}
