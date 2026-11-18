<?php

namespace app\assets;

use yii\web\AssetBundle;

/** Colori leggibili dei messaggi flash (toastr) sopra Tabler/Bootstrap 5; dopo il CSS di toastr. */
class ToastrTablerAsset extends AssetBundle
{
    public $basePath = '@webroot/bundles/site/css';
    public $baseUrl = '@web/bundles/site/css';
    public $css = ['toastr-tabler.css'];
    public $depends = ['jambtc\ToastrWidget\assets\ToastrAsset'];
}
