<?php

namespace app\assets;

/** One local Bootstrap bundle, matching the version embedded in Tabler CSS. */
class TablerPluginAsset extends \yii\bootstrap5\BootstrapPluginAsset
{
    public $sourcePath = null;
    public $basePath = '@webroot/bundles/tabler';
    public $baseUrl = '@web/bundles/tabler';
    public $js = ['js/bootstrap.bundle.min.js'];
}
