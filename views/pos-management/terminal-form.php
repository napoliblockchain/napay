<?php

use yii\helpers\Html;
use yii\bootstrap5\ActiveForm;

/* @var $this yii\web\View */
/* @var $form app\models\forms\pos\TerminalForm */
/* @var $stores array ID negozio cifrato => etichetta */
/* @var $terminal array|null terminale in modifica */

$this->title = $terminal === null ? Yii::t('app', 'Nuovo terminale') : Yii::t('app', 'Modifica terminale');
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Terminali POS'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<?= $this->render('_flash') ?>
<div class="card">
    <div class="card-header"><h3 class="card-title"><?= Html::encode($this->title) ?></h3></div>
    <div class="card-body">
        <?php $f = ActiveForm::begin(['id' => 'terminal-form']); ?>
        <?php if ($terminal === null): ?>
            <?= $f->field($form, 'store')->dropDownList($stores, ['prompt' => Yii::t('app', 'Seleziona il negozio')]) ?>
            <div class="form-hint mb-3"><?= Html::encode(Yii::t('app', 'Il terminale nasce disabilitato.')) ?></div>
        <?php else: ?>
            <div class="mb-3"><span class="form-label"><?= Html::encode(Yii::t('app', 'Negozio')) ?></span><div><?= Html::encode($terminal['store']) ?></div></div>
        <?php endif; ?>
        <?= $f->field($form, 'name')->textInput(['maxlength' => 256]) ?>
        <?= Html::submitButton('<i class="fa fa-save me-1"></i>' . Html::encode(Yii::t('app', 'Salva')), ['class' => 'btn btn-success']) ?>
        <?= Html::a(Html::encode(Yii::t('app', 'Annulla')), ['index'], ['class' => 'btn btn-link']) ?>
        <?php ActiveForm::end(); ?>
    </div>
</div>
