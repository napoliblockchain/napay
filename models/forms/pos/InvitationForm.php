<?php
namespace app\models\forms\pos;

use Yii;
use yii\base\Model;
use app\components\pos\management\PosInvitations;

/** C4 parte D: invito operatore. Il merchant (solo admin) arriva cifrato ed e' solo un selettore. */
class InvitationForm extends Model
{
    public $email;
    /** ID merchant cifrato con Crypt, usato solo dall'admin */
    public $merchant;

    public function rules()
    {
        return [
            ['email', 'required'],
            ['email', 'string', 'max' => 254],
            ['email', function ($attribute) {
                $email = PosInvitations::normalizeEmail($this->$attribute);
                if ($email === null) $this->addError($attribute, Yii::t('app', 'Indirizzo email non valido.'));
                else $this->$attribute = $email;
            }],
            ['merchant', 'string', 'max' => 512],
        ];
    }

    public function attributeLabels()
    {
        return ['email' => Yii::t('app', 'Email del nuovo operatore'), 'merchant' => Yii::t('app', 'Merchant')];
    }
}
