<?php
namespace app\models\forms;

use Yii;
use yii\base\Model;
use app\components\{Log, Throttle, sendMail};
use app\components\pos\PosApi;
use app\models\{Users, PosOperatorSessions};

/** Self-service only: identity comes from the authenticated session, never from form data. */
final class ChangePasswordForm extends Model
{
    public $current_password;
    public $password;
    public $repeat_password;
    public int $retryAfter = 0;

    public function rules(): array
    {
        return [
            [['current_password', 'password', 'repeat_password'], 'filter', 'filter' => static fn($v) => is_string($v) ? $v : ''],
            [['current_password', 'password', 'repeat_password'], 'required'],
            ['current_password', 'string', 'max' => 4096],
            ['password', 'string', 'min' => Yii::$app->params['user.passwordMinLength'], 'max' => 72],
            ['repeat_password', 'string', 'max' => 72],
            ['password', function ($attribute): void {
                if (is_string($this->$attribute) && strlen($this->$attribute) > 72) $this->addError($attribute, Yii::t('app', 'Password must not exceed 72 bytes.'));
            }],
            ['repeat_password', 'compare', 'compareAttribute' => 'password', 'message' => Yii::t('app', 'Passwords do not match.')],
        ];
    }

    public function attributeLabels(): array
    {
        return ['current_password' => Yii::t('app', 'Current password'), 'password' => Yii::t('app', 'New password'), 'repeat_password' => Yii::t('app', 'Confirm password')];
    }

    public function change(): bool
    {
        if (Yii::$app->user->isGuest || !$this->validate()) return false;
        $id = (int) Yii::$app->user->id;
        $key = 'password-change-user-' . $id;
        $ip = 'password-change-ip-' . Yii::$app->request->userIP;
        $this->retryAfter = max(Throttle::hit($key, [5, 900]), Throttle::hit($ip, [20, 900]));
        if ($this->retryAfter > 0) return $this->refuse();
        $db = Yii::$app->db;
        $tx = $db->beginTransaction();
        try {
            $user = Users::findBySql('SELECT * FROM {{%users}} WHERE id=:id FOR UPDATE', [':id' => $id])->one();
            if ($user === null || !$user->validateStatus() || !hash_equals((string) Yii::$app->user->identity->authKey, (string) $user->authKey)
                || !Yii::$app->security->validatePassword($this->current_password, $user->password)) {
                $tx->rollBack();
                return $this->refuse();
            }
            $previous = $user->password;
            $user->setPassword($this->password);
            $user->generateAuthKey();
            $user->accessToken = Yii::$app->security->generateRandomString(60);
            if (Users::updateAll(['password' => $user->password, 'authKey' => $user->authKey, 'accessToken' => $user->accessToken, 'activationCode' => null],
                ['id' => $id, 'password' => $previous, 'is_active' => Users::STATUS_ACTIVE]) !== 1) throw new \RuntimeException('password_change_failed');
            $sessions = PosOperatorSessions::findBySql('SELECT * FROM {{%pos_operator_sessions}} WHERE user_id=:id AND revoked_at IS NULL ORDER BY id FOR UPDATE', [':id' => $id])->all();
            foreach ($sessions as $session) PosApi::revoke($session, 'password_changed');
            if (!Log::save('users', 'change-password', 'Account password changed.')) throw new \RuntimeException('password_change_failed');
            $tx->commit();
        } catch (\Throwable $error) {
            if ($tx->isActive) $tx->rollBack();
            Yii::warning('Password change failed.', __METHOD__);
            return $this->refuse();
        }
        Throttle::reset($key);
        try {
            if (!sendMail::toUser($user, ['html' => 'passwordChanged', 'text' => 'passwordChanged-text'], Yii::t('app', 'Your password has changed'))) {
                Yii::warning('Password change notification could not be delivered.', __METHOD__);
            }
        } catch (\Throwable $error) {
            Yii::warning('Password change notification could not be delivered.', __METHOD__);
        }
        return true;
    }

    private function refuse(): bool
    {
        $this->addError('current_password', Yii::t('app', 'Unable to change password. Check your entries or try again later.'));
        return false;
    }
}
