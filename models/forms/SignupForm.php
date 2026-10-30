<?php
namespace app\models\forms;

use Yii;
use yii\base\Model;
use app\models\Users;
use app\components\Log;


/**
 * Model representing Signup Form.
 */
class SignupForm extends Model
{
    const PRIVILEGE_ADMINISTRATOR_ID = 1; // privilege_id -> webmaster
    const PRIVILEGE_USER_ID = 2; // USER
    const PRIVILEGE_MERCHANT_ID = 3; // MERCHANT

    const PROVIDER_SOURCE = 'SELF';

    public $username;
    public $password;
    public $first_name;
    public $last_name;
    public $email;
    public $is_merchant;

    // holds the password confirmation word
    public $repeat_password;


    /**
     * Returns the validation rules for attributes.
     *
     * @return array
     */
    public function rules()
    {
        return [
            [['username', 'first_name', 'last_name', 'email'], 'filter', 'filter' => static fn($value) => is_string($value) ? trim($value) : ''],
            ['username', 'string', 'min' => 6, 'max' => 60],
            [['first_name', 'last_name'], 'string', 'max' => 256],
            ['email', 'string', 'max' => 60],
            ['username', 'match',  'not' => true,
                // we do not want to allow users to pick one of spam/bad usernames
                'pattern' => '/\b('.Yii::$app->params['user.spamNames'].')\b/i',
                'message' => Yii::t('app', 'Non puoi usare questo nome utente.')],
            ['username', 'unique', 'targetClass' => '\app\models\Users',
                 'message' => Yii::t('app', 'Il nome utente inserito è già stato utilizzato.')],

            ['email', 'email'],
            ['email', 'unique', 'targetClass' => '\app\models\Users',
                'message' => Yii::t('app', 'La mail inserita è già stata utilizzata.')
            ],
            [['username', 'first_name', 'last_name', 'email', 'password', 'repeat_password', 'is_merchant'], 'required'],
            ['password', 'string', 'min' => Yii::$app->params['user.passwordMinLength'], 'max' => 72],
            ['repeat_password', 'string', 'max' => 72],
            ['password', function ($attribute) {
                if (is_string($this->$attribute) && strlen($this->$attribute) > 72) $this->addError($attribute, 'Password exceeds 72 bytes.');
            }],

            ['repeat_password', 'compare', 'compareAttribute'=>'password'],

            ['is_merchant', 'in', 'range' => [0, 1, '0', '1'], 'strict' => true],

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
            'username' => Yii::t('app', 'Nome utente'),
            'first_name' => Yii::t('app', 'Nome'),
            'last_name' => Yii::t('app', 'Cognome'),
            'email' => Yii::t('app', 'Email'),
            'password' => Yii::t('app', 'Password'),
            'repeat_password' => Yii::t('app', 'Conferma password'),
        ];
    }

    /**
     * Signs up the user.
     * If scenario is set to "rna" (registration needs activation), this means
     * that user need to activate his account using email confirmation method.
     *
     * @return User|null The saved model or null if saving fails.
     */
    public function signup()
    {
        // echo "<pre>".print_r($_POST,true)."</pre>";
		// exit;

        // echo "<pre>".print_r($this->attributes,true)."</pre>";
		// exit;


        if ($this->validate()) {
            $privilegio = self::PRIVILEGE_USER_ID;

            if ($this->is_merchant == 1){
                $privilegio = self::PRIVILEGE_MERCHANT_ID;
            }

            $test_privilege = Users::findOne(1);
            if (null === $test_privilege) {
                $privilegio = self::PRIVILEGE_ADMINISTRATOR_ID;
            }

            // nonce per effettuare l'attivazione utente tramite link in email
            $microtime = explode(' ', microtime());
            $nonce = $microtime[1] . str_pad(substr($microtime[0], 2, 6), 6, '0');

            $user = new Users([
                'username' => $this->username,
                'email' => $this->email,
                'password' => \Yii::$app->getSecurity()->generatePasswordHash($this->password),
                'first_name' => $this->first_name,
                'last_name' => $this->last_name,
                'oauth_provider' => self::PROVIDER_SOURCE,
                'oauth_uid' => \Yii::$app->security->generateRandomString(),
                'authKey' => $nonce, //\Yii::$app->security->generateRandomString(),
                'accessToken' => \Yii::$app->security->generateRandomString(),
                'picture' => '/bundles/site/images/anonymous.png',
                'privilege_id' => $privilegio,
                'is_merchant' => $this->is_merchant,
                'is_active' => (null === $test_privilege) ? 1 : 0,
            ]);

            if ($user->save()) {
                Log::save(Yii::$app->controller->id, (Yii::$app->action->id ?? 'index'), Yii::t('app', 'User {user} signed up.', ['user' => $this->username]));
                
                return $user;
            } else {
                $message = 'Unable to save ' . self::PROVIDER_SOURCE . ' for new account '. $this->username .':</br></br>'.print_r($user->getErrors(), true);
                Yii::$app->getSession()->setFlash('error', $message);
                Log::save(Yii::$app->controller->id, (Yii::$app->action->id ?? 'index'), $message);
            }

        }
        return false;
    }

}
