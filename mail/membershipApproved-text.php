<?php

use app\components\sendMail;

/* SIP-030: iscrizione approvata dall'admin. */
/* @var $user app\models\Users */
echo Yii::t('app', 'Hello {name},', ['name' => $user->first_name ?: $user->email]) . "\n\n";
echo Yii::t('app', 'your membership request to {app} has been approved: you can now log in.', ['app' => Yii::$app->name]) . "\n";
echo Yii::t('app', 'To complete the membership, pay the annual fee from your area:') . "\n";
echo sendMail::applicationUrl('pay-fee/index') . "\n";
