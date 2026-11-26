<?php

use yii\helpers\Html;
use yii\bootstrap5\ActiveForm;

/* @var $this yii\web\View */
/* @var $model yii\db\ActiveRecord */
/* @var $type string role|fee|method */
/* @var $fields string[] */

$names = ['role' => Yii::t('app', 'Carica'), 'fee' => Yii::t('app', 'Quota'), 'method' => Yii::t('app', 'Metodo di pagamento')];
$this->title = ($model->isNewRecord ? Yii::t('app', 'Nuova voce') : Yii::t('app', 'Modifica')) . ': ' . $names[$type];
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Tabelle associazione'), 'url' => ['tables']];
$this->params['breadcrumbs'][] = $this->title;
?>
<?= $this->render('_readonly') ?>
<div class="card">
    <div class="card-header"><h3 class="card-title"><?= Html::encode($this->title) ?></h3></div>
    <div class="card-body">
        <?php $form = ActiveForm::begin(['id' => 'table-form']); ?>
        <?php foreach ($fields as $field): ?>
            <?php if ($field === 'min_level'): ?>
                <?= $form->field($model, $field)->dropDownList([0 => Yii::t('app', 'anche online'), 20 => Yii::t('app', 'solo registrazione manuale')]) ?>
            <?php elseif ($field === 'amount'): ?>
                <?= $form->field($model, $field)->input('number', ['step' => '0.01', 'min' => 0]) ?>
            <?php elseif ($field === 'duration'): ?>
                <?= $form->field($model, $field)->input('number', ['min' => 0, 'max' => 10]) ?>
            <?php else: ?>
                <?= $form->field($model, $field)->textInput(['maxlength' => true]) ?>
            <?php endif; ?>
        <?php endforeach; ?>
        <?= Html::submitButton('<i class="fa fa-save me-1"></i>' . Yii::t('app', 'Salva'), ['class' => 'btn btn-success']) ?>
        <?php ActiveForm::end(); ?>
    </div>
</div>
