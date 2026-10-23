<?php

namespace app\assets;

/** Local Tabler CSS replaces Bootstrap CSS in application pages only. */
class TablerAsset extends \yii\bootstrap5\BootstrapAsset
{
    public $sourcePath = null;
    public $basePath = '@webroot/bundles/tabler';
    public $baseUrl = '@web/bundles/tabler';
    public $css = ['css/tabler.min.css'];
}
