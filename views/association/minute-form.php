<?php

use yii\helpers\Html;
use yii\bootstrap5\ActiveForm;
use app\components\Crypt;

/* @var $this yii\web\View */
/* @var $minute app\models\AssemblyMinutes */

$this->title = $minute->isNewRecord ? Yii::t('app', 'Nuovo verbale') : Yii::t('app', 'Modifica verbale');
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Verbali'), 'url' => ['minutes']];
$this->params['breadcrumbs'][] = $this->title;
?>
<?= $this->render('_readonly') ?>
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><?= Html::encode($this->title) ?></h3>
        <?php if (!$minute->isNewRecord): ?>
            <div class="card-actions"><?= $this->render('_delete', ['action' => ['minute-delete', 'id' => Crypt::encrypt($minute->id)], 'label' => Yii::t('app', 'Elimina')]) ?></div>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php $form = ActiveForm::begin(['id' => 'minute-form', 'options' => ['enctype' => 'multipart/form-data']]); ?>
        <?= $form->field($minute, 'meeting_date')->input('date') ?>
        <?= $form->field($minute, 'description')->textarea(['rows' => 3, 'maxlength' => 500]) ?>
        <div class="mb-3">
            <?= Html::label(Yii::t('app', 'PDF del verbale (max 20 MB)'), 'minute_file', ['class' => 'form-label']) ?>
            <?= Html::fileInput('minute_file', null, ['id' => 'minute_file', 'accept' => 'application/pdf', 'class' => 'form-control' . ($minute->hasErrors('file_name') ? ' is-invalid' : '')]) ?>
            <?php if ($minute->hasErrors('file_name')): ?><div class="invalid-feedback d-block"><?= Html::encode($minute->getFirstError('file_name')) ?></div><?php endif; ?>
            <?php if (!$minute->isNewRecord && $minute->file_sha256): ?><div class="form-hint"><?= Yii::t('app', 'Lascia vuoto per mantenere il PDF attuale.') ?></div><?php endif; ?>
        </div>
        <?= Html::submitButton('<i class="fa fa-save me-1"></i>' . Yii::t('app', 'Salva'), ['class' => 'btn btn-success']) ?>
        <?php ActiveForm::end(); ?>
    </div>
</div>
