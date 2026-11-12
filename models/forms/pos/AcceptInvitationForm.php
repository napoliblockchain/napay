<?php
namespace app\models\forms\pos;

use Yii;
use yii\base\Model;

/** C4 parte D: completamento invito. Solo nome, cognome e password: ruolo, email e merchant li decide il server. */
class AcceptInvitationForm extends Model
{
    public $token;
    public $first_name;
    public $last_name;
    public $password;
    public $repeat_password;

    public function rules()
    {
        return [
            [['first_name', 'last_name'], 'filter', 'filter' => static fn($v) => is_string($v) ? trim($v) : $v],
            [['token', 'first_name', 'last_name', 'password', 'repeat_password'], 'required'],
            ['token', 'string', 'max' => 64],
            [['first_name', 'last_name'], 'string', 'max' => 256],
            [['first_name', 'last_name'], 'match', 'pattern' => '/\S/u'],
            ['password', 'string', 'min' => Yii::$app->params['user.passwordMinLength'], 'max' => 72],
            ['repeat_password', 'string', 'max' => 72],
            ['repeat_password', 'compare', 'compareAttribute' => 'password', 'message' => Yii::t('app', 'Passwords do not match.')],
        ];
    }

    public function attributeLabels()
    {
        return [
            'first_name' => Yii::t('app', 'Nome'), 'last_name' => Yii::t('app', 'Cognome'),
            'password' => Yii::t('app', 'Password'), 'repeat_password' => Yii::t('app', 'Ripeti password'),
        ];
    }
}
