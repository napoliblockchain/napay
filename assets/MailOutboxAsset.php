<?php

namespace app\assets;

use yii\web\AssetBundle;

/**
 * Posta in uscita: copia dei link negli appunti
 */
class MailOutboxAsset extends AssetBundle
{
    public $basePath = '@webroot/bundles/mail-outbox';
    public $baseUrl = '@web/bundles/mail-outbox';
    public $js = [
        'mail-outbox.js',
    ];
}
