<?php
namespace app\models\forms;

use Yii;
use yii\base\Model;

/** Shared validation/throttle for public recovery forms, never account lookup. */
abstract class EmailRequestForm extends Model
{
    public $email;

    public function rules()
    {
        return [
            ['email', 'filter', 'filter' => static fn($value) => is_string($value) ? trim($value) : ''],
            ['email', 'required'], ['email', 'email'], ['email', 'string', 'max' => 255],
        ];
    }

    public function attributeLabels()
    {
        return ['email' => Yii::t('app', 'Email')];
    }

    protected function acceptRequest(string $purpose = 'reset'): bool
    {
        if (!in_array($purpose, ['reset', 'activation'], true)) return false;
        $mutex = Yii::$app->mutex;
        if (!$mutex->acquire('email-request-throttle', 1)) return false;
        try {
            $cache = Yii::$app->cache;
            $ipKey = $purpose . '-ip-' . hash('sha256', Yii::$app->request->userIP ?? 'unknown');
            $emailKey = $purpose . '-email-' . hash('sha256', strtolower($this->email));
            $count = (int) $cache->get($ipKey);
            if ($count >= 5 || $cache->get($emailKey) !== false) return false;
            if (!$cache->set($ipKey, $count + 1, 60) || !$cache->set($emailKey, true, 60)) return false;
            return true;
        } finally { $mutex->release('email-request-throttle'); }
    }
}
