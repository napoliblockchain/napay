<?php
namespace app\models\forms\pos;

use Yii;
use yii\base\Model;
use app\components\pos\tts\TtsAddress;

/** SIP-021 fase 3: indirizzo wallet TTS del merchant; merchant e autore li decide il server. */
class TtsWalletForm extends Model
{
    /** ID merchant cifrato con Crypt (solo admin) */
    public $merchant;
    public $address;

    public function rules()
    {
        return [
            ['address', 'filter', 'filter' => static fn($v) => is_string($v) ? trim($v) : $v],
            ['address', 'required'],
            ['address', 'string', 'max' => 42],
            ['address', 'validateAddress'],
            ['merchant', 'string', 'max' => 512],
        ];
    }

    public function validateAddress($attribute): void
    {
        $normalized = is_string($this->$attribute) ? TtsAddress::normalize($this->$attribute) : null;
        if ($normalized === null) {
            $this->addError($attribute, Yii::t('app', 'Invalid wallet address: use 0x followed by 40 hexadecimal characters, with a valid checksum.'));
            return;
        }
        $this->$attribute = $normalized;
    }

    public function attributeLabels()
    {
        return ['merchant' => Yii::t('app', 'Merchant'), 'address' => Yii::t('app', 'TTS wallet address')];
    }
}
