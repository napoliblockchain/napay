<?php

/** @var yii\web\View $this */
/** @var app\models\forms\ActivationResendForm $model */

use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;

$this->title = Yii::t('app', 'Request activation link');
?>
<h1 class="auth-title"><?= Html::encode($this->title) ?></h1>
<p class="auth-subtitle"><?= Html::encode(Yii::t('app', 'Enter the email you registered with. If the account still has to be activated, you will receive a new link.')) ?></p>

<?php $form = ActiveForm::begin(['id' => 'resend-activation-form']); ?>
<?= $form->field($model, 'email')->textInput(['type' => 'email', 'autocomplete' => 'email', 'maxlength' => 255, 'placeholder' => Yii::t('app', 'name@example.com')]) ?>
<?= Html::submitButton(Html::encode(Yii::t('app', 'Send link')), ['class' => 'auth-submit']) ?>
<?php ActiveForm::end(); ?>
<div class="auth-links">
    <?= Html::a(Html::encode(Yii::t('app', 'Back to login')), ['site/login']) ?>
</div>
