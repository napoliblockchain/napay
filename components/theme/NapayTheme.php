<?php

namespace app\components\theme;

use app\assets\AppAsset;
use app\assets\BackofficeAsset;
use app\assets\TablerAsset;
use app\assets\TablerPluginAsset;
use Yii;
use yii\base\ViewEvent;
use yii\web\Controller;

/** Apply asset substitutions before the content view registers its widgets. */
final class NapayTheme
{
    public static function onBeforeRender(ViewEvent $event): void
    {
        $controller = Yii::$app->controller;
        if (!$controller instanceof Controller || $controller->module !== Yii::$app
            || !in_array($controller->layout, [null, 'main'], true)) {
            return;
        }
        $bundles = &Yii::$app->assetManager->bundles;
        if (($bundles['yii\bootstrap5\BootstrapAsset'] ?? null) instanceof \yii\web\AssetBundle) return;
        $bundles['yii\bootstrap5\BootstrapAsset'] = ['class' => TablerAsset::class];
        $bundles['yii\bootstrap5\BootstrapPluginAsset'] = ['class' => TablerPluginAsset::class];
        $bundles['kartik\icons\FontAwesomeAsset'] = ['class' => 'hail812\adminlte3\assets\FontAwesomeAsset'];
        $bundles['app\assets\SidebarCollapseAsset'] = false;
        $bundles[AppAsset::class] = ['class' => BackofficeAsset::class];
        $bundles['yii\bootstrap4\BootstrapAsset'] = false;
        $bundles['yii\bootstrap4\BootstrapPluginAsset'] = false;
    }

    public static function legacySidebarFolded(): bool
    {
        $raw = Yii::$app->request->cookies->getValue('collapsed');
        if (!is_string($raw)) return false;
        $decoded = json_decode($raw, true);
        return is_array($decoded) && ($decoded['body'] ?? null) === 'sidebar-collapse';
    }
}
