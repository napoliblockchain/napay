<?php
namespace app\components;

use Yii;
use yii\base\Component;

class sendMail extends Component
{
    public static function toUser ($user, $htmlView, $subject, array $parameters = [])
    {
        try {
            $sent = Yii::$app->mailer->compose($htmlView, array_merge($parameters, ['user' => $user]))
                ->setFrom([Yii::$app->params['adminEmail'] => Yii::$app->name])
                ->setTo($user->email)
                ->setSubject(Yii::$app->name.' - ' . $subject)
                ->send();
            if (!$sent) Yii::warning('Mail delivery failed.', __METHOD__);
            return $sent;
        } catch (\Throwable $error) {
            Yii::warning('Mail delivery failed.', __METHOD__);
            return false;
        }
    }

    public static function applicationUrl(string $path, array $query = []): string
    {
        $base = Yii::$app->params['webapp_link'];
        $url = parse_url($base);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || preg_match('/[\x00-\x20\x7f]/', $base)) {
            throw new \RuntimeException('Configure a trusted HTTPS WEBAPP_LINK for mail.');
        }
        return rtrim($base, '/') . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }

    public static function activationUrl(\app\models\Users $user): string
    {
        if ((int) $user->is_active === \app\models\Users::STATUS_ACTIVE) return self::applicationUrl('site/login');
        if (!$user->canRequestActivation()) throw new \DomainException('Account is not pending local activation.');
        return self::applicationUrl('site/activate', [
            'id' => Crypt::sqlEncrypt((string) $user->id), 'sign' => $user->getActivationSignature(),
        ]);
    }

    public static function toAdmins ($user, $htmlView, $subject)
    {
        try {
            $models = User::getAdmins();
            $adminMails = [];
            foreach ($models as $model) {
                $adminMails[] = $model->email;
            }
            if ($adminMails === []) return false;

            $sent = Yii::$app->mailer->compose($htmlView, ['user' => $user])
                ->setFrom([Yii::$app->params['adminEmail'] => Yii::$app->name])
                ->setTo($adminMails)
                ->setSubject(Yii::$app->name . ' - ' . $subject)
                ->send();
            if (!$sent) Yii::warning('Administrator mail delivery failed.', __METHOD__);
            return $sent;
        } catch (\Throwable $error) {
            Yii::warning('Administrator mail delivery failed.', __METHOD__);
            return false;
        }
    }

}
