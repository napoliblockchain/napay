<?php
namespace app\models\forms;

use Yii;
use app\models\Users;
use app\components\sendMail;

class PasswordResetRequestForm extends EmailRequestForm
{
    public function sendEmail()
    {
        if (!$this->validate()) return false;
        try {
            if (!$this->acceptRequest()) return false;
            // Ambiguous email ownership must never select an arbitrary identity.
            $users = Users::find()->active()->andWhere(['email' => $this->email])->limit(2)->all();
            if (count($users) !== 1) return false;
            $user = $users[0];
            $previous = $user->activationCode;
            $token = $user->generatePasswordResetToken();
            $url = sendMail::applicationUrl('site/reset-password', ['token' => $token]);
            // Do not revive a reset after a concurrent password/status change.
            if (Users::updateAll(['activationCode' => $user->activationCode], [
                'id' => $user->id, 'is_active' => Users::STATUS_ACTIVE,
                'authKey' => $user->authKey, 'activationCode' => $previous,
            ]) !== 1) return false;
            $sent = sendMail::toUser($user, ['html' => 'resetPasswordUser', 'text' => 'resetPasswordUser-text'], Yii::t('app', 'Password reset'), ['resetUrl' => $url]);
            if (!$sent) {
                Users::updateAll(['activationCode' => $previous], ['id' => $user->id, 'activationCode' => $user->activationCode]);
            }
            return $sent;
        } catch (\Throwable $error) {
            Yii::warning('Password reset request could not be processed.', __METHOD__);
            return false;
        }
    }
}
