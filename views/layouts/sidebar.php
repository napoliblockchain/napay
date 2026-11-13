<?php

use yii\helpers\Html;
use yii\helpers\Url;
use app\components\User;
use app\widgets\TablerSidebarMenu;
use app\components\identity\AccountEligibility;
use app\components\pos\management\Scope;

$admin = !Yii::$app->user->isGuest && User::isAdministrator();
$scope = Yii::$app->user->isGuest ? null : Scope::current();
$member = !Yii::$app->user->isGuest && AccountEligibility::isMemberAccount((int)Yii::$app->user->id);
$usersAllowed = !Yii::$app->user->isGuest && User::can(40);
$on = static fn($controller, array $actions=[]) => Yii::$app->controller->id === $controller
    && ($actions === [] || in_array(Yii::$app->controller->action->id, $actions, true));
$items = [
    ['label'=>Yii::t('app', 'Dashboard'), 'url'=>['site/index'], 'icon'=>'tachometer-alt'],
    ['label'=>Yii::t('app', 'La mia area'), 'icon'=>'user', 'items'=>[
        ['label'=>Yii::t('app', 'Paga quota'), 'url'=>['pay-fee/index'], 'icon'=>'hand-holding-heart', 'visible'=>$member, 'active'=>$on('pay-fee')],
    ]],
    ['label'=>Yii::t('app', 'Associazione'), 'icon'=>'users', 'visible'=>$admin, 'items'=>[
        ['label'=>Yii::t('app', 'Soci'), 'url'=>['association/members'], 'active'=>$on('association', ['members','member','member-update'])],
        ['label'=>Yii::t('app', 'Quote associative'), 'url'=>['association/payments'], 'active'=>$on('association', ['payments','receipt','payment-create','payment-update','payment-delete','export'])],
        ['label'=>Yii::t('app', 'Verbali'), 'url'=>['association/minutes'], 'active'=>$on('association', ['minutes','minute-file','minute-create','minute-update','minute-delete'])],
        ['label'=>Yii::t('app', 'Tabelle associazione'), 'url'=>['association/tables'], 'active'=>$on('association', ['tables','table-edit','table-delete'])],
    ]],
    ['label'=>Yii::t('app', 'Esercenti'), 'icon'=>'store', 'visible'=>$scope !== null, 'items'=>[
        ['label'=>Yii::t('app', 'Aziende'), 'url'=>['merchants/index'], 'visible'=>$admin, 'active'=>$on('merchants')],
        ['label'=>Yii::t('app', 'Negozi'), 'url'=>['stores/index'], 'active'=>$on('stores')],
        ['label'=>Yii::t('app', 'Terminali POS'), 'url'=>['pos-management/index'], 'active'=>$on('pos-management')],
    ]],
    ['label'=>Yii::t('app', 'Amministrazione'), 'icon'=>'cog', 'items'=>[
        ['label'=>Yii::t('app', 'Utenti'), 'url'=>['users/index'], 'visible'=>$usersAllowed, 'active'=>$on('users')],
        ['label'=>Yii::t('app', 'Profili e privilegi'), 'url'=>['privileges/index'], 'visible'=>$admin, 'active'=>$on('privileges')],
        ['label'=>Yii::t('app', 'Impostazioni'), 'url'=>['settings/index'], 'visible'=>$admin, 'active'=>$on('settings')],
        ['label'=>Yii::t('app', 'Registro attività'), 'url'=>['logs/index'], 'visible'=>$admin, 'active'=>$on('logs')],
    ]],
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
