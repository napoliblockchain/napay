<?php

use yii\helpers\Html;
use yii\bootstrap5\ActiveForm;

/* @var $this yii\web\View */
/* @var $form app\models\forms\pos\AcceptInvitationForm */
/* @var $invitation array{merchant: string, email: string} */
/* @var $error string|null */

$this->title = Yii::t('app', 'POS operator invitation');
ob_start();
?>
<p><?= Html::encode(Yii::t('app', 'You have been invited as a cashier of {merchant}.', ['merchant' => $invitation['merchant']])) ?></p>
<p class="text-secondary"><?= Html::encode(Yii::t('app', 'A dedicated cashier account will be created, separate from any member account. The manager will enable it and assign you the shops.')) ?></p>
<?php if ($error !== null): ?><div class="alert alert-danger" role="status"><?= Html::encode($error) ?></div><?php endif; ?>
<?php $f = ActiveForm::begin(['id' => 'pos-invitation-form']); ?>
<?= Html::activeHiddenInput($form, 'token') ?>
<div class="mb-3">
    <label class="form-label" for="invitation-email"><?= Html::encode(Yii::t('app', 'Email')) ?></label>
    <input class="form-control" id="invitation-email" type="email" value="<?= Html::encode($invitation['email']) ?>" readonly>
</div>
<?= $f->field($form, 'first_name')->textInput(['maxlength' => 256, 'autocomplete' => 'given-name']) ?>
<?= $f->field($form, 'last_name')->textInput(['maxlength' => 256, 'autocomplete' => 'family-name']) ?>
<?= $f->field($form, 'password')->passwordInput(['maxlength' => 72, 'autocomplete' => 'new-password']) ?>
<?= $f->field($form, 'repeat_password')->passwordInput(['maxlength' => 72, 'autocomplete' => 'new-password']) ?>
<?= Html::submitButton(Html::encode(Yii::t('app', 'Create operator account')), ['class' => 'auth-submit']) ?>
<?php ActiveForm::end(); ?>
<?php
echo $this->render('_card', ['title' => $this->title, 'content' => ob_get_clean()]);
