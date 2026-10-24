<?php

use yii\helpers\Html;
use yii\helpers\Url;
use app\components\User;
use app\components\Crypt;
use yii\helpers\ArrayHelper;
use app\models\Privileges;

use yii\bootstrap5\ActiveForm;
use kartik\depdrop\DepDrop;

// se l'utente che guarda è lo stesso che viene guardato
if ($model->id == Yii::$app->user->id) {
    $disabled = ['disabled' => "disabled"];
} else {
    $disabled = [];
}
$privilege_list = ArrayHelper::map(Privileges::find()->byLevelLessThen(Yii::$app->user->identity->privilege->level)->all(), 'id', function ($data) {
    return $data->description;
});
// echo '<pre>'. print_r($privilege_list,true) .'</pre>' ;exit;

// se l'utente che guarda è lo stesso che viene guardato
$disabled = [];
if ($model->id == Yii::$app->user->id || Yii::$app->user->identity->privilege->level < $model->privilege->level) {
    $disabled = ['disabled' => "disabled"];
    $privilege_list = ArrayHelper::map(Privileges::find()->all(), 'id', function ($data) {
        return $data->description;
    });
}

$status = [0 => Yii::t('app', 'Disabled'), 1 => Yii::t('app', 'Enabled')];
$color = [0 => 'warning', 1 => 'success'];
$icon = [0 => 'icon fas fa-exclamation-triangle', 1 => 'icon fas fa-check'];

?>
<?php $form = ActiveForm::begin([
    'id' => 'users-form',
    'action' => ['update', 'id' => Crypt::encrypt($model->id)],
]); ?>
<div class="col-xl-12">

    <div class="card">
        <div class="card-body box-profile">
            <div class="col-md-12 col-sm-12 col-12">
                <div class="card-info card-outline info-box shadow-sm">
                    <div class="row">
                        <?php if (!User::isAdministrator()): ?>
                            <div class="col-lg-12 col-sm-12">
                                <p class="text-muted">
                                    <?= $form->field($model, 'privilege_id')->dropDownList(
                                        $privilege_list,
                                        ((User::isAdministrator()) ? $disabled : ['disabled' => 'disabled'])
                                    )->label() ?>
                                </p>
                            </div>
                            <div class="col-lg-12 col-sm-12">
                                <p class="text-muted">
                                    <?= $form->field($model, 'merchant_id')->dropDownList(
                                        $merchants_list,
                                        [
                                            'prompt' => Yii::t('app', 'Select Merchant'), 'id' => 'merchant_id'
                                        ]
                                    ); ?>
                                    <?php echo Html::hiddenInput('selected_id', $model->isNewRecord ? '' : $model->merchant_id, ['id' => 'selected_id']); ?>

                                </p>
                            </div>
                            <div class="col-lg-12 col-sm-12">
                                <?php
                                // echo $form->field($model, 'store_id')->widget(DepDrop::class, [
                                //     'options' => [
                                //         'id' => 'store_id',
                                //         'prompt' => Yii::t('app', 'Store'),
                                //     ],
                                //     'type' => DepDrop::TYPE_SELECT2,
                                //     'select2Options' => ['pluginOptions' => ['allowClear' => true]],
                                //     'pluginOptions' => [
                                //         'initialize' => $model->isNewRecord ? false : true,
                                //         'depends' => ['merchant_id'],
                                //         'placeholder' => Yii::t('app', 'Select Client'),
                                //         'url' => Url::to(['stores/lista-negozi', 'id' => $model->store_id ?? 0])
                                        
                                //         ]
                                        
                                //         ])->label('Negozio');
                                        ?>
                            </div>
                        <?php endif; ?>
                        <div class="col-lg-12 col-sm-12">
                            <?= $form->field($model, 'is_active', ['options' => ['class' => 'shadow p-2 mb-3']])->dropDownList(
                                $status,
                                [
                                    'prompt' => ' ' . Yii::t('app', 'Seleziona stato'), 'id' => 'is_active',
                                ]
                            )->label('<i class="fa fa-user-check mr-1"></i> ' . Yii::t('app', 'Account status')); ?>
                        </div>
                        <div class="col-lg-12 col-sm-12">
                            <p class="ml-auto">
                                <?php if (User::isAdministrator() && $model->id != Yii::$app->user->id && Yii::$app->user->identity->privilege->level >= $model->privilege->level) : ?>
                                    <?= Html::submitButton('<i class="fas fa-save"></i> ' . Yii::t('app', 'Confirm'), [
                                        'class' => 'btn btn-primary',
                                        'data' => [
                                            'confirm' => Yii::t('app', 'Are you sure you want to update user'),
                                        ],
                                    ]) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php ActiveForm::end(); ?>
