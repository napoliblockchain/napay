<?php

use app\assets\AppAsset;
use app\assets\ServiceWorkerAsset;
use app\components\theme\NapayTheme;
use yii\helpers\Html;

AppAsset::register($this);
ServiceWorkerAsset::register($this);
// The early script runs before body rendering and never evaluates stored markup.
$this->registerJsFile('@web/bundles/site/js/napay-theme-init.js', ['position' => \yii\web\View::POS_HEAD]);
?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<?= Html::beginTag('html', [
    'lang' => Yii::$app->language,
    'data-bs-theme' => 'light',
    'data-napay-sidebar' => NapayTheme::legacySidebarFolded() ? 'folded' : 'expanded',
]) ?>
<head>
    <meta charset="<?= Html::encode(Yii::$app->charset) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php $this->registerCsrfMetaTags() ?>
    <title><?= Html::encode(Yii::$app->name) ?> | <?= Html::encode($this->title ?: Yii::$app->controller->id) ?></title>
    <?php
    $this->registerMetaTag(['name' => 'description', 'content' => $this->params['meta_description'] ?? '']);
    $this->registerMetaTag(['name' => 'keywords', 'content' => $this->params['meta_keywords'] ?? '']);
    $this->registerLinkTag(['rel' => 'icon', 'type' => 'image/x-icon', 'href' => Yii::getAlias('@web/favicon.ico')]);
    ?>
    <link rel="manifest" href="/manifest.json">
    <?php $this->head() ?>
</head>
<body class="napay-app">
<?php $this->beginBody() ?>
<div class="page">
    <?= $this->render('sidebar') ?>
    <?= $this->render('navbar') ?>
    <div class="page-wrapper">
        <?= $this->render('content', ['content' => $content]) ?>
        <?= $this->render('footer') ?>
    </div>
</div>
<?= $this->render('snackbar') ?>
<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
