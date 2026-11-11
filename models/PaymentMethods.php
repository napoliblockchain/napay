<?php

namespace app\models;

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
    public static function tableName()
    {
        return 'payment_methods';
    }
}
