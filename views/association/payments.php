<?php

use yii\helpers\Html;
use app\components\web\LinkPager;
use yii\bootstrap5\ActiveForm;
use app\models\MembershipPayments;

/* @var $this yii\web\View */
/* @var $searchModel app\models\search\MembershipPaymentsSearch */
/* @var $dataProvider yii\data\ActiveDataProvider */
/* @var $totals array */
/* @var $methods array */
/* @var $years array */
/* @var $queryParams string */

$this->title = Yii::t('app', 'Quote associative');
$this->params['breadcrumbs'][] = $this->title;
$statuses = array_map(fn($label) => Yii::t('app', $label), MembershipPayments::STATUS_LABELS);
?>
<?= $this->render('_readonly') ?>
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><?= Html::encode($this->title) ?></h3>
        <div class="card-actions d-flex gap-2">
            <?php if (empty($this->params['associationReadOnly'])): ?>
                <?= Html::a('<i class="fas fa-plus me-1"></i>' . Yii::t('app', 'Registra pagamento'), ['payment-create'], ['class' => 'btn btn-primary']) ?>
            <?php endif; ?>
            <?php $export = ActiveForm::begin(['id' => 'export-form', 'action' => ['export'], 'method' => 'post']); ?>
            <?= Html::hiddenInput('queryParams', $queryParams) ?>
            <?= Html::submitButton('<i class="fas fa-file-excel me-1"></i>' . Yii::t('app', 'Esporta'), ['class' => 'btn btn-success']) ?>
            <?php ActiveForm::end(); ?>
        </div>
    </div>
    <div class="card-body border-bottom">
        <?php $form = ActiveForm::begin(['method' => 'get', 'action' => ['payments'], 'options' => ['class' => 'row g-2 align-items-end']]); ?>
        <div class="col-md-4"><?= $form->field($searchModel, 'q')->textInput(['placeholder' => Yii::t('app', 'Nome o email')]) ?></div>
        <div class="col-md-2"><?= $form->field($searchModel, 'year')->dropDownList($years, ['prompt' => Yii::t('app', 'Tutti')]) ?></div>
        <div class="col-md-2"><?= $form->field($searchModel, 'payment_method_id')->dropDownList($methods, ['prompt' => Yii::t('app', 'Tutti')]) ?></div>
        <div class="col-md-2"><?= $form->field($searchModel, 'status')->dropDownList($statuses, ['prompt' => Yii::t('app', 'Tutti')]) ?></div>
        <div class="col-md-2 mb-3"><?= Html::submitButton(Yii::t('app', 'Filtra'), ['class' => 'btn btn-primary w-100']) ?></div>
        <?php ActiveForm::end(); ?>
        <div class="fw-bold" data-napay-totals>
            <?= Html::encode(Yii::t('app', '{n} pagamenti, totale {amount}', ['n' => $totals['count'], 'amount' => Yii::$app->formatter->asCurrency($totals['amount'], 'EUR')])) ?>
        </div>
    </div>
    <div class="table-responsive">
        <?= $this->render('_payments-table', ['payments' => $dataProvider->getModels(), 'showMember' => true]) ?>
    </div>
    <div class="card-footer d-flex align-items-center">
        <div class="ms-auto"><?= LinkPager::widget(['pagination' => $dataProvider->pagination]) ?></div>
    </div>
</div>
