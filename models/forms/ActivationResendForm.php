<?php
namespace app\models\forms;

use Yii;
use app\models\Users;
use app\components\sendMail;

class ActivationResendForm extends EmailRequestForm
{
    public function sendEmail(): bool
    {
        if (!$this->validate()) return false;
        try {
            if (!$this->acceptRequest('activation')) return false;
            $user = Users::findByEmail($this->email);
            if ($user === null || !$user->canRequestActivation()) return false;
            $old = ['authKey' => $user->authKey, 'accessToken' => $user->accessToken, 'activationCode' => $user->activationCode];
            $user->authKey = (string) time() . sprintf('%06d', random_int(0, 999999));
            $user->accessToken = Yii::$app->security->generateRandomString(60);
            $user->activationCode = null;
            // Validate trusted link configuration before persisting any change.
            sendMail::activationUrl($user);
            $next = ['authKey' => $user->authKey, 'accessToken' => $user->accessToken, 'activationCode' => null];
            $pending = ['id' => $user->id, 'is_active' => Users::STATUS_INSERTED, 'oauth_provider' => 'SELF'];
            if (Users::updateAll($next, array_merge($pending, $old)) !== 1) return false;
            $sent = sendMail::toUser($user, ['html' => 'newAccountUser', 'text' => 'newAccountUser-text'], Yii::t('app', 'Account activation'));
            if (!$sent) Users::updateAll($old, array_merge($pending, $next));
            return $sent;
        } catch (\Throwable $error) {
            Yii::warning('Activation recovery request could not be processed.', __METHOD__);
            return false;
        }
    }
}
