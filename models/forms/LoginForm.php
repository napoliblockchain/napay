<?php

namespace app\models\forms;

use Yii;
use yii\base\Model;
use app\models\Users;


/**
 * LoginForm is the model behind the login form.
 *
 * @property-read User|null $user This property is read-only.
 *
 */
class LoginForm extends Model
{
    public $email;
    public $password;

    private $_user = false;

    /** Hash bcrypt di un valore casuale scartato (non un segreto), costo 13 come generatePasswordHash */
    private const TIMING_HASH = '$2y$13$.xDUYZJ/DPSQLGY6zqxMMuXixiOJlQnXHxUcOuJRXBFazVoXlPxk6';


    /**
     * @return array the validation rules.
     */
    public function rules()
    {
        return [
            // accesso con email (TIP-020): unico identificativo per tutti gli utenti
            ['email', 'filter', 'filter' => static fn($value) => is_string($value) ? trim($value) : ''],
            [['email', 'password'], 'required'],
            ['email', 'email'],

            // password is validated by validatePassword()
            ['password', 'validatePassword'],
        ];
    }

    /**
     * Returns the attribute labels.
     *
     * @return array
     */
    public function attributeLabels()
    {
        return [
            'email' => Yii::t('app', 'Email'),
            'password' => Yii::t('app', 'Password'),
            'otpCode' => Yii::t('app', 'Codice OTP'),
        ];
    }

    /**
     * Validates the password.
     * This method serves as the inline validation for password.
     *
     * @param string $attribute the attribute currently being validated
     * @param array $params the additional name-value pairs given in the rule
     */
    public function validatePassword($attribute, $params)
    {
        if (!$this->hasErrors()) {
            $user = $this->getUser();

            //echo "<pre>".print_r($user,true)."</pre>";exit;

            if (!$user) {
                // stesso costo di una verifica reale: i tempi non rivelano se l'email esiste
                Yii::$app->getSecurity()->validatePassword((string) $this->password, self::TIMING_HASH);
            }
            if (!$user || !$user->validatePassword($this->password)) {
                $this->addError($attribute, Yii::t('app', 'Email o password errati.'));
            } else if (!$user->validateStatus()) {
                $this->addError($attribute, Yii::t('app', 'User is not active.'));
            }
        }
    }

    /**
     * Logs in a user using the provided email and password.
     * @return bool whether the user is logged in successfully
     */
    public function login()
    {
        if ($this->validate()) {
            Yii::$app->user->login($this->_user, 3600 * 24 * 30);

            return $this->_user;
        }
        // echo "<pre>" . print_r($this->getErrors(), true) . "</pre>";
        // exit;
        return false;
    }

    /**
     * Finds user by [[email]]
     *
     * @return User|null
     */
    public function getUser()
    {
        if ($this->_user === false) {
            $this->_user = Users::findByEmail($this->email);
        }

        return $this->_user;
    }
}
