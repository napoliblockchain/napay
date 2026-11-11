<?php

namespace app\models;

use Yii;
use yii\helpers\ArrayHelper;

/**
 * This is the model class for table "users".
 *
 * @property int $id
 * @property string $username
 * @property string $email
 * @property string $password
 * @property string $first_name
 * @property string $last_name
 * @property string $oauth_provider
 * @property string $oauth_uid
 * @property string $authKey
 * @property string $accessToken
 * @property string|null $activationCode
 * @property string $picture
 * @property int $privilege_id
 * @property int $is_active
 *
 * @property Auth[] $auths
 * @property Privileges $privilege
 */
class Users extends \yii\db\ActiveRecord implements \yii\web\IdentityInterface

{
    const STATUS_INSERTED = 0;
    const STATUS_ACTIVE = 1;
    const STATUS_BLOCKED = 2;

    public $merchant_id;

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'users';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['username', 'email', 'first_name', 'last_name', 'oauth_provider', 'oauth_uid', 'authKey', 'accessToken', 'picture', 'privilege_id', 'is_active'], 'required'],
            [['privilege_id', 'is_active',], 'integer'],
            [['username', 'email'], 'string', 'max' => 60],
            [['first_name', 'last_name', 'authKey'], 'string', 'max' => 256],
            [['oauth_provider'], 'string', 'max' => 20],
            [['oauth_uid'], 'string', 'max' => 128],
            [['accessToken'], 'string', 'max' => 2048],
            [['picture'], 'string', 'max' => 512],
            [['activationCode'], 'string', 'max' => 256],

            [['privilege_id'], 'exist', 'skipOnError' => true, 'targetClass' => Privileges::class, 'targetAttribute' => ['privilege_id' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('app', 'ID'),
            'username' => Yii::t('app', 'Nome utente'),
            'email' => Yii::t('app', 'Email'),
            'first_name' => Yii::t('app', 'Nome'),
            'last_name' => Yii::t('app', 'Cognome'),
            'oauth_provider' => Yii::t('app', 'OAuth Provider'),
            'oauth_uid' => Yii::t('app', 'OAuth ID'),
            'authKey' => Yii::t('app', 'Auth Key'),
            'accessToken' => Yii::t('app', 'Access Token'),
            'activationCode' => Yii::t('app', 'Activation Code'),
            'picture' => Yii::t('app', 'Picture'),
            'privilege_id' => Yii::t('app', 'Profilo'),
            'is_active' => Yii::t('app', 'Abilitato'),
        ];
    }

   
    /**
     * Gets query for [[Auths]].
     *
     * @return \yii\db\ActiveQuery|\app\models\query\AuthQuery
     */
    public function getAuths()
    {
        return $this->hasMany(Auth::class, ['user_id' => 'id']);
    }

    /**
     * Gets query for [[Merchants]].
     *
     * @return \yii\db\ActiveQuery|\app\models\query\MerchantsQuery
     */
    public function getMerchants()
    {
        return $this->hasMany(Merchants::class, ['user_id' => 'id']);
    }

    /**
     * Gets query for [[Privilege]].
     *
     * @return \yii\db\ActiveQuery|\app\models\query\PrivilegesQuery
     */
    public function getPrivilege()
    {
        return $this->hasOne(Privileges::class, ['id' => 'privilege_id']);
    }

   
    /**
     * {@inheritdoc}
     * @return \app\models\query\UsersQuery the active query used by this AR class.
     */
    public static function find()
    {
        return new \app\models\query\UsersQuery(get_called_class());
    }

    
    /**
     * Generates "remember me" authentication key
     */
    public function generateAuthKey()
    {
        $this->authKey = Yii::$app->security->generateRandomString();
    }

    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function validateAuthKey($authKey)
    {
        return $this->authKey === $authKey;
    }

    /**
     * Validates user activation
     *
     * @return bool if status is valid for current user
     */
    public function validateStatus()
    {
        return (int) $this->is_active === self::STATUS_ACTIVE;
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthKey()
    {
        return $this->authKey;
    }

    

    private function setAuthKey()
    {
        $this->authKey = Yii::$app->security->generateRandomString(60);
    }

    private function setUid()
    {
        $this->oauth_uid = Yii::$app->security->generateRandomString(60);
    }

    public function getActivationSignature(): string
    {
        if (!is_string($this->authKey) || !preg_match('/^[0-9]{16}$/D', $this->authKey)) {
            throw new \DomainException('Invalid activation credential.');
        }
        $key = base64_decode($this->authKey, true);
        if ($key === false) throw new \DomainException('Invalid activation credential.');
        return base64_encode(hash_hmac('sha512', hash('sha256', $this->authKey . $this->accessToken, true), $key, true));
    }

    public function canRequestActivation(): bool
    {
        return (int) $this->is_active === self::STATUS_INSERTED && $this->oauth_provider === 'SELF'
            && is_string($this->authKey) && preg_match('/^[0-9]{16}$/D', $this->authKey) === 1
            && is_string($this->accessToken) && strlen($this->accessToken) >= 32;
    }

    public function canActivate($signature): bool
    {
        if (!$this->canRequestActivation() || !is_string($signature) || strlen($signature) !== 88) return false;
        $issued = (int) substr($this->authKey, 0, 10);
        $decoded = base64_decode($signature, true);
        return $issued <= time() && $issued + Yii::$app->params['nonce.timeout'] > time()
            && $decoded !== false && strlen($decoded) === 64 && base64_encode($decoded) === $signature
            && hash_equals($this->getActivationSignature(), $signature);
    }

    public function activate($signature = null)
    {
        if (!$this->canActivate($signature)) return false;
        // Consume only this pending credential. Never replace the OAuth identity.
        $updated = self::updateAll([
            'is_active' => self::STATUS_ACTIVE, 'activationCode' => null,
            'authKey' => Yii::$app->security->generateRandomString(),
            'accessToken' => Yii::$app->security->generateRandomString(60),
        ], ['id' => $this->id, 'is_active' => self::STATUS_INSERTED,
            'oauth_provider' => 'SELF', 'authKey' => $this->authKey, 'accessToken' => $this->accessToken]);
        if ($updated === 1) $this->refresh();
        return $updated === 1;
    }
    
    /**
     * {@inheritdoc}
     */
    public static function findIdentity($id)
    {
        return self::find()->active()->andWhere(['id' => $id])->one();
    }

    /**
     * {@inheritdoc}
     */
    public static function findIdentityByAccessToken($token, $type = null)
    {
        return self::find()->active()->andWhere(['accessToken' => $token])->one();
    }

    /**
     * Finds user by username
     *
     * @param string $username
     * @return static|null
     */
    public static function findByUsername($username)
    {
        return self::findOne(['username' => $username]);
    }

    /**
     * Finds user by email (login identifier)
     * Ambiguous email ownership must never select an arbitrary identity.
     *
     * @param string $email
     * @return static|null
     */
    public static function findByEmail($email)
    {
        if (!is_string($email) || $email === '') return null;
        $users = self::find()->where(['email' => $email])->limit(2)->all();
        return count($users) === 1 ? $users[0] : null;
    }

    /**
     * Validates password
     *
     * @param string $password password to validate
     * @return bool if password provided is valid for current user
     */
    public function validatePassword($password)
    {
        return Yii::$app->getSecurity()->validatePassword($password, $this->password);
    }


    // ------------------------------------------------------------  * 
    // Start reset password functions

    /**
     * Finds out if password reset token is valid
     *
     * @param string $token password reset token
     * @return bool
     */
    public static function isPasswordResetTokenValid($token)
    {
        if (!is_string($token) || !preg_match('/^[A-Za-z0-9_-]{48}_[0-9]{10}$/D', $token)) {
            return false;
        }

        $timestamp = (int) substr($token, strrpos($token, '_') + 1);
        $expire = Yii::$app->params['user.passwordResetTokenExpire'];
        return $timestamp <= time() && $timestamp + $expire > time();
    }

    /**
     * Generates new password reset token
     */
    public function generatePasswordResetToken()
    {
        $token = Yii::$app->security->generateRandomString(48) . '_' . time();
        $this->activationCode = self::passwordResetDigest($token);
        return $token;
    }

    public static function passwordResetDigest(string $token): string
    {
        return hash('sha256', $token) . '_' . substr($token, -10);
    }

    /**
     * Finds user by password reset token
     *
     * @param string $token password reset token
     * @return static|null
     */
    public static function findByPasswordResetToken($token)
    {
        if (!static::isPasswordResetTokenValid($token)) {
            return null;
        }

        return static::findOne([
            'activationCode' => self::passwordResetDigest($token),
            'is_active' => self::STATUS_ACTIVE,
        ]);
    }

    /**
     * Generates password hash from password and sets it to the model
     *
     * @param string $password
     */
    public function setPassword($password)
    {
        $this->password = \Yii::$app->getSecurity()->generatePasswordHash($password);
    }

    /**
     * Removes password reset token
     */
    public function removePasswordResetToken()
    {
        $this->activationCode = null;
    }


    // END reset password functions
    // ------------------------------------------------------------  * 
}
