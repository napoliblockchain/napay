<?php

namespace app\components\logging;

/** Keep rejected-identity audit events, but never serialize their credential. */
final class AuthenticationFileTarget extends \yii\log\FileTarget
{
    public function getMessagePrefix($message)
    {
        $app = \Yii::$app;
        $request = $app === null ? null : $app->getRequest();
        $ip = $request instanceof \yii\web\Request ? $request->getUserIP() : '-';
        $user = $app !== null && $app->has('user', true) ? $app->get('user') : null;
        $id = $user === null ? '-' : ($user->getIdentity(false)->id ?? '-');
        // Yii's default prefix includes the live session ID.
        return "[$ip][$id]";
    }

    public function formatMessage($message)
    {
        if (in_array($message[2], ['yii\\web\\User::renewAuthStatus', 'yii\\web\\User::getIdentityAndDurationFromCookie'], true)) {
            $message[0] = is_string($message[0])
                ? preg_replace('/: .*/s', ': [REDACTED]', $message[0])
                : 'Identity credential rejected.';
        }
        return parent::formatMessage($message);
    }
}
