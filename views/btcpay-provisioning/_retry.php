<?php

/* Pulsante "riprova collegamento BTCPay" (SIP-022 B5): solo admin e solo con l'ultimo lavoro riprovabile. */
/* @var $kind string merchant_account | store_link | pos_link */
/* @var $entityId int */

use app\components\Crypt;
use app\components\User;
use app\components\btcpay\provisioning\ProvisioningOutbox;
use yii\helpers\Html;

if (!User::isAdministrator() || ProvisioningOutbox::instance() === null) return;
$last = ProvisioningOutbox::lastJob(Yii::$app->db, $kind, (int) $entityId);
// merchant mai collegato: primo collegamento; altrimenti riprova solo se l'ultimo lavoro è riprovabile
$first = $last === null && $kind === 'merchant_account';
if (!$first && ($last === null || !in_array($last['state'], ProvisioningOutbox::RETRYABLE, true))) return;
?>
<?= Html::beginForm(['/btcpay-provisioning/retry'], 'post', ['class' => 'd-inline-block mt-1']) ?>
<?= Html::hiddenInput('kind', $kind) ?>
<?= Html::hiddenInput('id', Crypt::encrypt((int) $entityId)) ?>
<?= Html::submitButton('<i class="fas fa-' . ($first ? 'link' : 'redo') . ' me-1"></i>' . Html::encode($first ? Yii::t('app', 'Link to BTCPay') : Yii::t('app', 'Retry BTCPay link')),
    ['class' => 'btn btn-sm btn-outline-primary']) ?>
<?= Html::endForm() ?>
