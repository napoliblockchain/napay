<?php

namespace app\widgets;

/**
 * Messaggi flash di sessione come toast a comparsa (jambtc/yii2-toastr-widget >= 1.2: messaggi
 * codificati HTML, UTF-8 leggibile, script solo se ci sono messaggi). Resta come nome stabile per layout e viste.
 */
class Alert extends \yii\bootstrap5\Widget
{
    public function run()
    {
        return \jambtc\ToastrWidget\Toast::widget();
    }
}
