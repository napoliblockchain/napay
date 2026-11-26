<?php
namespace app\components\pos;

/** SIP-021 fase 2: esito negativo delle aperture POS, codice pubblico dell'API (mai dettagli o titolare). */
final class OpeningException extends \RuntimeException
{
    public const NOT_FOUND = 'not_found';
    public const IN_USE = 'terminal_in_use';
    public const IDEMPOTENCY = 'idempotency_conflict';
    public const INVALID = 'opening_invalid';

    public function status(): int
    {
        return $this->getMessage() === self::NOT_FOUND ? 404 : 409;
    }
}
