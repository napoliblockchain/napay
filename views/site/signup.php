<?php

/** @var yii\web\View $this */
/** @var yii\bootstrap5\ActiveForm $form */
/** @var app\models\forms\SignupForm $model */
/** @var app\models\UserConsensus $consensus */

use app\assets\SignupAsset;
use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;
use yii\helpers\Url;

$this->title = Yii::t('app', 'Sign up');
$memberTypes = ['pf' => Yii::t('app', 'Individual member'), 'pg' => Yii::t('app', 'Corporate member')];
$link = static fn (string $label, string $url): string => Html::a(Html::encode($label), $url, ['target' => '_blank', 'rel' => 'noopener']);
SignupAsset::register($this);
?>
<h1 class="auth-title"><?= Html::encode(Yii::t('app', 'Join the association')) ?></h1>
<p class="auth-subtitle"><?= Html::encode(Yii::t('app', 'Create your account: after the email check the request is reviewed by an administrator.')) ?></p>

<?php $form = ActiveForm::begin(['id' => 'signup-form']) ?>
<?= $form->field($model, 'member_type')->dropDownList($memberTypes, ['prompt' => Yii::t('app', 'Select the membership type'), 'id' => 'member_type']) ?>

<div id="signup-pg-fields">
    <?= $form->field($model, 'denomination')->textInput(['maxlength' => 250]) ?>
    <p class="form-hint"><?= Html::encode(Yii::t('app', 'A legal entity joins as a member. The merchant profile is activated separately after registration.')) ?></p>
</div>

<?= $form->field($model, 'first_name')->textInput(['autocomplete' => 'given-name']) ?>
<?= $form->field($model, 'last_name')->textInput(['autocomplete' => 'family-name']) ?>
<?= $form->field($model, 'email')->textInput(['type' => 'email', 'autocomplete' => 'email', 'placeholder' => Yii::t('app', 'name@example.com')]) ?>
<?= $form->field($model, 'password')->passwordInput(['autocomplete' => 'new-password']) ?>
<?= $form->field($model, 'repeat_password')->passwordInput(['autocomplete' => 'new-password']) ?>

<?= $form->field($consensus, 'consenso_statuto')->checkbox()->label(Yii::t('app', 'I have read the {statute} and agree with every point.', [
    'statute' => $link(Yii::t('app', 'Statute'), 'https://napoliblockchain.it/chi-siamo/statuto-associazione-napoli-blockchain-ets/'),
])) ?>
<?= $form->field($consensus, 'consenso_privacy')->checkbox()->label(Yii::t('app', 'I have read the {privacy} and authorise the processing of my personal data.', [
    'privacy' => $link(Yii::t('app', 'Privacy notice'), Url::to(['site/print-privacy'])),
])) ?>
<?= $form->field($consensus, 'consenso_condizioni')->checkbox()->label(Yii::t('app', 'I accept the {terms} of the software.', [
    'terms' => $link(Yii::t('app', 'Terms of use'), Url::to(['site/term-of-use'])),
])) ?>
<?= $form->field($consensus, 'consenso_marketing')->checkbox()->label(Html::encode(Yii::t('app', 'I agree to the processing of my data for marketing purposes.'))) ?>

<?= Html::submitButton(Html::encode(Yii::t('app', 'Confirm')), ['class' => 'auth-submit']) ?>
<?php ActiveForm::end(); ?>
<div class="auth-links">
    <?= Html::a(Html::encode(Yii::t('app', 'Already registered? Sign in')), ['site/login']) ?>
</div>
