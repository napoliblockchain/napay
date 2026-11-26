<?php
use app\components\sendMail;
echo 'Ciao ' . ($user->first_name ?: $user->username) . ",\n\n";
echo "Apri il link per accedere o confermare la tua registrazione:\n";
echo sendMail::activationUrl($user) . "\n\n";
echo "I link di attivazione scadono dopo 48 ore.\n";
echo Yii::t('app', 'After you confirm your email, the association reviews your membership request: you will receive an email with the outcome.') . "\n";
echo "Se non hai richiesto questa email, puoi ignorarla.\n";
