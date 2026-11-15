<?php

use yii\helpers\Html;
use yii\helpers\Url;
use app\assets\MerchantCityAsset;
use yii\widgets\ActiveForm;
use app\components\User;

/* @var $this yii\web\View */
/* @var $model app\models\Commercianti */
/* @var $form yii\widgets\ActiveForm */

MerchantCityAsset::register($this);
?>

<div class="commercianti-form">

    <?php $form = ActiveForm::begin(); ?>

    <div class="txt-left">
        <?= $form->errorSummary($model, ['id' => 'error-summary', 'class' => 'col-lg-12 callout callout-warning text-warning']) ?>
    </div>

    <?php if ($model->isNewRecord && User::isAdministrator()) : ?>
        <?= $form->field($model, 'user_id')->dropDownList(
            $users_list,
            [
                'prompt' => Yii::t('app', 'Select a user'),
                'id' => 'user_id',
            ]
        ); ?>
    <?php else : ?>
        <?= $form->field($model, 'user_id')->hiddenInput(['value' => $model->isNewRecord ? Yii::$app->user->id : $model->user_id])->label(false) ?>
    <?php endif; ?>

    <?= $form->field($model, 'description')->textInput(['maxlength' => true]) ?>
    <?= $form->field($model, 'vatNumber')->textInput(['maxlength' => true, 'readonly' => !$model->isNewRecord, 'inputmode' => 'numeric']) ?>

    <?= $form->field($model, 'addressStreet')->textInput(['maxlength' => true, 'placeholder' => Yii::t('app', 'e.g. Via Roma 1')]) ?>
    <?= $form->field($model, 'addressZip')->textInput(['maxlength' => 5, 'inputmode' => 'numeric']) ?>
    <?php
    // città mostrata con la sigla della provincia, come nei suggerimenti ISTAT
    $city = $model->addressProvince !== '' && $model->addressProvince !== null && !$model->hasErrors('addressCity')
        ? $model->addressCity . ' (' . $model->addressProvince . ')' : $model->addressCity;
    ?>
    <?= $form->field($model, 'addressCity')->textInput(['maxlength' => true, 'value' => $city, 'list' => 'napay-city-options', 'autocomplete' => 'off',
        'data-cities-url' => Url::to(['/merchants/cities'])]) ?>
    <datalist id="napay-city-options"></datalist>

    <?= $form->field($model, 'phone')->textInput(['maxlength' => true, 'inputmode' => 'tel'])->hint(Yii::t('app', 'Optional')) ?>
    <?= $form->field($model, 'mobile')->textInput(['maxlength' => true, 'inputmode' => 'tel'])->hint(Yii::t('app', 'Optional')) ?>
    <?php if ($model->isNewRecord) : ?>
        <p class="text-secondary"><?= Html::encode(Yii::t('app', 'The company email is the one used for registration.')) ?></p>
    <?php endif; ?>

    <div class="form-group">
        <?= Html::submitButton('<i class="fa fa-save"></i> ' . Yii::t('app', 'Save'), ['class' => 'btn btn-success']) ?>
    </div>

    <?php ActiveForm::end(); ?>

</div>
