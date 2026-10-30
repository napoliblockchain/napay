<?php
use app\components\sendMail;
echo "La password e stata modificata e le precedenti sessioni revocate.\n";
echo "Se non sei stato tu, contatta subito un amministratore.\n\n";
echo sendMail::applicationUrl('site/login') . "\n";
