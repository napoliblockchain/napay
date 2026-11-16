<?php

/** @var yii\web\View $this */
/** @var string $content */

use app\assets\AuthAsset;
use app\assets\ServiceWorkerAsset;
use yii\helpers\Html;
use yii\helpers\Url;

AuthAsset::register($this);
ServiceWorkerAsset::register($this);
?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="<?= Html::encode(Yii::$app->language) ?>">
<head>
    <meta charset="<?= Html::encode(Yii::$app->charset) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#17220f">
    <?php $this->registerCsrfMetaTags() ?>
    <title><?= Html::encode($this->title) ?></title>
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" href="<?= Html::encode(Yii::getAlias('@web/brand/icon.svg')) ?>" type="image/svg+xml">
    <link rel="icon" href="<?= Html::encode(Yii::getAlias('@web/favicon.ico')) ?>" sizes="48x48">
    <link rel="apple-touch-icon" href="<?= Html::encode(Yii::getAlias('@web/brand/apple-touch-icon.png')) ?>">
    <?php $this->head() ?>
</head>
<body class="auth auth--napay">
<?php $this->beginBody() ?>
<div class="auth-card">
    <aside class="auth-brand">
        <canvas class="auth-chain" aria-hidden="true"></canvas>
        <img class="auth-icon" src="<?= Html::encode(Yii::getAlias('@web/brand/icon-192.png')) ?>" alt="">
        <div class="auth-divider"></div>
        <p class="auth-tag"><?= Html::encode(Yii::t('app', 'Payments, members and shops of the Napoli Blockchain association')) ?></p>
    </aside>
    <main class="auth-main">
        <div class="auth-form">
            <a class="auth-wordmark" href="<?= Html::encode(Url::to(['site/index'])) ?>" aria-label="<?= Html::encode(Yii::$app->name) ?>">na<span>pay</span></a>
            <?= $content ?>
        </div>
        <div class="auth-supporters">
            <p><?= Html::encode(Yii::t('app', 'Our supporters')) ?></p>
            <div class="auth-logos">
                <img class="auth-logo-nb" src="<?= Html::encode(Yii::getAlias('@web/bundles/auth/napoli-blockchain.png')) ?>" alt="Napoli Blockchain">
                <img class="auth-logo-uni" src="<?= Html::encode(Yii::getAlias('@web/bundles/auth/parthenope.jpg')) ?>" alt="Università degli Studi di Napoli Parthenope">
            </div>
            <p class="auth-footer"><?= Html::encode(Yii::$app->params['webapp_society']) ?> &copy;<?= date('Y') ?> · <?= Html::encode(Yii::t('app', 'Version')) ?> <?= Html::encode(Yii::$app->params['version']) ?></p>
        </div>
    </main>
</div>
<?= \app\widgets\Alert::widget() ?>
<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
