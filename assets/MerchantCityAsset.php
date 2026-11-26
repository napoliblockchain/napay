<?php

namespace app\assets;

use yii\web\AssetBundle;

/** Suggerimenti città (comuni ISTAT) nel form azienda. */
class MerchantCityAsset extends AssetBundle
{
    public $basePath = '@webroot/bundles/merchants';
    public $baseUrl = '@web/bundles/merchants';
    public $js = ['city.js'];
}
