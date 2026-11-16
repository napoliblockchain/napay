<?php

use yii\bootstrap5\ActiveForm;
use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $model app\models\Stores */

// SIP-030 punto 4: modificabili solo nome e valuta; le altre impostazioni BTCPay sono fisse (StoresController::defaultSettings)
$store = $model->storesettings;
?>
<div class="stores-form">
    <?php $form = ActiveForm::begin(['id' => 'store-general-form']); ?>
    <?= $form->field($model, 'description')->textInput(['maxlength' => 256]) ?>
    <?= $form->field($store, 'defaultCurrency')->dropDownList(app\controllers\StoresController::CURRENCIES) ?>
    <div class="form-group">
        <?= Html::submitButton(Html::encode(Yii::t('app', 'Save')), ['class' => 'btn btn-primary']) ?>
    </div>
    <?php ActiveForm::end(); ?>
</div>
