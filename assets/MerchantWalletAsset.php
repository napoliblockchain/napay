<?php

namespace app\assets;

use yii\web\AssetBundle;

/** Pagina wallet TTS del merchant: copia dell'indirizzo negli appunti. */
class MerchantWalletAsset extends AssetBundle
{
    public $basePath = '@webroot/bundles/merchant-wallet';
    public $baseUrl = '@web/bundles/merchant-wallet';
    public $js = ['copy.js'];
}
