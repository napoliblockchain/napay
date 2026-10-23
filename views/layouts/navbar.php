<?php

use yii\helpers\Html;
use yii\helpers\Url;
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
            <?= Html::a('<i class="fas fa-sign-out-alt" aria-hidden="true"></i>', ['/site/logout'], [
                'data-method' => 'post', 'class' => 'btn btn-icon btn-ghost-secondary',
                'aria-label' => Yii::t('app', 'Logout'), 'title' => Yii::t('app', 'Logout'),
            ]) ?>
        </div>
    </div>
</header>
