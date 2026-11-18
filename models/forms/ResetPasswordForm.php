<?php

namespace app\models\forms;

use Yii;
use yii\base\Model;
use yii\base\InvalidArgumentException;
use app\models\Users;
use app\components\sendMail;
use app\components\Log;

/**
 * Password reset form
 */
class ResetPasswordForm extends Model
{
    public $password;
    public $repeat_password;

    /**
     * @var \common\models\Users
     */
    private $_user;
    private $_token;


    /**
     * Creates a form model given a token.
     *
     * @param string $token
     * @param array $config name-value pairs that will be used to initialize the object properties
     * @throws InvalidArgumentException if token is empty or not valid
     */
    public function __construct($token, $config = [])
    {
        if (empty($token) || !is_string($token)) {
            throw new InvalidArgumentException('Password reset token cannot be blank.');
        }
        $this->_user = Users::findByPasswordResetToken($token);
        if (!$this->_user) {
            throw new InvalidArgumentException('Wrong password reset token.');
        }
        $this->_token = $token;
        parent::__construct($config);
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['password', 'repeat_password'], 'required'],
            ['password', 'string', 'min' => Yii::$app->params['user.passwordMinLength'], 'max' => 72],
            ['repeat_password', 'string', 'max' => 72],
            ['password', function ($attribute) {
                if (is_string($this->$attribute) && strlen($this->$attribute) > 72) $this->addError($attribute, 'Password exceeds 72 bytes.');
            }],

            ['repeat_password', 'compare', 'compareAttribute' => 'password', 'message' => Yii::t('app',"Passwords do not match.")],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'password' => Yii::t('app', 'Password'),
            'repeat_password' => Yii::t('app', 'Confirm password'),
        ];
    }

    /**
     * Resets password.
     *
     * @return bool if password was reset.
     */
    public function resetPassword()
    {
        if (!$this->validate() || !Users::isPasswordResetTokenValid($this->_token)) return false;
        $user = $this->_user;
        $user->setPassword($this->password);
        $user->removePasswordResetToken();
        $user->generateAuthKey();
        $user->accessToken = Yii::$app->security->generateRandomString(60);
        // Compare-and-consume in one SQL statement: concurrent reuse cannot win.
        return Users::updateAll([
            'password' => $user->password, 'activationCode' => null,
            'authKey' => $user->authKey, 'accessToken' => $user->accessToken,
        ], ['id' => $user->id, 'is_active' => Users::STATUS_ACTIVE,
            'activationCode' => Users::passwordResetDigest($this->_token)]) === 1;
    }

    /**
     * Sends an email with a link, for resetting the password.
     *
     * @return bool whether the email was send
     */
    public function sendEmail()
    {
        $user = $this->_user;

        // invio la mail all'utente
        sendMail::toUser($user, ['html' => 'resetPasswordUserSuccess', 'text' => 'resetPasswordUserSuccess-text'], Yii::t('app', 'Password reset'));

        // Log message
        $message_log = Yii::t('app', 'User {user} has changed the password.', [
            'user' => $user->username,
        ]);
        Log::save(Yii::$app->controller->id, (Yii::$app->controller->action->id), $message_log);
        // end log message

        return;
    }
}
