<?php

use yii\helpers\Html;
use yii\bootstrap5\ActiveForm;
use app\assets\StoreWalletAsset;

/* @var $this yii\web\View */
/* @var $form app\models\forms\pos\TtsWalletForm */
/* @var $current array|null wallet salvato: address, updated_at */
/* @var $stores array ID negozio cifrato => nome (in scope) */
/* @var $store string|null negozio selezionato (ID cifrato) */
/* @var $editing bool form visibile: nessun indirizzo, modifica richiesta o errori */

StoreWalletAsset::register($this);
$back = ['index', 'store' => $store];

$this->title = Yii::t('app', 'TTS wallet');
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Stores'), 'url' => ['stores/index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<?php if (count($stores) > 1): ?>
<div class="card mb-3">
    <div class="card-body">
        <?= Html::beginForm(['index'], 'get', ['class' => 'd-flex gap-2']) ?>
        <?= Html::dropDownList('store', $store, $stores, ['class' => 'form-select', 'prompt' => Yii::t('app', 'Select the store'), 'aria-label' => Yii::t('app', 'Store')]) ?>
        <?= Html::submitButton(Html::encode(Yii::t('app', 'Open')), ['class' => 'btn btn-outline-secondary']) ?>
        <?= Html::endForm() ?>
    </div>
</div>
<?php elseif ($stores === []): ?>
<div class="alert alert-info"><?= Html::encode(Yii::t('app', 'Create a store first: each store has its own TTS wallet.')) ?></div>
<?php endif; ?>
<?php if ($store !== null): ?>
<div class="card">
    <div class="card-header"><h3 class="card-title"><?= Html::encode($this->title . ' - ' . ($stores[$store] ?? '')) ?></h3></div>
    <div class="card-body">
        <p class="text-secondary"><?= Html::encode(Yii::t('app', 'The POS terminals of this store receive TTS payments on this address.')) ?></p>
        <?php if ($current !== null && !$editing): ?>
            <span class="form-label"><?= Html::encode(Yii::t('app', 'Current address')) ?></span>
            <div class="input-group mb-2">
                <input type="text" class="form-control font-monospace" value="<?= Html::encode($current['address']) ?>" readonly id="tts-wallet-address" aria-label="<?= Html::encode(Yii::t('app', 'Current address')) ?>">
                <button type="button" class="btn btn-outline-secondary" data-copy-target="#tts-wallet-address" data-copied="<?= Html::encode(Yii::t('app', 'Copied')) ?>">
                    <i class="fa fa-copy me-1" aria-hidden="true"></i><span><?= Html::encode(Yii::t('app', 'Copy')) ?></span>
                </button>
            </div>
            <div class="form-hint mb-3"><?= Html::encode(Yii::t('app', 'A transfer sent straight to this address is not a POS payment: payments are recognized only through the POS request (QR with reference).')) ?></div>
            <div class="d-flex gap-2">
                <?= Html::a('<i class="fa fa-pen me-1" aria-hidden="true"></i>' . Html::encode(Yii::t('app', 'Edit')), $back + ['edit' => '1'], ['class' => 'btn btn-primary']) ?>
                <?= Html::beginForm(['delete', 'store' => $store], 'post', ['class' => 'd-inline']) ?>
                <?= Html::submitButton('<i class="fa fa-trash me-1" aria-hidden="true"></i>' . Html::encode(Yii::t('app', 'Delete')), ['class' => 'btn btn-outline-danger',
                    'data-confirm' => Yii::t('app', 'Remove the TTS wallet of the store? New TTS payments of the store will be refused until a new address is saved.')]) ?>
                <?= Html::endForm() ?>
            </div>
        <?php else: ?>
            <?php if ($current === null): ?>
                <div class="alert alert-warning"><?= Html::encode(Yii::t('app', 'Not set: TTS payments of this store are disabled.')) ?></div>
            <?php endif; ?>
            <?php $f = ActiveForm::begin(['id' => 'tts-wallet-form', 'action' => $back]); ?>
            <?= $f->field($form, 'address')->textInput(['maxlength' => 42, 'autocomplete' => 'off', 'spellcheck' => 'false', 'placeholder' => '0x…']) ?>
            <div class="form-hint mb-3"><?= Html::encode(Yii::t('app', 'New payment requests use the new address; requests already created keep the previous one.')) ?></div>
            <div class="d-flex gap-2">
                <?= Html::submitButton('<i class="fa fa-save me-1" aria-hidden="true"></i>' . Html::encode(Yii::t('app', 'Save')), ['class' => 'btn btn-success']) ?>
                <?php if ($current !== null): ?><?= Html::a(Html::encode(Yii::t('app', 'Cancel')), $back, ['class' => 'btn btn-outline-secondary']) ?><?php endif; ?>
            </div>
            <?php ActiveForm::end(); ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
