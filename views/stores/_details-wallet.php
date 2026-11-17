<?php

/*
 * Wallet BTC del negozio (SIP-022 xpub, decisione A): il merchant fornisce la chiave pubblica estesa del proprio wallet,
 * vede i primi indirizzi prima di confermare e può cambiarla o rimuoverla. La chiave salvata è mostrata solo abbreviata;
 * schema di derivazione e key path restano interni. Negozi v1: wallet gestito direttamente su BTCPay.
 */

use app\components\Crypt;
use app\components\User;
use app\components\btcpay\XpubAddresses;
use app\components\btcpay\provisioning\ProvisioningOutbox;
use yii\helpers\Html;

/* @var $model app\models\Stores */

$settings = $model->storesettings;
$legacy = $settings !== null && !empty($settings->bps_storeid);
$scheme = $settings !== null && is_string($settings->derivationScheme) ? trim($settings->derivationScheme) : '';
$last = ProvisioningOutbox::lastJob(Yii::$app->db, 'store_wallet', (int) $model->id);
$merchant = User::isMerchant() && !$legacy && $settings !== null;
$id = Crypt::encrypt($model->id);
$form = static function (string $label) use ($id): string {
    return Html::beginForm(['wallet-preview', 'id' => $id], 'post', ['class' => 'mt-3'])
        . '<label class="form-label" for="store-xpub">' . Html::encode(Yii::t('app', 'Extended public key of your wallet (xpub, ypub, zpub)')) . '</label>'
        . Html::textInput('xpub', '', ['id' => 'store-xpub', 'class' => 'form-control font-monospace', 'autocomplete' => 'off', 'spellcheck' => 'false', 'maxlength' => 130, 'required' => true])
        . '<div class="form-text">' . Html::encode(Yii::t('app', 'Only the public key: Napay never needs your seed or private keys. You will see the first addresses before confirming.')) . '</div>'
        . Html::submitButton(Html::encode($label), ['class' => 'btn btn-primary mt-2'])
        . Html::endForm();
};
?>
<div class="store-btc-wallet">
<?php if ($legacy): ?>
    <p class="text-secondary"><?= Html::encode(Yii::t('app', 'The BTC wallet of this store is managed directly on BTCPay.')) ?></p>
<?php elseif ($scheme === ''): ?>
    <p><?= Html::encode(Yii::t('app', 'No BTC wallet: this store does not receive BTC payments.')) ?></p>
    <?php if ($merchant) echo $form(Yii::t('app', 'Show addresses')); ?>
<?php else: ?>
    <p class="mb-1"><?= Html::encode(Yii::t('app', 'BTC wallet')) ?>: <code><?= Html::encode(XpubAddresses::mask($scheme)) ?></code></p>
    <?php if ($last !== null && $last['state'] === 'failed' && $last['last_error_code'] === 'wallet_rejected'): ?>
        <div class="alert alert-danger"><?= Html::encode(Yii::t('app', 'BTCPay did not accept this key: check it or use another one.')) ?></div>
    <?php elseif ($last !== null && !in_array($last['state'], ['done', 'failed'], true)): ?>
        <p><span class="badge bg-azure-lt"><?= Html::encode(Yii::t('app', 'Sending to BTCPay')) ?></span></p>
    <?php endif; ?>
    <?php if ($merchant): ?>
        <details class="mt-2">
            <summary><?= Html::encode(Yii::t('app', 'Change wallet')) ?></summary>
            <?= $form(Yii::t('app', 'Show addresses')) ?>
        </details>
        <?= Html::beginForm(['wallet-remove', 'id' => $id], 'post', ['class' => 'mt-3']) ?>
        <?= Html::submitButton(Html::encode(Yii::t('app', 'Remove wallet')), ['class' => 'btn btn-outline-danger']) ?>
        <?= Html::endForm() ?>
    <?php endif; ?>
<?php endif; ?>
</div>
