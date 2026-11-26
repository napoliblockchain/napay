<?php

namespace app\widgets;

use Yii;
use yii\base\Widget;

final class AppShortcuts extends Widget
{
    public bool $publicPage = false;

    public function run(): string
    {
        if (!$this->publicPage && Yii::$app->user->isGuest) {
            return '';
        }

        $links = [];
        foreach ([
            ['walletAppUrl', Yii::t('app', 'Open Wallet TTS'), 'fa-wallet'],
            ['posAppUrl', Yii::t('app', 'Open POS'), 'fa-cash-register'],
        ] as [$setting, $label, $icon]) {
            $url = Yii::$app->params[$setting] ?? null;
            if (!is_string($url) || !preg_match('~\Ahttps://([a-zA-Z0-9.-]{1,253})(?::([1-9][0-9]{0,4}))?/?\z~', $url, $parts)
                || !filter_var($parts[1], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
                || (isset($parts[2]) && (int) $parts[2] > 65535)) {
                continue;
            }
            $links[] = [$url, $label, $icon];
        }

        return $links === [] ? '' : $this->render('app-shortcuts', [
            'links' => $links, 'publicPage' => $this->publicPage,
        ]);
    }
}
