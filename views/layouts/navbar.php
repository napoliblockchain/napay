<?php

use yii\helpers\Html;
use yii\helpers\Url;
use app\components\Crypt;
use app\components\User;
?>
<header class="navbar d-print-none napay-topbar">
    <div class="container-fluid gap-2">
        <button class="btn btn-icon btn-ghost-secondary d-none d-lg-inline-flex" type="button"
                data-napay-sidebar-toggle aria-label="<?= Html::encode(Yii::t('app', 'Toggle navigation')) ?>" aria-pressed="false">
            <i class="fas fa-bars" aria-hidden="true"></i>
        </button>
        <a class="nav-link px-2" href="<?= Url::to(['site/index'], true) ?>">Home</a>
        <div class="navbar-nav flex-row ms-auto gap-2">
            <button type="button" class="btn btn-icon btn-ghost-secondary" data-napay-theme-toggle
                    data-label-light="<?= Html::encode(Yii::t('app', 'Use light theme')) ?>"
                    data-label-dark="<?= Html::encode(Yii::t('app', 'Use dark theme')) ?>"
                    aria-label="<?= Html::encode(Yii::t('app', 'Use dark theme')) ?>" aria-pressed="false">
                <i class="fas fa-moon" data-napay-theme-icon aria-hidden="true"></i>
            </button>
            <?php if (!Yii::$app->user->isGuest): ?>
                <div class="nav-item dropdown">
                    <button type="button" class="nav-link dropdown-toggle gap-2 border-0 bg-transparent"
                            id="napay-account-menu" data-bs-toggle="dropdown" aria-expanded="false"
                            aria-label="<?= Html::encode(Yii::t('app', 'Account')) ?>">
                        <?= Html::img(Yii::$app->user->identity->picture, ['class' => 'avatar avatar-sm', 'alt' => '']) ?>
                        <span class="napay-profile-name text-truncate d-none d-md-inline"><?= Html::encode(Yii::$app->user->identity->first_name . ' ' . Yii::$app->user->identity->last_name) ?></span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end" aria-labelledby="napay-account-menu">
                        <?= Html::a(Html::encode(Yii::t('app', 'Profilo')), ['users/view', 'id' => Crypt::encrypt(Yii::$app->user->id)], [
                            'class' => 'dropdown-item', 'data-napay-profile-link' => true,
                        ]) ?>
                        <?php if (User::isMerchant()): ?>
                            <?= Html::a(Html::encode(Yii::t('app', 'Azienda')), ['/merchants/company'], [
                                'class' => 'dropdown-item', 'data-napay-company-link' => true,
                            ]) ?>
                        <?php endif ?>
                        <?= Html::a(Html::encode(Yii::t('app', 'Logout')), ['/site/logout'], [
                            'data-method' => 'post', 'class' => 'dropdown-item',
                        ]) ?>
                    </div>
                </div>
            <?php endif ?>
        </div>
    </div>
</header>
