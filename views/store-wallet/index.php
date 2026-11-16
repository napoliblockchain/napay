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
/* @var $lost array segnalazioni di seed perso del negozio (SIP-031): address, reported_at, recovered_at */

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
    <?php if ($current !== null): ?>
    <!-- SIP-031: seed perso, doppia conferma, non si annulla -->
    <div class="card-body border-top" data-store-wallet-lost>
        <details>
            <summary class="text-danger fw-semibold"><?= Html::encode(Yii::t('app', 'Lost the seed of this wallet?')) ?></summary>
            <p class="text-secondary mt-2"><?= Html::encode(Yii::t('app', 'Reporting blocks TTS payments on this address right away: it is removed from the stores that use it, pending requests are cancelled and past payments are counted as lost funds. The report cannot be undone; if you find the seed again, save the same address.')) ?></p>
            <?= Html::beginForm(['report-lost', 'store' => $store], 'post') ?>
            <div class="mb-3">
                <label class="form-label" for="lost-reason"><?= Html::encode(Yii::t('app', 'Reason')) ?></label>
                <?= Html::textarea('reason', '', ['id' => 'lost-reason', 'class' => 'form-control', 'rows' => 2, 'maxlength' => 1000, 'required' => true]) ?>
            </div>
            <div class="mb-3">
                <label class="form-label" for="lost-confirm"><?= Html::encode(Yii::t('app', 'Type {word} to confirm', ['word' => Yii::t('app', 'CONFIRM')])) ?></label>
                <?= Html::textInput('confirm', '', ['id' => 'lost-confirm', 'class' => 'form-control', 'autocomplete' => 'off', 'required' => true]) ?>
            </div>
            <?= Html::submitButton('<i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i>' . Html::encode(Yii::t('app', 'Report lost seed')), ['class' => 'btn btn-danger']) ?>
            <?= Html::endForm() ?>
        </details>
    </div>
    <?php endif; ?>
    <?php if ($lost !== []): ?>
    <div class="card-body border-top">
        <h4 class="mb-2"><?= Html::encode(Yii::t('app', 'Lost seed reports')) ?></h4>
        <ul class="list-unstyled mb-0 small">
            <?php foreach ($lost as $row): ?>
                <li class="d-flex flex-wrap gap-2 align-items-center mb-1">
                    <span class="font-monospace" title="<?= Html::encode($row['address']) ?>"><?= Html::encode(substr($row['address'], 0, 10) . '…' . substr($row['address'], -8)) ?></span>
                    <span class="text-secondary"><?= Html::encode(Yii::$app->formatter->asDatetime((int) $row['reported_at'], 'php:d/m/Y H:i')) ?></span>
                    <?= $row['recovered_at'] === null
                        ? Html::tag('span', Html::encode(Yii::t('app', 'Lost')), ['class' => 'badge bg-dark-lt'])
                        : Html::tag('span', Html::encode(Yii::t('app', 'Recovered on {date}', ['date' => Yii::$app->formatter->asDate((int) $row['recovered_at'], 'php:d/m/Y')])), ['class' => 'badge bg-success-lt']) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
