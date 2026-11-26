<?php
/** @var string $resetUrl trusted HTTPS reset link */
echo 'Ciao ' . ($user->first_name ?: $user->username) . ",\n\n";
echo "Usa il link entro 60 minuti per reimpostare la password:\n" . $resetUrl . "\n\n";
echo "Se non hai richiesto il reset, ignora questa email.\n";
