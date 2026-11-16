<?php

use yii\helpers\Html;

/* SIP-030: iscrizione rifiutata dall'admin; l'account viene eliminato subito dopo l'invio. */
/* @var $user app\models\Users */
/* @var $reason string */
?>
<p><?= Html::encode(Yii::t('app', 'Hello {name},', ['name' => $user->first_name ?: $user->email])) ?></p>
<p><?= Html::encode(Yii::t('app', 'your membership request to {app} has not been accepted.', ['app' => Yii::$app->name])) ?></p>
<p><?= Html::encode(Yii::t('app', 'Reason:')) ?><br><?= nl2br(Html::encode($reason)) ?></p>
<p><?= Html::encode(Yii::t('app', 'The account created with the registration and its data have been deleted.')) ?></p>
