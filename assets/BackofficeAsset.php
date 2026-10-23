<?php

namespace app\assets;

/** Login retains AppAsset; authenticated layout receives the Tabler styles. */
class BackofficeAsset extends AppAsset
{
    public $basePath = '@webroot/bundles/site';
    public $baseUrl = '@web/bundles/site';
    public $css = ['css/yiipager.css', 'css/napay-tabler.css'];
    public $js = ['js/napay-layout.js'];
    public $depends = [
        'yii\web\YiiAsset',
        'yii\bootstrap5\BootstrapAsset',
        'yii\bootstrap5\BootstrapPluginAsset',
        'hail812\adminlte3\assets\FontAwesomeAsset',
    ];
}
