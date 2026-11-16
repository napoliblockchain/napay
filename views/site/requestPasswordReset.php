<?php

/** @var yii\web\View $this */
/** @var app\models\forms\PasswordResetRequestForm $model */

use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;

$this->title = Yii::t('app', 'Request password reset');
?>
<h1 class="auth-title"><?= Html::encode($this->title) ?></h1>
<p class="auth-subtitle"><?= Html::encode(Yii::t('app', 'Enter your email: we will send you a link to reset the password.')) ?></p>

<?php $form = ActiveForm::begin(['id' => 'login-form']) ?>
<?= $form->field($model, 'email')->textInput(['type' => 'email', 'autocomplete' => 'email', 'placeholder' => Yii::t('app', 'name@example.com')]) ?>
<?= Html::submitButton(Html::encode(Yii::t('app', 'Send link')), ['class' => 'auth-submit']) ?>
<?php ActiveForm::end(); ?>
<div class="auth-links">
    <?= Html::a(Html::encode(Yii::t('app', 'Back to login')), ['site/login']) ?>
</div>
