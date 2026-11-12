<?php
namespace app\models\forms\pos;

use Yii;
use yii\base\Model;
use app\components\pos\management\PosInvitations;

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
            // input non testuale (es. array) = vuoto, poi required: la vista non riceve mai array
            [['first_name', 'last_name'], 'filter', 'filter' => static fn($v) => is_string($v) ? trim($v) : ''],
            [['token', 'password', 'repeat_password'], 'filter', 'filter' => static fn($v) => is_string($v) ? $v : ''],
            [['token', 'first_name', 'last_name', 'password', 'repeat_password'], 'required'],
            ['token', 'string', 'max' => 64],
            [['first_name', 'last_name'], 'string', 'max' => 256],
            [['first_name', 'last_name'], 'match', 'pattern' => '/\S/u'],
            ['password', 'string', 'min' => Yii::$app->params['user.passwordMinLength'], 'max' => 72],
            ['repeat_password', 'string', 'max' => 72],
            // stesso controllo di reset/signup: bcrypt tronca oltre 72 byte (UTF-8 multibyte)
            ['password', function ($attribute) {
                if (is_string($this->$attribute) && strlen($this->$attribute) > PosInvitations::PASSWORD_MAX_BYTES) $this->addError($attribute, Yii::t('app', 'Password exceeds 72 bytes.'));
            }],
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
