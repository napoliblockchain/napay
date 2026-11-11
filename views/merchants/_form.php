<?php

use yii\helpers\Html;
use yii\widgets\ActiveForm;
use app\components\User;

/* @var $this yii\web\View */
/* @var $model app\models\Commercianti */
/* @var $form yii\widgets\ActiveForm */

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
                'prompt' => Yii::t('app', 'Seleziona un utente'),
                'id' => 'user_id',
            ]
        ); ?>
    <?php else : ?>
        <?= $form->field($model, 'user_id')->hiddenInput(['value' => $model->isNewRecord ? Yii::$app->user->id : $model->user_id])->label(false) ?>
    <?php endif; ?>

    <?= $form->field($model, 'description')->textInput(['maxlength' => true]) ?>
    <?= $form->field($model, 'vatNumber')->textInput(['maxlength' => true, 'readonly' => !$model->isNewRecord]) ?>

    <?= $form->field($model, 'addressStreet')->textInput(['maxlength' => true]) ?>
    <?= $form->field($model, 'addressNumberHouse')->textInput(['maxlength' => true]) ?>
    <?= $form->field($model, 'addressZip')->textInput(['maxlength' => true]) ?>
    <?= $form->field($model, 'addressCity')->textInput(['maxlength' => true]) ?>
    <?= $form->field($model, 'addressProvince')->textInput(['maxlength' => true]) ?>
    <?= $form->field($model, 'addressCountry')->textInput(['maxlength' => true]) ?>

    <?= $form->field($model, 'email')->textInput(['maxlength' => true]) ?>
    <?= $form->field($model, 'phone')->textInput(['maxlength' => true]) ?>
    <?= $form->field($model, 'mobile')->textInput(['maxlength' => true]) ?>

    <div class="form-group">
        <?= Html::submitButton('<i class="fa fa-save"></i> ' . Yii::t('app', 'Salva'), ['class' => 'btn btn-success']) ?>
    </div>

    <?php ActiveForm::end(); ?>

</div>
