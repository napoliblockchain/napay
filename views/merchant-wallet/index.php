<?php

use yii\helpers\Html;
use yii\bootstrap5\ActiveForm;

/* @var $this yii\web\View */
/* @var $form app\models\forms\pos\TtsWalletForm */
/* @var $current array|null wallet salvato: address, updated_at */
/* @var $merchants array ID merchant cifrato => nome (solo admin) */
/* @var $merchant string|null merchant selezionato (ID cifrato, solo admin) */
/* @var $selected bool merchant determinato */

$this->title = Yii::t('app', 'TTS wallet');
$this->params['breadcrumbs'][] = $this->title;
?>
<?php if ($merchants !== []): ?>
<div class="card mb-3">
    <div class="card-body">
        <?= Html::beginForm(['index'], 'get', ['class' => 'd-flex gap-2']) ?>
        <?= Html::dropDownList('merchant', $merchant, $merchants, ['class' => 'form-select', 'prompt' => Yii::t('app', 'Select the merchant'), 'aria-label' => Yii::t('app', 'Merchant')]) ?>
        <?= Html::submitButton(Html::encode(Yii::t('app', 'Open')), ['class' => 'btn btn-outline-secondary']) ?>
        <?= Html::endForm() ?>
    </div>
</div>
<?php endif; ?>
<?php if ($selected): ?>
<div class="card">
    <div class="card-header"><h3 class="card-title"><?= Html::encode($this->title) ?></h3></div>
    <div class="card-body">
        <p class="text-secondary"><?= Html::encode(Yii::t('app', 'All POS terminals of the merchant receive TTS payments on this address.')) ?></p>
        <div class="mb-3">
            <span class="form-label"><?= Html::encode(Yii::t('app', 'Current address')) ?></span>
            <div class="text-break"><?= $current === null ? Html::encode(Yii::t('app', 'Not set: TTS payments are disabled.')) : Html::tag('code', Html::encode($current['address'])) ?></div>
        </div>
        <?php $f = ActiveForm::begin(['id' => 'tts-wallet-form']); ?>
        <?= $f->field($form, 'address')->textInput(['maxlength' => 42, 'autocomplete' => 'off', 'spellcheck' => 'false', 'placeholder' => '0x…']) ?>
        <div class="form-hint mb-3"><?= Html::encode(Yii::t('app', 'New payment requests use the new address; requests already created keep the previous one.')) ?></div>
        <?= Html::submitButton('<i class="fa fa-save me-1"></i>' . Html::encode(Yii::t('app', 'Save')), ['class' => 'btn btn-success']) ?>
        <?php ActiveForm::end(); ?>
    </div>
</div>
<?php endif; ?>
