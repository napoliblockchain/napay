<?php

use yii\helpers\Html;

/* @var $payment array riga incasso (method, state, expires_at) */
// SIP-033: stati dei pagamenti BTC (BTCPay)
if (($payment['method'] ?? 'tts') === 'btc') {
    [$class, $label] = match ($payment['state']) {
        'processing' => ['bg-yellow-lt', Yii::t('app', 'Received')],
        'settled' => ['bg-success-lt', Yii::t('app', 'Confirmed')],
        'expired' => ['bg-secondary-lt', Yii::t('app', 'Expired')],
        'invalid' => ['bg-red-lt', Yii::t('app', 'Not valid')],
        'failed' => ['bg-red-lt', Yii::t('app', 'Not created')],
        'reconcile_required' => ['bg-orange-lt', Yii::t('app', 'Unknown outcome')],
        default => ['bg-blue-lt', Yii::t('app', 'Pending')],
    };
    echo Html::tag('span', Html::encode($label), ['class' => 'badge ' . $class]);
    return;
}
// in attesa oltre la scadenza: esito non ancora verificato sulla chain (lo chiude il comando di pulizia o il POS)
$late = $payment['state'] === 'pending' && (int) $payment['expires_at'] < time();
[$class, $label] = match (true) {
    $late => ['bg-orange-lt', Yii::t('app', 'Being verified')],
    // SIP-031: pagato su un wallet il cui seed è stato perso
    $payment['state'] === 'paid' && (int) ($payment['lost'] ?? 0) === 1 => ['bg-dark-lt', Yii::t('app', 'Paid: lost funds')],
    $payment['state'] === 'paid' => ['bg-success-lt', Yii::t('app', 'Paid')],
    $payment['state'] === 'pending' => ['bg-blue-lt', Yii::t('app', 'Pending')],
    $payment['state'] === 'expired' => ['bg-secondary-lt', Yii::t('app', 'Expired')],
    default => ['bg-red-lt', Yii::t('app', 'Cancelled')],
};
echo Html::tag('span', Html::encode($label), ['class' => 'badge ' . $class]);
