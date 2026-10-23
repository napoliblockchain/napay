<?php

use yii\helpers\Html;
use yii\helpers\Url;
use app\components\User;
use app\components\Crypt;
use app\widgets\TablerSidebarMenu;

$items = [
            ['label' => Yii::t('app', 'Dashboard'), 'url' => ['site/index'], 'iconStyle' => 'fas', 'icon' => 'tachometer-alt'],
            ['label' => Yii::t('app', 'Esercenti'), 'url' => ['merchants/index'], 'iconStyle' => 'fas', 'icon' => 'store', 'visible' => User::isAdministrator()], //administrator
            ['label' => Yii::t('app', 'Negozi'), 'url' => ['stores/index'], 'iconStyle' => 'fas', 'icon' => 'shopping-cart', 'visible' => User::isAdministrator() || User::isMerchant()],
            ['label' => Yii::t('app', 'Pos'), 'url' => ['pos/index'], 'iconStyle' => 'fas', 'icon' =>'mobile-alt', 'visible' => User::isAdministrator() || User::isMerchant()],
            ['label' => Yii::t('app', 'Transazioni'), 'url' => ['invoices/index'], 'iconStyle' => 'fas', 'icon' =>'file-invoice', 'visible' => User::isAdministrator() || User::isMerchant()],
            ['label' => Yii::t('app', 'Pannello Admin'), 'url' => ['admin/index'], 'iconStyle' => 'fas', 'icon' => 'cog', 'visible' => User::isAdministrator() || User::isMerchant()],
                      
            ['label' => 'Developer', 'header' => true, 'visible' => User::isAdministrator()],
            ['label' => 'Debug', 'icon' => 'bug', 'url' => ['/debug'], 'target' => '_blank', 'visible' => User::isAdministrator()],
            ['label' => 'Gii',  'icon' => 'file-code', 'url' => ['/gii'], 'target' => '_blank', 'visible' => User::isAdministrator()],
        ];
?>
<aside class="navbar navbar-vertical navbar-expand-lg d-print-none" aria-label="<?= Html::encode(Yii::t('app', 'Main navigation')) ?>">
    <div class="container-fluid">
        <div class="navbar-brand d-flex align-items-center justify-content-between">
            <a class="text-reset text-decoration-none d-flex align-items-center gap-2" href="<?= Url::to(['site/index'], true) ?>">
                <?= Html::img('@web/bundles/site/images/logo.png', ['alt' => '', 'class' => 'navbar-brand-image']) ?>
                <span class="napay-brand-text"><?= Html::encode(Yii::$app->name) ?></span>
            </a>
            <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="collapse"
                    data-bs-target="#napay-sidebar-menu" aria-controls="napay-sidebar-menu"
                    aria-expanded="false" aria-label="<?= Html::encode(Yii::t('app', 'Toggle navigation')) ?>">
                <span class="navbar-toggler-icon"></span>
            </button>
        </div>
        <div class="collapse navbar-collapse" id="napay-sidebar-menu">
            <?php if (!Yii::$app->user->isGuest): ?>
                <a class="nav-link d-flex gap-2 align-items-center px-3 py-3" href="<?= Url::to(['users/view', 'id' => Crypt::encrypt(Yii::$app->user->id)]) ?>">
                    <?= Html::img(Yii::$app->user->identity->picture, ['class' => 'avatar avatar-sm', 'alt' => '']) ?>
                    <span class="napay-profile-name text-truncate"><?= Html::encode(Yii::$app->user->identity->first_name . ' ' . Yii::$app->user->identity->last_name) ?></span>
                </a>
            <?php endif ?>
            <?= TablerSidebarMenu::widget(['items' => $items]) ?>
        </div>
    </div>
</aside>
