<?php
namespace app\components\btcpay\provisioning;

/**
 * SIP-022 B1: esito negativo del client HTTP del provisioning, solo codici fissi (mai URL, header, corpo o credenziali).
 * INVALID_CONFIG e INVALID_REQUEST: rifiutati prima di aprire qualunque connessione, quindi nulla è stato inviato.
 * OUTCOME_UNKNOWN: la richiesta può essere arrivata ed eseguita; non è mai prova di mancata esecuzione.
 */
final class ProvisioningHttpException extends \RuntimeException
{
    public const INVALID_CONFIG = 'invalid_config';
    public const INVALID_REQUEST = 'invalid_request';
    public const OUTCOME_UNKNOWN = 'outcome_unknown';

    public function notSent(): bool
    {
        return in_array($this->getMessage(), [self::INVALID_CONFIG, self::INVALID_REQUEST], true);
    }
}
