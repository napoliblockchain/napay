<?php

namespace app\assets;

use yii\web\AssetBundle;

/**
 * Gestione associazione: stampa ricevuta (TIP-020)
 */
class AssociationAsset extends AssetBundle
{
    public $basePath = '@webroot/bundles/association';
    public $baseUrl = '@web/bundles/association';
    public $js = [
        'association.js',
    ];
}
