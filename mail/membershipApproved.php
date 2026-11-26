<?php

use yii\helpers\Html;
use app\components\sendMail;

/* SIP-030: iscrizione approvata dall'admin. */
/* @var $user app\models\Users */
$url = sendMail::applicationUrl('pay-fee/index');
?>
<p><?= Html::encode(Yii::t('app', 'Hello {name},', ['name' => $user->first_name ?: $user->email])) ?></p>
<p><?= Html::encode(Yii::t('app', 'your membership request to {app} has been approved: you can now log in.', ['app' => Yii::$app->name])) ?></p>
<p><?= Html::encode(Yii::t('app', 'To complete the membership, pay the annual fee from your area:')) ?></p>
<p><a href="<?= Html::encode($url) ?>" rel="noreferrer"><?= Html::encode(Yii::t('app', 'Pay the membership fee')) ?></a></p>
