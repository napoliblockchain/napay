<?php

use yii\helpers\Html;
use app\components\sendMail;

/* SIP-030: promemoria della quota associativa con la data di scadenza reale. */
/* @var $user app\models\Users */
/* @var $expiresOn string Y-m-d */
$date = Yii::$app->formatter->asDate($expiresOn, 'php:d/m/Y');
$expired = $expiresOn < date('Y-m-d');
?>
<p><?= Html::encode(Yii::t('app', 'Hello {name},', ['name' => $user->first_name ?: $user->email])) ?></p>
<p><?= Html::encode($expired
    ? Yii::t('app', 'your membership of {app} expired on {date}.', ['app' => Yii::$app->name, 'date' => $date])
    : Yii::t('app', 'your membership of {app} expires on {date}.', ['app' => Yii::$app->name, 'date' => $date])) ?></p>
<p><?= Html::encode(Yii::t('app', 'To keep supporting the association and using its services, renew the membership fee:')) ?></p>
<p><a href="<?= Html::encode(sendMail::applicationUrl('pay-fee/index')) ?>" rel="noreferrer"><?= Html::encode(Yii::t('app', 'Pay the membership fee')) ?></a></p>
<p class="small"><?= Html::encode(Yii::t('app', 'If you have already paid, ignore this message. You can turn these reminders off from the fee page.')) ?></p>
