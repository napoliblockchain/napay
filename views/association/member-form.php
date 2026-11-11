<?php

use yii\helpers\Html;
use yii\bootstrap5\ActiveForm;
use app\components\Crypt;

/* @var $this yii\web\View */
/* @var $user app\models\Users */
/* @var $profile app\models\MemberProfiles */
/* @var $roles array */

$this->title = Yii::t('app', 'Profilo socio: {name}', ['name' => trim($user->last_name . ' ' . $user->first_name)]);
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Soci'), 'url' => ['members']];
$this->params['breadcrumbs'][] = ['label' => trim($user->last_name . ' ' . $user->first_name), 'url' => ['member', 'id' => Crypt::encrypt($user->id)]];
$this->params['breadcrumbs'][] = Yii::t('app', 'Modifica');
?>
<?= $this->render('_readonly') ?>
<div class="card">
    <div class="card-header"><h3 class="card-title"><?= Html::encode($this->title) ?></h3></div>
    <div class="card-body">
        <?php $form = ActiveForm::begin(['id' => 'member-form']); ?>
        <div class="row">
            <div class="col-md-4"><?= $form->field($profile, 'association_role_id')->dropDownList($roles, ['prompt' => Yii::t('app', 'Nessuna')]) ?></div>
            <div class="col-md-2 pt-4"><?= $form->field($profile, 'is_corporate')->checkbox() ?></div>
            <div class="col-md-6"><?= $form->field($profile, 'denomination')->textInput(['maxlength' => 250]) ?></div>
            <div class="col-md-4"><?= $form->field($profile, 'vat')->textInput(['maxlength' => 250]) ?></div>
            <div class="col-md-8"><?= $form->field($profile, 'address')->textInput(['maxlength' => 250]) ?></div>
            <div class="col-md-2"><?= $form->field($profile, 'cap')->textInput(['maxlength' => 250]) ?></div>
            <div class="col-md-6"><?= $form->field($profile, 'city')->textInput(['maxlength' => 250]) ?></div>
            <div class="col-md-4"><?= $form->field($profile, 'country')->textInput(['maxlength' => 250]) ?></div>
        </div>
        <?= Html::submitButton('<i class="fa fa-save me-1"></i>' . Yii::t('app', 'Salva'), ['class' => 'btn btn-success']) ?>
        <?php ActiveForm::end(); ?>
    </div>
</div>
