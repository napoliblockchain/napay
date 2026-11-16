<?php

namespace app\assets;

use yii\web\AssetBundle;

/** Pagina wallet TTS del negozio: copia dell'indirizzo negli appunti. */
class StoreWalletAsset extends AssetBundle
{
    public $basePath = '@webroot/bundles/store-wallet';
    public $baseUrl = '@web/bundles/store-wallet';
    public $js = ['copy.js'];
}
