<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "settings".
 *
 * @property int $id
 * @property string $description
 * @property string $code
 * @property string|null $value
 */
class Settings extends \yii\db\ActiveRecord
{
    /**
     * Righe ritirate (SIP-030 punto 4): server e chiave BTCPay dei negozi ora dalle variabili d'ambiente.
     * Restano nel DB (il bootstrap vieta alle migration di cambiare i dati importati) ma mai lette né mostrate.
     */
    public const RETIRED_CODES = ['btcpayHost', 'btcpayApiKey', 'webhookSecret'];

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'settings';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['description', 'code'], 'required'],
            [['description', 'code'], 'string', 'max' => 256],
            [['code'], 'unique'],
            [['code'], 'in', 'range' => self::RETIRED_CODES, 'not' => true],
            [['value'], 'string', 'max' => 2048],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('app', 'ID'),
            'description' => Yii::t('app', 'Descrizione'),
            'code' => Yii::t('app', 'Nome campo'),
            'value' => Yii::t('app', 'Valore'),
        ];
    }

    /**
     * {@inheritdoc}
     * @return \app\models\query\SettingsQuery the active query used by this AR class.
     */
    public static function find()
    {
        return new \app\models\query\SettingsQuery(get_called_class());
    }
}
