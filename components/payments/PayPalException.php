<?php

namespace app\components\payments;

use RuntimeException;

/** Errori PayPal con messaggi sicuri da registrare: mai credenziali, token o dati del pagatore. */
final class PayPalException extends RuntimeException
{
}
