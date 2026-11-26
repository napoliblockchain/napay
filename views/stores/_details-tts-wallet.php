<?php

/* Wallet TTS del negozio (SIP-030): indirizzo degli incassi TTS dei suoi POS; gestione nella pagina store-wallet. */

use yii\helpers\Html;
use app\components\Crypt;

/* @var $model app\models\Stores */
/* @var $ttsWallet string|null */
?>
<div class="card" data-store-tts-wallet>
    <div class="card-body">
        <?php if ($ttsWallet !== null): ?>
            <span class="form-label"><?= Html::encode(Yii::t('app', 'Current address')) ?></span>
            <div class="font-monospace text-break mb-2"><?= Html::encode($ttsWallet) ?></div>
        <?php else: ?>
            <div class="alert alert-warning mb-2"><?= Html::encode(Yii::t('app', 'Not set: TTS payments of this store are disabled.')) ?></div>
        <?php endif; ?>
        <p class="text-secondary mb-0"><?= Html::encode(Yii::t('app', 'The POS terminals of this store receive TTS payments on this address.')) ?></p>
    </div>
    <div class="card-footer">
        <?= Html::a('<i class="fas fa-wallet me-1" aria-hidden="true"></i>' . Html::encode(Yii::t('app', 'Manage')), ['store-wallet/index', 'store' => Crypt::encrypt($model->id)], ['class' => 'btn btn-primary']) ?>
    </div>
</div>
