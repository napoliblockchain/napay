<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace app\assets;

use Yii;
use yii\web\AssetBundle;

/**
 * Sidebar collapse Mode asset bundle.
 *
 * @author Sergio Casizzone <jambtc@gmail.com>
 * @since 2.0
 */
class SidebarCollapseAsset extends AssetBundle
{
    public $basePath = '@webroot/bundles/site/js';
    public $baseUrl = '@web/bundles/site/js';
    
    public $css = [
    ];
    
    public $js = [
        'sidebar-collapse.js'
    ];


    public $depends = [
        'yii\web\YiiAsset',
        'yii\web\JqueryAsset'
    ];
}
