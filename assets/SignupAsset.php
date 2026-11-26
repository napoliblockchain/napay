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
 * Select organization
 *
 * @author Sergio Casizzone <jambtc@gmail.com>
 * @since 2.0
 */
class SignupAsset extends AssetBundle
{
    public $basePath = '@webroot/bundles/signup';
    public $baseUrl = '@web/bundles/signup';
    public $css = [
    ];
    public $js = [
        'selectorganization.js'
    ];


    public $depends = [
        'yii\web\YiiAsset',
        'yii\web\JqueryAsset'
    ];
}
