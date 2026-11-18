<?php

use yii\helpers\Html;
use yii\bootstrap5\ActiveForm;
use app\components\User;

/* @var $this yii\web\View */
/* @var $model app\models\Stores */

?>

<div class="stores-form">

    <?php $form = ActiveForm::begin(); ?>

    <?php if (User::isAdministrator()) : ?>
        <?= $form->field($model, 'merchant_id')->dropDownList(
            $merchants_list,
            [
                'prompt' => Yii::t('app', 'Seleziona un esercente'),
                'id' => 'merchant_id',
            ]
        ); ?>
    <?php else : ?>
        <?= $form->field($model, 'merchant_id')->hiddenInput(['value' => User::getMerchantId()])->label(false) ?>
    <?php endif; ?>

    <?= $form->field($model, 'description')->textInput(['maxlength' => true]) ?>


    <div class="form-group">
        <?= Html::submitButton('<i class="fa fa-save"></i> ' . Yii::t('app', 'Salva'), ['class' => 'btn btn-success']) ?>
    </div>

    <?php ActiveForm::end(); ?>

</div>