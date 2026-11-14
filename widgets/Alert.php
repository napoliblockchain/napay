<?php

namespace app\widgets;

use jambtc\ToastrWidget\assets\ToastrAsset;
use Yii;
use yii\helpers\Html;
use yii\web\View;

/**
 * Messaggi flash di sessione come toast a comparsa (jambtc/yii2-toastr-widget: asset e opzioni del widget),
 * non più div fissi. Differenze dal widget Toast v1.1.0: ogni messaggio è codificato HTML (toastr usa
 * escapeHtml: false) e il JSON resta UTF-8 leggibile; tornare a Toast quando il pacchetto le includerà.
 */
class Alert extends \yii\bootstrap5\Widget
{
    /** Tipo flash Yii => metodo toastr (toastr non ha "danger"). */
    private const TYPES = ['error' => 'error', 'danger' => 'error', 'success' => 'success', 'info' => 'info', 'warning' => 'warning'];
    private const OPTIONS = ['closeButton' => true, 'progressBar' => true, 'positionClass' => 'toast-bottom-right',
        'timeOut' => '15000', 'extendedTimeOut' => '2000', 'showMethod' => 'fadeIn', 'hideMethod' => 'fadeOut'];

    public function run()
    {
        $session = Yii::$app->session;
        $calls = [];
        foreach (self::TYPES as $type => $method) {
            if (!$session->hasFlash($type)) continue;
            foreach ((array) $session->getFlash($type) as $message) {
                if (!is_scalar($message) || (string) $message === '') continue;
                $calls[] = 'toastr.' . $method . '(' . json_encode(Html::encode((string) $message), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ');';
            }
            $session->removeFlash($type);
        }
        if ($calls !== []) {
            ToastrAsset::register($this->getView());
            $this->getView()->registerJs('toastr.options = ' . json_encode(self::OPTIONS, JSON_THROW_ON_ERROR) . ";\n" . implode("\n", $calls), View::POS_READY);
        }
        return '';
    }
}
