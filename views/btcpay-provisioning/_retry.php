<?php

/* Pulsante "riprova collegamento BTCPay" (SIP-022 B5): solo admin e solo con l'ultimo lavoro riprovabile. */
/* @var $kind string store_link | pos_link */
/* @var $entityId int */

use app\components\Crypt;
use app\components\User;
use app\components\btcpay\provisioning\ProvisioningOutbox;
use yii\helpers\Html;

$last = User::isAdministrator() ? ProvisioningOutbox::lastJob(Yii::$app->db, $kind, (int) $entityId) : null;
if ($last === null || !in_array($last['state'], ProvisioningOutbox::RETRYABLE, true)) return;
?>
<?= Html::beginForm(['/btcpay-provisioning/retry'], 'post', ['class' => 'd-inline-block mt-1']) ?>
<?= Html::hiddenInput('kind', $kind) ?>
<?= Html::hiddenInput('id', Crypt::encrypt((int) $entityId)) ?>
<?= Html::submitButton('<i class="fas fa-redo me-1"></i>' . Html::encode(Yii::t('app', 'Retry BTCPay link')), ['class' => 'btn btn-sm btn-outline-primary']) ?>
<?= Html::endForm() ?>
