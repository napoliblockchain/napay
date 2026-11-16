<?php

/* SIP-030: iscrizione rifiutata dall'admin; l'account viene eliminato subito dopo l'invio. */
/* @var $user app\models\Users */
/* @var $reason string */
echo Yii::t('app', 'Hello {name},', ['name' => $user->first_name ?: $user->email]) . "\n\n";
echo Yii::t('app', 'your membership request to {app} has not been accepted.', ['app' => Yii::$app->name]) . "\n\n";
echo Yii::t('app', 'Reason:') . "\n" . $reason . "\n\n";
echo Yii::t('app', 'The account created with the registration and its data have been deleted.') . "\n";
