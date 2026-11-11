<?php

use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $payment app\models\MembershipPayments */

$this->title = Yii::t('app', 'Conferma pagamento');
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Paga quota'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
$f = Yii::$app->formatter;
?>
<div class="card col-lg-6">
    <div class="card-header"><h3 class="card-title"><?= Html::encode($this->title) ?></h3></div>
    <div class="card-body">
        <p><?= Html::encode($payment->fee->description ?? '') ?>:
            <?= Html::encode($f->asDate($payment->starts_on, 'php:d/m/Y') . ' - ' . $f->asDate($payment->expires_on, 'php:d/m/Y')) ?></p>
        <div class="h1 mb-3"><?= $f->asCurrency($payment->amount, 'EUR') ?></div>
        <p class="text-secondary"><?= Yii::t('app', 'Hai autorizzato il pagamento su PayPal: confermalo per completarlo.') ?></p>
        <?= Html::beginForm(['paypal-capture'], 'post') ?>
        <?= Html::hiddenInput('token', $payment->paypal_order_id) ?>
        <?= Html::submitButton('<i class="fas fa-check me-1"></i>' . Yii::t('app', 'Conferma e paga'), ['class' => 'btn btn-success w-100']) ?>
        <?= Html::endForm() ?>
        <?= Html::a(Yii::t('app', 'Annulla'), ['index'], ['class' => 'btn btn-link w-100 mt-2']) ?>
    </div>
</div>
