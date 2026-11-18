<?php

use yii\helpers\Html;
use app\components\Crypt;
use app\components\btcpay\StoreSettingsPayload;
use app\components\merchant\MerchantLogo;

/* @var $this yii\web\View */
/* @var $model app\models\Merchants */

// SIP-033: logo mostrato sulla pagina di pagamento BTCPay di tutti i negozi del merchant
$sha = MerchantLogo::current(Yii::$app->db, (int) $model->id);
$id = Crypt::encrypt($model->id);
?>
<div class="merchant-logo">
    <p class="text-secondary"><?= Html::encode(Yii::t('app', 'Shown on the BTCPay payment page of all your stores. PNG or JPEG, up to 2 MB; it is resized to at most 600x200 pixels.')) ?></p>
    <div class="mb-3 p-3 border rounded bg-light d-inline-block">
        <?= Html::img($sha !== null ? MerchantLogo::url($sha) : '@web' . StoreSettingsPayload::DEFAULT_LOGO, [
            'alt' => Yii::t('app', 'Logo'), 'style' => 'max-width:300px;max-height:100px',
        ]) ?>
        <div class="small text-secondary mt-2"><?= Html::encode($sha !== null ? Yii::t('app', 'Your logo') : Yii::t('app', 'Napay logo (no logo uploaded)')) ?></div>
    </div>
    <?= Html::beginForm(['logo', 'id' => $id], 'post', ['enctype' => 'multipart/form-data', 'class' => 'd-flex flex-wrap gap-2 align-items-center']) ?>
        <?= Html::fileInput('logo', null, ['accept' => 'image/png,image/jpeg', 'class' => 'form-control', 'style' => 'max-width:24rem', 'required' => true,
            'aria-label' => Yii::t('app', 'Logo')]) ?>
        <?= Html::submitButton(Html::encode(Yii::t('app', 'Upload logo')), ['class' => 'btn btn-primary']) ?>
    <?= Html::endForm() ?>
    <?php if ($sha !== null): ?>
    <?= Html::beginForm(['logo-delete', 'id' => $id], 'post', ['class' => 'mt-2']) ?>
        <?= Html::submitButton(Html::encode(Yii::t('app', 'Remove logo')), ['class' => 'btn btn-outline-danger',
            'data-confirm' => Yii::t('app', 'Remove the logo? The payment pages will show the Napay logo.')]) ?>
    <?= Html::endForm() ?>
    <?php endif ?>
</div>
