<?php
namespace app\models\forms;

use Yii;
use yii\base\Model;
use app\models\Users;
use app\models\MemberProfiles;
use app\models\Privileges;
use app\components\User;
use app\components\Log;
use app\components\identity\EmailIdentity;
use yii\db\IntegrityException;


/**
 * Model representing Signup Form.
 */
class SignupForm extends Model
{
    const PROVIDER_SOURCE = 'SELF';

    public $password;
    public $first_name;
    public $last_name;
    public $email;
    public $member_type;
    public $denomination;

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
            [['first_name', 'last_name'], 'filter', 'filter' => static fn($value) => is_string($value) ? trim($value) : ''],
            ['email', 'filter', 'filter' => [EmailIdentity::class, 'normalize']],
            [['first_name', 'last_name'], 'string', 'max' => 256],
            ['email', 'string', 'max' => 60],
            ['email', 'email'],
            ['email', function ($attribute) {
                if (Users::find()->where(EmailIdentity::condition($this->email))->exists()) {
                    $this->addError($attribute, Yii::t('app', 'La mail inserita è già stata utilizzata.'));
                }
            }],
            [['first_name', 'last_name', 'email', 'password', 'repeat_password', 'member_type'], 'required'],
            ['member_type', 'in', 'range' => ['pf', 'pg'], 'strict' => true],
            ['denomination', 'filter', 'filter' => static fn($value) => is_string($value) ? trim($value) : ''],
            ['denomination', 'string', 'max' => 250],
            ['denomination', 'required', 'when' => fn($model) => $model->member_type === 'pg',
                'whenClient' => "function () { return document.getElementById('member_type').value === 'pg'; }"],
            ['denomination', 'match', 'pattern' => '/\S/u', 'when' => fn($model) => $model->member_type === 'pg',
                'whenClient' => "function () { return document.getElementById('member_type').value === 'pg'; }"],
            ['password', 'string', 'min' => Yii::$app->params['user.passwordMinLength'], 'max' => 72],
            ['repeat_password', 'string', 'max' => 72],
            ['password', function ($attribute) {
                if (is_string($this->$attribute) && strlen($this->$attribute) > 72) $this->addError($attribute, 'Password exceeds 72 bytes.');
            }],

            ['repeat_password', 'compare', 'compareAttribute'=>'password'],

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
            'first_name' => Yii::t('app', 'Nome'),
            'last_name' => Yii::t('app', 'Cognome'),
            'email' => Yii::t('app', 'Email'),
            'password' => Yii::t('app', 'Password'),
            'repeat_password' => Yii::t('app', 'Conferma password'),
            'member_type' => Yii::t('app', 'Tipo di socio'),
            'denomination' => Yii::t('app', 'Ragione sociale della persona giuridica'),
        ];
    }

    /**
     * Creates a pending PF/PG member and profile, never a merchant or administrator.
     *
     * @return Users|false The saved member or false on validation/persistence failure.
     */
    public function signup()
    {
        if (!$this->validate()) return false;
        try {
            // The controller also includes consent in its outer transaction.
            return Yii::$app->db->transaction(function () {
                $base = Privileges::find()->select('id')->where(['level' => User::ROLE_USER])->limit(2)->column();
                if (count($base) !== 1) throw new \RuntimeException('Base membership privilege is not unambiguous.');
                // nonce per effettuare l'attivazione utente tramite link in email
                $microtime = explode(' ', microtime());
                $nonce = $microtime[1] . str_pad(substr($microtime[0], 2, 6), 6, '0');

                $user = new Users([
                    // accesso con email: lo username coincide con l'email (TIP-020)
                    'username' => $this->email,
                    'email' => $this->email,
                    'password' => \Yii::$app->getSecurity()->generatePasswordHash($this->password),
                    'first_name' => $this->first_name,
                    'last_name' => $this->last_name,
                    'oauth_provider' => self::PROVIDER_SOURCE,
                    'oauth_uid' => \Yii::$app->security->generateRandomString(),
                    'authKey' => $nonce,
                    'accessToken' => \Yii::$app->security->generateRandomString(),
                    'picture' => '/bundles/site/images/anonymous.png',
                    'privilege_id' => $base[0],
                    'is_merchant' => 0,
                    'is_active' => Users::STATUS_INSERTED,
                ]);

                if (!$user->save()) throw new \RuntimeException('Member account could not be saved.');
                $profile = new MemberProfiles([
                    'user_id' => $user->id,
                    'is_corporate' => $this->member_type === 'pg' ? 1 : 0,
                    'denomination' => $this->member_type === 'pg' ? $this->denomination : null,
                ]);
                if (!$profile->save()) throw new \RuntimeException('Member profile could not be saved.');
                Log::event(sprintf('Iscrizione socio %s creata (utente %d)', strtoupper($this->member_type), $user->id), Log::TYPE_DATA);
                return $user;
            });
        } catch (\Throwable $error) {
            if ($error instanceof IntegrityException && (int) ($error->errorInfo[1] ?? 0) === 1062) {
                $this->addError('email', Yii::t('app', 'La mail inserita è già stata utilizzata.'));
            } else {
                $this->addError('member_type', Yii::t('app', 'Registrazione non completata. Riprova oppure richiedi il recupero dal login.'));
                Yii::warning('Member registration failed: ' . get_class($error), __METHOD__);
            }
        }
        return false;
    }

}
