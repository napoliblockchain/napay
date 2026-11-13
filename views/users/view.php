<?php

use yii\helpers\Html;
use yii\bootstrap5\Tabs;
use app\components\User;
use app\models\Users;

/* @var $model app\models\Users */
$own = (int) $model->id === (int) Yii::$app->user->id;
$name = trim($model->first_name . ' ' . $model->last_name);
$name = $name !== '' ? $name : $model->email;
$this->title = $own ? Yii::t('app', 'Il mio profilo') : Yii::t('app', 'Profilo utente');
if (User::can(40)) {
    $this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Utenti'), 'url' => ['index']];
}
$this->params['breadcrumbs'][] = $this->title;
$states = [
    Users::STATUS_INSERTED => ['bg-warning-lt', Yii::t('app', 'Inattivo')],
    Users::STATUS_ACTIVE => ['bg-success-lt', Yii::t('app', 'Attivo')],
    Users::STATUS_BLOCKED => ['bg-danger-lt', Yii::t('app', 'Bloccato')],
];
[$badge, $status] = $states[(int) $model->is_active] ?? ['bg-secondary-lt', Yii::t('app', 'Non disponibile')];
$items = [[
    'label' => Yii::t('app', 'Panoramica'),
    'options' => ['id' => 'tab-overview'],
    'content' => $this->render('_details-overview', ['model' => $model]),
]];
// Do not render hidden forms: each tab is created only for its allowed context.
if ($own) {
    \app\assets\SidebarCollapseAsset::register($this);
    $items[] = ['label' => Yii::t('app', 'Preferenze'), 'options' => ['id' => 'tab-settings'],
        'content' => $this->render('_details-settings', ['model' => $model])];
} elseif (User::isAdministrator() && (int) Yii::$app->user->identity->privilege->level >= (int) ($model->privilege->level ?? PHP_INT_MAX)) {
    $items[] = ['label' => Yii::t('app', 'Gestione account'), 'options' => ['id' => 'tab-edit'],
        'content' => $this->render('_details-edit', ['model' => $model])];
}
?>
<div class="row g-3" data-napay-user-profile>
    <div class="col-lg-4 col-xl-3">
        <section class="card h-100" aria-labelledby="profile-name">
            <div class="card-body text-center">
                <span class="avatar avatar-xl mb-3" aria-hidden="true"><i class="fas fa-user"></i></span>
                <h2 class="h3 mb-1 text-break" id="profile-name"><?= Html::encode($name) ?></h2>
                <div class="text-secondary text-break mb-3"><?= Html::encode($model->email) ?></div>
                <span class="badge <?= $badge ?>"><?= Html::encode($status) ?></span>
            </div>
            <div class="card-footer">
                <div class="subheader mb-1"><?= Html::encode(Yii::t('app', 'Profilo di accesso')) ?></div>
                <div><?= Html::encode($model->privilege->description ?? Yii::t('app', 'Non disponibile')) ?></div>
            </div>
        </section>
    </div>
    <div class="col-lg-8 col-xl-9">
        <section class="card h-100">
            <div class="card-header flex-wrap gap-2">
                <h3 class="card-title"><?= Html::encode($this->title) ?></h3>
                <?php if (User::can(40)): ?>
                    <div class="card-actions"><?= Html::a(Html::encode(Yii::t('app', 'Torna agli utenti')), ['index'], ['class' => 'btn btn-outline-secondary']) ?></div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?= Tabs::widget(['id' => 'user-profile-tabs', 'items' => $items,
                    'options' => ['class' => 'nav-tabs mb-4'], 'encodeLabels' => true]) ?>
            </div>
        </section>
    </div>
</div>
