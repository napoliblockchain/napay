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
        $output = \jambtc\ToastrWidget\Toast::widget();
        // colori leggibili sotto Tabler (la .toast di Bootstrap rendeva il testo bianco su bianco), solo se ci sono toast
        $view = $this->getView();
        if (isset($view->assetBundles[\jambtc\ToastrWidget\assets\ToastrAsset::class])) \app\assets\ToastrTablerAsset::register($view);
        return $output;
    }
}
