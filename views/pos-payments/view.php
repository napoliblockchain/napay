<?php

use yii\helpers\Html;
use yii\widgets\DetailView;

/* @var $this yii\web\View */
/* @var $payment array */
/* @var $admin bool */

$this->title = Yii::t('app', 'Payment details');
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'POS payments'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
$time = static fn($t) => $t === null ? null : Yii::$app->formatter->asDatetime((int) $t, 'php:d/m/Y H:i:s');
$attributes = [
    ['label' => Yii::t('app', 'Amount'), 'value' => Yii::$app->formatter->asDecimal((int) $payment['amount_cents'] / 100, 2) . ' TTS'],
    ['label' => Yii::t('app', 'Status'), 'format' => 'raw', 'value' => $this->render('_state', ['payment' => $payment])],
    ['label' => Yii::t('app', 'Store - POS'), 'value' => $payment['store'] . ' - ' . $payment['terminal']],
    ['label' => Yii::t('app', 'Operator'), 'value' => trim($payment['first_name'] . ' ' . $payment['last_name'])],
    ['label' => Yii::t('app', 'Created at'), 'value' => $time($payment['created_at'])],
    ['label' => Yii::t('app', 'Expires at'), 'value' => $time($payment['expires_at'])],
    ['label' => Yii::t('app', 'Paid at'), 'value' => $time($payment['paid_at'])],
    ['label' => Yii::t('app', 'Closed at'), 'value' => $time($payment['closed_at'])],
    ['label' => Yii::t('app', 'Destination wallet'), 'format' => 'raw', 'value' => Html::tag('code', Html::encode($payment['to_address']))],
    ['label' => Yii::t('app', 'Transaction'), 'format' => 'raw', 'value' => $payment['tx_hash'] === null ? null : Html::tag('code', Html::encode($payment['tx_hash']), ['class' => 'text-break'])],
    ['label' => Yii::t('app', 'Block'), 'value' => $payment['paid_block']],
    ['label' => Yii::t('app', 'ID'), 'value' => $payment['public_id']],
];
if ($admin) array_unshift($attributes, ['label' => Yii::t('app', 'Merchant'), 'value' => $payment['merchant']]);
?>
<div class="card">
    <div class="card-header"><h3 class="card-title"><?= Html::encode($this->title) ?></h3></div>
    <?= DetailView::widget(['model' => $payment, 'options' => ['class' => 'table table-vcenter card-table mb-0'], 'attributes' => $attributes]) ?>
    <div class="card-footer"><?= Html::a('<i class="fa fa-arrow-left me-1" aria-hidden="true"></i>' . Html::encode(Yii::t('app', 'Back')), ['index'], ['class' => 'btn btn-outline-secondary']) ?></div>
</div>
