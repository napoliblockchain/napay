<?php

namespace app\components\payments;

use RuntimeException;

/** Errori dei gateway di pagamento con messaggi sicuri da registrare: mai credenziali, token o dati del pagatore. */
final class PaymentGatewayException extends RuntimeException
{
}
