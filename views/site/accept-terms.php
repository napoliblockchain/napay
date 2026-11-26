<?php

use yii\helpers\Html;
use app\models\LegalDocuments;

/* @var $this yii\web\View */
/* @var $document LegalDocuments condizioni in vigore da accettare */

$this->title = Yii::t('app', 'Updated terms of use');
?>
<h1><?= Html::encode($this->title) ?></h1>
<p><?= Html::encode(Yii::t('app', 'The terms of use have been updated (version {n}). Read them and confirm to continue.', ['n' => (int) $document->version])) ?></p>
<div class="legal-document border rounded p-3 mb-3"><?= LegalDocuments::render($document->body) ?></div>
<?= Html::beginForm(['site/accept-terms'], 'post') ?>
<?= Html::hiddenInput('document', (string) (int) $document->id) ?>
<label class="form-check mb-3">
    <?= Html::checkbox('accept', false, ['value' => '1', 'class' => 'form-check-input', 'required' => true]) ?>
    <span class="form-check-label"><?= Html::encode(Yii::t('app', 'I have read and accept the terms of use.')) ?></span>
</label>
<?= Html::submitButton(Html::encode(Yii::t('app', 'Accept and continue')), ['class' => 'auth-submit']) ?>
<?= Html::endForm() ?>
<div class="auth-links"><?= Html::a(Html::encode(Yii::t('app', 'Logout')), ['site/logout'], ['data-method' => 'post']) ?></div>
