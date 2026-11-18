<?php

use yii\bootstrap5\ActiveForm;
use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $model app\models\Stores */

// SIP-030 punto 4, SIP-033: modificabili nome, valuta e sito; le altre impostazioni BTCPay sono fisse (StoreSettingsPayload::FIXED)
$store = $model->storesettings;
?>
<div class="stores-form">
    <?php $form = ActiveForm::begin(['id' => 'store-general-form']); ?>
    <?= $form->field($model, 'description')->textInput(['maxlength' => 256]) ?>
    <?= $form->field($store, 'defaultCurrency')->dropDownList(app\controllers\StoresController::CURRENCIES) ?>
    <?= $form->field($store, 'website')->textInput(['maxlength' => 255, 'placeholder' => 'https://', 'inputmode' => 'url']) ?>
    <div class="form-group">
        <?= Html::submitButton(Html::encode(Yii::t('app', 'Save')), ['class' => 'btn btn-primary']) ?>
    </div>
    <?php ActiveForm::end(); ?>
</div>
