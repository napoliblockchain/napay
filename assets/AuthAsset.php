<?php

namespace app\assets;

use yii\web\AssetBundle;

/** Pagine di accesso: scheda divisa su desktop, fascia animata su mobile (stessi file del POS e del wallet). */
class AuthAsset extends AssetBundle
{
    public $basePath = '@webroot/bundles/auth';
    public $baseUrl = '@web/bundles/auth';
    public $css = ['auth.css'];
    public $js = ['auth-chain.js'];
    public $depends = [
        'yii\web\YiiAsset',
        'yii\bootstrap5\BootstrapAsset',
    ];
}
