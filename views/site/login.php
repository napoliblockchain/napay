<?php

/** @var yii\web\View $this */
/** @var app\models\forms\LoginForm $model */

use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;
use yii\helpers\Url;

$this->title = Yii::t('app', 'Sign in');
?>
<div class="site-login">
    <h1 class="auth-title"><?= Html::encode(Yii::t('app', 'Sign in')) ?></h1>
    <p class="auth-subtitle"><?= Html::encode(Yii::t('app', 'Enter your credentials to continue')) ?></p>

    <?php $form = ActiveForm::begin(['id' => 'login-form']) ?>
    <?= $form->field($model, 'email')->textInput(['type' => 'email', 'autocomplete' => 'username', 'placeholder' => Yii::t('app', 'name@example.com')]) ?>
    <?= $form->field($model, 'password')->passwordInput(['autocomplete' => 'current-password', 'placeholder' => '••••••••']) ?>
    <?= Html::submitButton(Html::encode(Yii::t('app', 'Sign in')), ['class' => 'auth-submit', 'name' => 'login-button']) ?>
    <div class="auth-links">
        <?= Html::a(Html::encode(Yii::t('app', 'I forgot my password')), Url::to(['site/forgot-password'])) ?>
        <?= Html::a(Html::encode(Yii::t('app', 'Register new account')), Url::to(['site/signup'])) ?>
    </div>
    <div class="auth-links">
        <?= Html::a(Html::encode(Yii::t('app', 'Request activation link')), Url::to(['site/resend-activation'])) ?>
        <?= Html::a(Html::encode(Yii::t('app', 'Install to home screen')), '#', ['onclick' => 'saveOnDesktop(); return false;']) ?>
    </div>
    <?php ActiveForm::end(); ?>
</div>
