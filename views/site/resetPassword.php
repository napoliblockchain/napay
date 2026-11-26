<?php

/** @var yii\web\View $this */
/** @var app\models\forms\ResetPasswordForm $model */

use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;

$this->title = Yii::t('app', 'Choose a new password');
?>
<h1 class="auth-title"><?= Html::encode($this->title) ?></h1>
<p class="auth-subtitle"><?= Html::encode(Yii::t('app', 'Enter the new password twice to confirm it.')) ?></p>

<?php $form = ActiveForm::begin(['id' => 'login-form']) ?>
<?= $form->field($model, 'password')->passwordInput(['autocomplete' => 'new-password']) ?>
<?= $form->field($model, 'repeat_password')->passwordInput(['autocomplete' => 'new-password']) ?>
<?= Html::submitButton(Html::encode(Yii::t('app', 'Save password')), ['class' => 'auth-submit']) ?>
<?php ActiveForm::end(); ?>
<div class="auth-links">
    <?= Html::a(Html::encode(Yii::t('app', 'Back to login')), ['site/login']) ?>
</div>
