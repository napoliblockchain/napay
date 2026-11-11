<?php

use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $quote array app\components\association\MembershipFee::quote() */
/* @var $paypal bool */
/* @var $bitcoin bool */
/* @var $readOnly bool */
/* @var $payments app\models\MembershipPayments[] */

$this->title = Yii::t('app', 'Paga quota');
$this->params['breadcrumbs'][] = $this->title;
$f = Yii::$app->formatter;
$pendingBitcoin = array_filter($payments, fn($p) => $p->status === 'pending' && $p->btcpay_invoice_id !== null);
?>
<?php foreach (Yii::$app->session->getAllFlashes() as $type => $messages): ?>
    <?php foreach ((array) $messages as $message): ?>
        <div class="alert alert-<?= in_array($type, ['success', 'danger', 'warning', 'info'], true) ? $type : 'info' ?>" role="status"><?= Html::encode($message) ?></div>
    <?php endforeach; ?>
<?php endforeach; ?>
<div class="row row-cards">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><?= Yii::t('app', 'Quota associativa') ?></h3></div>
            <div class="card-body">
                <?php if ($quote['paid_until']): ?>
                    <p><?= Html::encode(Yii::t('app', 'Quota pagata fino al {date}.', ['date' => $f->asDate($quote['paid_until'], 'php:d/m/Y')])) ?></p>
                <?php endif; ?>
                <?php if ($readOnly): ?>
                    <p class="text-secondary"><?= Yii::t('app', 'Pagamento online temporaneamente non disponibile.') ?></p>
                <?php elseif ($quote['payable']): ?>
                    <div class="h1 mb-1" data-napay-fee-amount><?= $f->asCurrency($quote['amount'], 'EUR') ?></div>
                    <p class="text-secondary">
                        <?= Html::encode($quote['fee']->description) ?>:
                        <?= Html::encode($f->asDate($quote['starts_on'], 'php:d/m/Y') . ' - ' . $f->asDate($quote['expires_on'], 'php:d/m/Y')) ?>
                    </p>
                    <?php if ($paypal): ?>
                        <?= Html::beginForm(['start'], 'post', ['class' => 'mb-2']) ?>
                        <?= Html::hiddenInput('method', 'paypal') ?>
                        <?= Html::submitButton('<i class="fab fa-paypal me-1"></i>' . Yii::t('app', 'Paga con PayPal'), ['class' => 'btn btn-primary w-100']) ?>
                        <?= Html::endForm() ?>
                    <?php endif; ?>
                    <?php if ($bitcoin): ?>
                        <?= Html::beginForm(['start'], 'post', ['class' => 'mb-2']) ?>
                        <?= Html::hiddenInput('method', 'bitcoin') ?>
                        <?= Html::submitButton('<i class="fab fa-bitcoin me-1"></i>' . Yii::t('app', 'Paga con Bitcoin'), ['class' => 'btn btn-warning w-100']) ?>
                        <?= Html::endForm() ?>
                    <?php endif; ?>
                    <?php if (!$paypal && !$bitcoin): ?>
                        <p class="text-secondary"><?= Yii::t('app', 'Pagamento online non disponibile: contatta l\'associazione.') ?></p>
                    <?php endif; ?>
                <?php elseif ($quote['reason'] === 'already_paid'): ?>
                    <p class="text-success"><?= Yii::t('app', 'Sei in regola: anche la quota del prossimo anno è già pagata.') ?></p>
                <?php else: ?>
                    <p class="text-secondary"><?= Yii::t('app', 'Nessuna quota da pagare online: contatta l\'associazione.') ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><?= Yii::t('app', 'I tuoi pagamenti') ?></h3>
                <?php if ($pendingBitcoin && $bitcoin): ?>
                    <div class="card-actions">
                        <?= Html::beginForm(['btcpay-refresh'], 'post') ?>
                        <?= Html::submitButton('<i class="fas fa-sync me-1"></i>' . Yii::t('app', 'Aggiorna stato'), ['class' => 'btn btn-sm btn-outline-secondary']) ?>
                        <?= Html::endForm() ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead><tr><th><?= Yii::t('app', 'Data') ?></th><th><?= Yii::t('app', 'Quota') ?></th><th class="text-end"><?= Yii::t('app', 'Importo') ?></th><th><?= Yii::t('app', 'Metodo') ?></th><th><?= Yii::t('app', 'Stato') ?></th><th><?= Yii::t('app', 'Scadenza') ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><?= $f->asDate($p->registered_on, 'php:d/m/Y') ?></td>
                            <td><?= Html::encode($p->fee->description ?? '') ?></td>
                            <td class="text-end"><?= $f->asCurrency($p->amount, 'EUR') ?></td>
                            <td><?= Html::encode($p->method->description ?? '') ?></td>
                            <td><?= $this->render('/association/_status', ['status' => $p->status, 'label' => $p->statusLabel()]) ?></td>
                            <td><?= $f->asDate($p->expires_on, 'php:d/m/Y') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$payments): ?><tr><td colspan="6" class="text-secondary"><?= Yii::t('app', 'Nessun pagamento.') ?></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
