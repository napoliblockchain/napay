<?php

use yii\helpers\Html;
use yii\helpers\Url;
use app\components\User;
use app\widgets\TablerSidebarMenu;

$items = [
            ['label' => Yii::t('app', 'Dashboard'), 'url' => ['site/index'], 'iconStyle' => 'fas', 'icon' => 'tachometer-alt'],
            ['label' => Yii::t('app', 'Aziende'), 'url' => ['merchants/index'], 'iconStyle' => 'fas', 'icon' => 'store', 'visible' => User::isAdministrator()], //administrator
            ['label' => Yii::t('app', 'Negozi'), 'url' => ['stores/index'], 'iconStyle' => 'fas', 'icon' => 'shopping-cart', 'visible' => User::isAdministrator() || User::isMerchant()],
            ['label' => Yii::t('app', 'Terminali POS'), 'url' => ['pos-management/index'], 'iconStyle' => 'fas', 'icon' =>'mobile-alt', 'visible' => !Yii::$app->user->isGuest && \app\components\pos\management\Scope::current() !== null],
            ['label' => Yii::t('app', 'Transazioni'), 'url' => ['invoices/index'], 'iconStyle' => 'fas', 'icon' =>'file-invoice', 'visible' => User::isAdministrator() || User::isMerchant()],
            // associazione (TIP-020)
            ['label' => Yii::t('app', 'Paga quota'), 'url' => ['pay-fee/index'], 'iconStyle' => 'fas', 'icon' => 'hand-holding-heart'],
            ['label' => Yii::t('app', 'Soci'), 'url' => ['association/members'], 'iconStyle' => 'fas', 'icon' => 'users', 'visible' => User::isAdministrator()],
            ['label' => Yii::t('app', 'Quote associative'), 'url' => ['association/payments'], 'iconStyle' => 'fas', 'icon' => 'id-card', 'visible' => User::isAdministrator()],
            ['label' => Yii::t('app', 'Verbali'), 'url' => ['association/minutes'], 'iconStyle' => 'fas', 'icon' => 'book', 'visible' => User::isAdministrator()],
            ['label' => Yii::t('app', 'Tabelle associazione'), 'url' => ['association/tables'], 'iconStyle' => 'fas', 'icon' => 'table', 'visible' => User::isAdministrator()],
            ['label' => Yii::t('app', 'Pannello Admin'), 'url' => ['admin/index'], 'iconStyle' => 'fas', 'icon' => 'cog', 'visible' => User::isAdministrator() || User::isMerchant()],
                      
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
            <?= TablerSidebarMenu::widget(['items' => $items]) ?>
        </div>
    </div>
</aside>
