<?php

use yii\helpers\Html;
use yii\bootstrap5\ActiveForm;
use app\components\Crypt;
use app\models\MembershipPayments;

/* @var $this yii\web\View */
/* @var $payment app\models\MembershipPayments */
/* @var $users array */
/* @var $fees array */
/* @var $methods array */

$this->title = $payment->isNewRecord ? Yii::t('app', 'Registra pagamento') : Yii::t('app', 'Modifica pagamento {n}', ['n' => $payment->receiptLabel()]);
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Quote associative'), 'url' => ['payments']];
$this->params['breadcrumbs'][] = $this->title;
$statuses = array_map(fn($label) => Yii::t('app', $label), MembershipPayments::STATUS_LABELS);
?>
<?= $this->render('_readonly') ?>
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><?= Html::encode($this->title) ?></h3>
        <?php if (!$payment->isNewRecord): ?>
            <div class="card-actions"><?= $this->render('_delete', ['action' => ['payment-delete', 'id' => Crypt::encrypt($payment->id)], 'label' => Yii::t('app', 'Elimina')]) ?></div>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php $form = ActiveForm::begin(['id' => 'payment-form']); ?>
        <div class="row">
            <div class="col-md-6"><?= $form->field($payment, 'user_id')->dropDownList($users, ['prompt' => Yii::t('app', 'Seleziona il socio')]) ?></div>
            <div class="col-md-3"><?= $form->field($payment, 'fee_id')->dropDownList($fees) ?></div>
            <div class="col-md-3"><?= $form->field($payment, 'amount')->input('number', ['step' => '0.01', 'min' => 0]) ?></div>
            <div class="col-md-3"><?= $form->field($payment, 'payment_method_id')->dropDownList($methods) ?></div>
            <div class="col-md-3"><?= $form->field($payment, 'status')->dropDownList($statuses) ?></div>
            <div class="col-md-2"><?= $form->field($payment, 'registered_on')->input('date') ?></div>
            <div class="col-md-2"><?= $form->field($payment, 'starts_on')->input('date') ?></div>
            <div class="col-md-2"><?= $form->field($payment, 'expires_on')->input('date') ?></div>
            <div class="col-md-6"><?= $form->field($payment, 'paypal_txn_id')->textInput(['maxlength' => 60]) ?></div>
            <div class="col-md-6"><?= $form->field($payment, 'btcpay_invoice_id')->textInput(['maxlength' => 60]) ?></div>
        </div>
        <p class="text-secondary small"><?= Html::encode(Yii::t('app', 'Il numero di ricevuta è assegnato automaticamente quando il pagamento risulta pagato.')) ?></p>
        <?= Html::submitButton('<i class="fa fa-save me-1"></i>' . Yii::t('app', 'Salva'), ['class' => 'btn btn-success']) ?>
        <?php ActiveForm::end(); ?>
    </div>
</div>
