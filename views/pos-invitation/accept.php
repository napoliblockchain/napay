<?php

use yii\helpers\Html;
use yii\bootstrap5\ActiveForm;

/* @var $this yii\web\View */
/* @var $form app\models\forms\pos\AcceptInvitationForm */
/* @var $invitation array{merchant: string, email: string} */
/* @var $error string|null */

$this->title = Yii::t('app', 'Invito operatore POS');
ob_start();
?>
<p><?= Html::encode(Yii::t('app', 'Sei stato invitato come operatore di cassa di {merchant}.', ['merchant' => $invitation['merchant']])) ?></p>
<p class="text-secondary"><?= Html::encode(Yii::t('app', 'Verrà creato un account dedicato alla cassa, separato da un eventuale account socio. Il gestore lo abiliterà e ti assegnerà i negozi.')) ?></p>
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
<?= Html::submitButton(Html::encode(Yii::t('app', 'Crea account operatore')), ['class' => 'btn btn-primary w-100']) ?>
<?php ActiveForm::end(); ?>
<?php
echo $this->render('_card', ['title' => $this->title, 'content' => ob_get_clean()]);
