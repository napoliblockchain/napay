<?php

/** @var yii\web\View $this */
/** @var string $content */

use app\assets\AppAsset;
use app\assets\ServiceWorkerAsset;
use yii\helpers\Url;
use yii\bootstrap5\Breadcrumbs;
use yii\bootstrap5\Html;
use yii\bootstrap5\Nav;
use yii\bootstrap5\NavBar;

AppAsset::register($this);
ServiceWorkerAsset::register($this);

?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="<?= Yii::$app->language ?>" class="h-100">

<head>
    <title><?= Html::encode($this->title) ?></title>

    <!-- Manifest Progressive Web App -->
    <link rel="manifest" href="/manifest.json">

    <?php
    $this->registerCsrfMetaTags();
    $this->registerMetaTag(['charset' => Yii::$app->charset], 'charset');
    $this->registerMetaTag(['name' => 'viewport', 'content' => 'width=device-width, initial-scale=1, shrink-to-fit=no']);
    $this->registerMetaTag(['name' => 'description', 'content' => $this->params['meta_description'] ?? '']);
    $this->registerMetaTag(['name' => 'keywords', 'content' => $this->params['meta_keywords'] ?? '']);
    $this->registerLinkTag(['rel' => 'icon', 'type' => 'image/svg+xml', 'href' => Yii::getAlias('@web/brand/icon.svg')]);
    $this->registerLinkTag(['rel' => 'icon', 'type' => 'image/x-icon', 'sizes' => '48x48', 'href' => Yii::getAlias('@web/favicon.ico')]);
    $this->registerLinkTag(['rel' => 'apple-touch-icon', 'href' => Yii::getAlias('@web/brand/apple-touch-icon.png')]);
    $this->registerMetaTag(['name' => 'theme-color', 'content' => '#162626']);
    ?>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i" rel="stylesheet">

    <!-- Font Awesome icons (free version)-->
    <script src="/bundles/landing-page/js/font-awesome/all.js" crossorigin="anonymous"></script>

    <?php $this->head() ?>
</head>

<body class="d-flex flex-column h-100 dark-mode">
    <?php $this->beginBody() ?>

    <header id="header">
        <?php
        NavBar::begin([
            'brandLabel' => Html::img('/bundles/site/images/logo-60.png') . '<span class="h1 pt-1 ml-3">' . Yii::$app->name . '</span>',
            // 'brandImage' => '/bundles/site/images/logo-70.png',
            'brandUrl' => Url::to(['site/index'], true),
            'options' => ['class' => 'navbar-expand-md navbar-dark bg-dark fixed-top valign-center']
        ]);
        echo Nav::widget([
            'options' => ['class' => 'navbar-nav'],
            'items' => [
                // ['label' => 'Home', 'url' => ['/site/index']],
                // ['label' => 'About', 'url' => ['/site/about']],
                // ['label' => 'Contact', 'url' => ['/site/contact']],
                Yii::$app->user->isGuest
                    ? ['label' => '']
                    : '<li class="nav-item">'
                    . Html::beginForm(['/site/logout'])
                    . Html::submitButton(
                        'Logout (' . Yii::$app->user->identity->username . ')',
                        ['class' => 'nav-link btn btn-link logout']
                    )
                    . Html::endForm()
                    . '</li>'
            ]
        ]);
        NavBar::end();
        ?>
    </header>

    <main id="main" class="flex-shrink-0" role="main">
        <div class="container">
            <?php if (!empty($this->params['breadcrumbs'])) : ?>
                <?= Breadcrumbs::widget(['links' => $this->params['breadcrumbs']]) ?>
            <?php endif ?>
            <?= $content ?>
        </div>
    </main>

    <!-- Main Footer -->
    <?= $this->render('footer-login') ?>


    <?= \app\widgets\Alert::widget() ?>
    <?php $this->endBody() ?>
</body>

</html>
<?php $this->endPage() ?>