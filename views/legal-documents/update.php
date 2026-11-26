<?php

use yii\helpers\Html;
use yii\bootstrap5\ActiveForm;
use app\models\LegalDocuments;

/* @var $this yii\web\View */
/* @var $document LegalDocuments bozza */

$this->title = Yii::t('app', '{type}: draft version {n}', ['type' => LegalDocuments::typeLabel($document->type), 'n' => (int) $document->version]);
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Legal documents'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="card">
    <div class="card-header"><h3 class="card-title"><?= Html::encode($this->title) ?></h3></div>
    <div class="card-body">
        <p class="text-secondary small"><?= Html::encode(Yii::t('app', 'Markdown text: ## headings, - lists, **bold**, links. HTML is not allowed. Parts marked {marker} must be completed before publishing.', ['marker' => LegalDocuments::PLACEHOLDER . ']'])) ?></p>
        <?php $form = ActiveForm::begin(); ?>
        <?= $form->field($document, 'title')->textInput(['maxlength' => 200]) ?>
        <?= $form->field($document, 'body')->textarea(['rows' => 24, 'class' => 'form-control font-monospace']) ?>
        <?= Html::submitButton(Html::encode(Yii::t('app', 'Save draft')), ['class' => 'btn btn-primary']) ?>
        <?= Html::a(Html::encode(Yii::t('app', 'Preview')), ['preview', 'id' => (int) $document->id], ['class' => 'btn btn-outline-secondary']) ?>
        <?php ActiveForm::end(); ?>
    </div>
    <div class="card-footer d-flex gap-2">
        <?= Html::beginForm(['publish', 'id' => (int) $document->id], 'post') . Html::submitButton(Html::encode(Yii::t('app', 'Publish this version')), ['class' => 'btn btn-success']) . Html::endForm() ?>
        <?= Html::beginForm(['delete', 'id' => (int) $document->id], 'post') . Html::submitButton(Html::encode(Yii::t('app', 'Delete draft')), ['class' => 'btn btn-outline-danger']) . Html::endForm() ?>
    </div>
</div>
