<?php

use yii\helpers\Html;
use yii\widgets\DetailView;
use app\controllers\PosPaymentsController;

/* @var $this yii\web\View */
/* @var $payment array */
/* @var $admin bool */

$this->title = Yii::t('app', 'Payment details');
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'POS payments'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
$time = static fn($t) => $t === null ? null : Yii::$app->formatter->asDatetime((int) $t, 'php:d/m/Y H:i:s');
$btcAmount = static fn($sats) => $sats === null ? null : PosPaymentsController::btc((int) $sats) . ' BTC';
$attributes = $payment['method'] === 'btc' ? [
    // SIP-033: pagamento BTC (BTCPay); mai ID dello store, dell'app o della fattura
    ['label' => Yii::t('app', 'Method'), 'value' => 'BTC'],
    ['label' => Yii::t('app', 'Amount'), 'value' => Yii::$app->formatter->asDecimal((int) $payment['amount_cents'] / 100, 2) . ' ' . $payment['currency']],
    ['label' => Yii::t('app', 'Status'), 'format' => 'raw', 'value' => $this->render('_state', ['payment' => $payment])],
    ['label' => Yii::t('app', 'Rate (1 BTC)'), 'value' => $payment['rate_cents'] === null ? null : Yii::$app->formatter->asDecimal((int) $payment['rate_cents'] / 100, 2) . ' ' . $payment['currency']],
    ['label' => Yii::t('app', 'Order'), 'value' => $btcAmount($payment['order_satoshis'])],
    ['label' => Yii::t('app', 'Network fee'), 'value' => $payment['order_satoshis'] === null || $payment['amount_satoshis'] === null ? null : $btcAmount((int) $payment['amount_satoshis'] - (int) $payment['order_satoshis'])],
    ['label' => Yii::t('app', 'Requested'), 'value' => $btcAmount($payment['amount_satoshis'])],
    ['label' => Yii::t('app', 'BTC paid'), 'value' => $btcAmount($payment['paid_satoshis'])],
    ['label' => Yii::t('app', 'Confirmations'), 'value' => $payment['confirmations']],
    ['label' => Yii::t('app', 'Store - POS'), 'value' => $payment['store'] . ' - ' . $payment['terminal']],
    ['label' => Yii::t('app', 'Operator'), 'value' => trim($payment['first_name'] . ' ' . $payment['last_name'])],
    ['label' => Yii::t('app', 'Created at'), 'value' => $time($payment['created_at'])],
    ['label' => Yii::t('app', 'Expires at'), 'value' => $time($payment['expires_at'])],
    ['label' => Yii::t('app', 'Received at'), 'value' => $time($payment['paid_at'])],
    ['label' => Yii::t('app', 'Confirmed at'), 'value' => $time($payment['settled_at'])],
    ['label' => Yii::t('app', 'Closed at'), 'value' => $time($payment['closed_at'])],
    ['label' => Yii::t('app', 'Transactions'), 'format' => 'raw', 'value' => $payment['tx_hash'] === null ? null
        : implode('<br>', array_map(static fn($tx) => Html::tag('code', Html::encode($tx), ['class' => 'text-break']), explode(',', $payment['tx_hash'])))],
    ['label' => Yii::t('app', 'Payment address'), 'format' => 'raw', 'value' => $payment['btc_address'] === null ? null : Html::tag('code', Html::encode($payment['btc_address']), ['class' => 'text-break'])],
    ['label' => Yii::t('app', 'Error'), 'value' => $payment['last_error_code']],
    ['label' => Yii::t('app', 'ID'), 'value' => $payment['public_id']],
] : [
    ['label' => Yii::t('app', 'Method'), 'value' => 'TTS'],
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
