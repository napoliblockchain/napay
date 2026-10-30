<?php
use app\components\sendMail;
echo 'Ciao ' . ($user->first_name ?: $user->username) . ",\n\n";
echo "Apri il link per accedere o confermare la tua registrazione:\n";
echo sendMail::activationUrl($user) . "\n\n";
echo "I link di attivazione scadono dopo 48 ore.\n";
echo "Se non hai richiesto questa email, puoi ignorarla.\n";
