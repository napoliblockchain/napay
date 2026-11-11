<?php

use yii\helpers\Html;
use app\assets\AssociationAsset;

/* @var $this yii\web\View */
/* @var $payment app\models\MembershipPayments */
/* @var $association array impostazioni gdpr_* dell'associazione */

AssociationAsset::register($this);
$this->title = Yii::t('app', 'Ricevuta {n}', ['n' => $payment->receiptLabel()]);
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Quote associative'), 'url' => ['payments']];
$this->params['breadcrumbs'][] = $this->title;
$f = Yii::$app->formatter;
$user = $payment->user;
$profile = $payment->profile;
$a = fn($code) => $association[$code] ?? '';
?>
<div class="d-flex mb-3 d-print-none">
    <?= Html::a('<i class="fas fa-arrow-left me-1"></i>' . Yii::t('app', 'Indietro'), ['member', 'id' => \app\components\Crypt::encrypt($payment->user_id)], ['class' => 'btn btn-outline-secondary']) ?>
    <?= Html::button('<i class="fas fa-print me-1"></i>' . Yii::t('app', 'Stampa'), ['class' => 'btn btn-primary ms-auto', 'data-napay-print' => 1]) ?>
</div>
<div class="card card-lg">
    <div class="card-body">
        <div class="row">
            <div class="col-7">
                <p class="h3"><?= Html::encode($a('gdpr_titolare')) ?></p>
                <address>
                    <?= Html::encode($a('gdpr_address')) ?><br>
                    <?= Html::encode(trim($a('gdpr_cap') . ' ' . $a('gdpr_city'))) ?><br>
                    <?php if ($a('gdpr_vat') !== ''): ?><?= Html::encode(Yii::t('app', 'C.F./P.IVA') . ': ' . $a('gdpr_vat')) ?><?php endif; ?>
                </address>
            </div>
            <div class="col-5 text-end">
                <p class="h3"><?= Html::encode(Yii::t('app', 'Ricevuta n. {n}', ['n' => $payment->receiptLabel()])) ?></p>
                <div><?= Html::encode(Yii::t('app', 'Data') . ': ' . $f->asDate($payment->registered_on, 'php:d/m/Y')) ?></div>
                <div><?= $this->render('_status', ['status' => $payment->status, 'label' => $payment->statusLabel()]) ?></div>
            </div>
            <div class="col-12 my-4">
                <div class="text-secondary"><?= Yii::t('app', 'Ricevuto da') ?></div>
                <div class="h4 mb-0"><?= Html::encode($profile && $profile->is_corporate && $profile->denomination ? $profile->denomination : trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))) ?></div>
                <?php if ($profile): ?>
                    <div><?= Html::encode(trim(implode(' ', array_filter([$profile->address, $profile->cap, $profile->city])))) ?></div>
                    <?php if ($profile->vat): ?><div><?= Html::encode(Yii::t('app', 'C.F./P.IVA') . ': ' . $profile->vat) ?></div><?php endif; ?>
                <?php endif; ?>
                <div><?= Html::encode($user->email ?? '') ?></div>
            </div>
        </div>
        <table class="table table-transparent table-responsive">
            <thead><tr><th><?= Yii::t('app', 'Descrizione') ?></th><th><?= Yii::t('app', 'Periodo') ?></th><th class="text-end"><?= Yii::t('app', 'Importo') ?></th></tr></thead>
            <tbody>
            <tr>
                <td><?= Html::encode($payment->fee->description ?? '') ?></td>
                <td><?= Html::encode($f->asDate($payment->starts_on, 'php:d/m/Y') . ' - ' . $f->asDate($payment->expires_on, 'php:d/m/Y')) ?></td>
                <td class="text-end"><?= $f->asCurrency($payment->amount, 'EUR') ?></td>
            </tr>
            <tr><td colspan="2" class="strong text-end"><?= Yii::t('app', 'Totale') ?></td><td class="fw-bold text-end"><?= $f->asCurrency($payment->amount, 'EUR') ?></td></tr>
            </tbody>
        </table>
        <p class="text-secondary">
            <?= Html::encode(Yii::t('app', 'Metodo di pagamento') . ': ' . ($payment->method->description ?? '')) ?>
            <?php if ($payment->paypal_txn_id): ?><br><?= Html::encode('PayPal ID: ' . $payment->paypal_txn_id) ?><?php endif; ?>
            <?php if ($payment->btcpay_invoice_id): ?><br><?= Html::encode(Yii::t('app', 'Transazione') . ': ' . $payment->btcpay_invoice_id) ?><?php endif; ?>
        </p>
    </div>
</div>
