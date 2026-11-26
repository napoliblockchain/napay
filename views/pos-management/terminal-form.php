<?php

use yii\helpers\Html;
use yii\bootstrap5\ActiveForm;

/* @var $this yii\web\View */
/* @var $form app\models\forms\pos\TerminalForm */
/* @var $stores array ID negozio cifrato => etichetta */
/* @var $terminal array|null terminale in modifica */

$this->title = $terminal === null ? Yii::t('app', 'New terminal') : Yii::t('app', 'Edit terminal');
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'POS terminals'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="card">
    <div class="card-header"><h3 class="card-title"><?= Html::encode($this->title) ?></h3></div>
    <div class="card-body">
        <?php $f = ActiveForm::begin(['id' => 'terminal-form']); ?>
        <?php if ($terminal === null): ?>
            <?= $f->field($form, 'store')->dropDownList($stores, ['prompt' => Yii::t('app', 'Select the store')]) ?>
            <div class="form-hint mb-3"><?= Html::encode(Yii::t('app', 'The terminal starts disabled.')) ?></div>
        <?php else: ?>
            <div class="mb-3"><span class="form-label"><?= Html::encode(Yii::t('app', 'Store')) ?></span><div><?= Html::encode($terminal['store']) ?></div></div>
        <?php endif; ?>
        <?= $f->field($form, 'name')->textInput(['maxlength' => 256]) ?>
        <?= Html::submitButton('<i class="fa fa-save me-1"></i>' . Html::encode(Yii::t('app', 'Save')), ['class' => 'btn btn-success']) ?>
        <?= Html::a(Html::encode(Yii::t('app', 'Cancel')), ['index'], ['class' => 'btn btn-link']) ?>
        <?php ActiveForm::end(); ?>
    </div>
</div>
