<?php

use yii\helpers\Html;
use app\widgets\TablerGridView as GridView;

/* @var $this yii\web\View */
/* @var $payments yii\data\ActiveDataProvider */
/* @var $filters array from, to, store (ID cifrato), state */
/* @var $stores array ID negozio cifrato => etichetta */
/* @var $admin bool */

$this->title = Yii::t('app', 'POS payments');
$this->params['breadcrumbs'][] = $this->title;
$states = ['pending' => Yii::t('app', 'Pending'), 'paid' => Yii::t('app', 'Paid'), 'expired' => Yii::t('app', 'Expired'), 'cancelled' => Yii::t('app', 'Cancelled')];
$query = array_filter($filters, static fn($v) => $v !== '');
$columns = [
    ['label' => Yii::t('app', 'Date'), 'value' => static fn($p) => Yii::$app->formatter->asDatetime((int) $p['created_at'], 'php:d/m/Y H:i')],
    ['label' => Yii::t('app', 'Store - POS'), 'value' => static fn($p) => $p['store'] . ' - ' . $p['terminal']],
    ['label' => Yii::t('app', 'Operator'), 'value' => static fn($p) => trim($p['first_name'] . ' ' . $p['last_name'])],
    ['label' => Yii::t('app', 'Amount'), 'contentOptions' => ['class' => 'text-end text-nowrap'], 'headerOptions' => ['class' => 'text-end'],
        'value' => static fn($p) => Yii::$app->formatter->asDecimal((int) $p['amount_cents'] / 100, 2) . ' TTS'],
    ['label' => Yii::t('app', 'Status'), 'format' => 'raw', 'value' => fn($p) => $this->render('_state', ['payment' => $p])],
    ['label' => Yii::t('app', 'Transaction'), 'format' => 'raw', 'value' => static fn($p) => $p['tx_hash'] === null ? '&ndash;'
        : Html::tag('code', Html::encode(substr($p['tx_hash'], 0, 10) . '…' . substr($p['tx_hash'], -6)), ['title' => $p['tx_hash']])],
    ['format' => 'raw', 'contentOptions' => ['class' => 'text-end w-1'], 'value' => static fn($p) => Html::a('<i class="fa fa-eye" aria-hidden="true"></i>',
        ['view', 'id' => $p['public_id']], ['class' => 'btn btn-sm btn-outline-secondary', 'title' => Yii::t('app', 'Details'), 'aria-label' => Yii::t('app', 'Details')])],
];
if ($admin) array_unshift($columns, ['label' => Yii::t('app', 'Merchant'), 'value' => 'merchant']);
?>
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><?= Html::encode($this->title) ?></h3>
        <div class="card-actions">
            <?= Html::a('<i class="fas fa-file-csv me-1" aria-hidden="true"></i>' . Html::encode(Yii::t('app', 'Export')), ['export'] + $query, ['class' => 'btn btn-outline-success']) ?>
        </div>
    </div>
    <div class="card-body border-bottom">
        <?= Html::beginForm(['index'], 'get', ['class' => 'row g-2 align-items-end']) ?>
        <div class="col-6 col-md-2">
            <?= Html::label(Html::encode(Yii::t('app', 'From')), 'payments-from', ['class' => 'form-label']) ?>
            <?= Html::input('date', 'from', $filters['from'], ['class' => 'form-control', 'id' => 'payments-from']) ?>
        </div>
        <div class="col-6 col-md-2">
            <?= Html::label(Html::encode(Yii::t('app', 'To')), 'payments-to', ['class' => 'form-label']) ?>
            <?= Html::input('date', 'to', $filters['to'], ['class' => 'form-control', 'id' => 'payments-to']) ?>
        </div>
        <div class="col-md-4">
            <?= Html::label(Html::encode(Yii::t('app', 'Store')), 'payments-store', ['class' => 'form-label']) ?>
            <?= Html::dropDownList('store', $filters['store'], $stores, ['class' => 'form-select', 'id' => 'payments-store', 'prompt' => Yii::t('app', 'All stores')]) ?>
        </div>
        <div class="col-md-2">
            <?= Html::label(Html::encode(Yii::t('app', 'Status')), 'payments-state', ['class' => 'form-label']) ?>
            <?= Html::dropDownList('state', $filters['state'], $states, ['class' => 'form-select', 'id' => 'payments-state', 'prompt' => Yii::t('app', 'All')]) ?>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <?= Html::submitButton(Html::encode(Yii::t('app', 'Filter')), ['class' => 'btn btn-primary flex-fill']) ?>
            <?= Html::a(Html::encode(Yii::t('app', 'Reset')), ['index'], ['class' => 'btn btn-outline-secondary']) ?>
        </div>
        <?= Html::endForm() ?>
    </div>
    <?= GridView::widget([
        'dataProvider' => $payments,
        'columns' => $columns,
        'emptyText' => Yii::t('app', 'No payments.'),
    ]) ?>
</div>
