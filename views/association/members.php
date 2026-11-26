<?php

use yii\helpers\Html;
use yii\helpers\Url;
use yii\grid\GridView;
use yii\bootstrap5\ActiveForm;
use app\components\Crypt;
use app\models\Members;

/* @var $this yii\web\View */
/* @var $searchModel app\models\search\MembersSearch */
/* @var $dataProvider yii\data\ActiveDataProvider */
/* @var $roles array */

$this->title = Yii::t('app', 'Soci');
$this->params['breadcrumbs'][] = $this->title;
$statuses = array_map(fn($label) => Yii::t('app', $label), Members::STATUS_LABELS);
?>
<?= $this->render('_readonly') ?>
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><?= Html::encode($this->title) ?></h3>
    </div>
    <div class="card-body border-bottom">
        <?php $form = ActiveForm::begin(['method' => 'get', 'action' => ['members'], 'options' => ['class' => 'row g-2 align-items-end']]); ?>
        <div class="col-md-5"><?= $form->field($searchModel, 'q')->textInput(['placeholder' => Yii::t('app', 'Nome, email o ragione sociale')]) ?></div>
        <div class="col-md-3"><?= $form->field($searchModel, 'status')->dropDownList($statuses, ['prompt' => Yii::t('app', 'Tutti')]) ?></div>
        <div class="col-md-2"><?= $form->field($searchModel, 'role_id')->dropDownList($roles, ['prompt' => Yii::t('app', 'Tutte')]) ?></div>
        <div class="col-md-2 mb-3"><?= Html::submitButton(Yii::t('app', 'Filtra'), ['class' => 'btn btn-primary w-100']) ?></div>
        <?php ActiveForm::end(); ?>
        <div class="text-secondary small">
            <?= Html::encode(Yii::t('app', 'In scadenza: entro {days} giorni. Scaduto: da meno di un anno. Decaduto: da più di un anno. Amministratori esenti.', ['days' => Members::EXPIRING_DAYS])) ?>
        </div>
    </div>
    <div class="table-responsive">
        <?= GridView::widget([
            'dataProvider' => $dataProvider,
            'tableOptions' => ['class' => 'table table-vcenter card-table'],
            'layout' => "{items}\n<div class=\"card-footer d-flex align-items-center\">{summary}<div class=\"ms-auto\">{pager}</div></div>",
            'columns' => [
                [
                    'attribute' => 'last_name',
                    'label' => Yii::t('app', 'Socio'),
                    'format' => 'raw',
                    'value' => fn($m) => Html::a(Html::encode(trim($m->last_name . ' ' . $m->first_name)), ['member', 'id' => Crypt::encrypt($m->id)])
                        . ($m->profile && $m->profile->is_corporate && $m->profile->denomination ? '<div class="text-secondary small">' . Html::encode($m->profile->denomination) . '</div>' : ''),
                ],
                'email:email',
                ['label' => Yii::t('app', 'Profilo'), 'value' => fn($m) => $m->privilege->description ?? ''],
                ['label' => Yii::t('app', 'Carica'), 'value' => fn($m) => $m->profile->role->description ?? ''],
                ['attribute' => 'last_expiry', 'label' => Yii::t('app', 'Scadenza quota'), 'value' => fn($m) => $m->last_expiry ? Yii::$app->formatter->asDate($m->last_expiry, 'php:d/m/Y') : '-'],
                ['label' => Yii::t('app', 'Stato'), 'format' => 'raw', 'value' => fn($m) => $this->render('_status', ['status' => $m->memberStatus(), 'label' => $m->memberStatusLabel()])],
            ],
        ]) ?>
    </div>
</div>
