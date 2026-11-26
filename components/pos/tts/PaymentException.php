<?php
namespace app\components\pos\tts;

/** SIP-021 fase 3: esito negativo dei pagamenti TTS, codice pubblico dell'API (mai dettagli di chain o merchant). */
final class PaymentException extends \RuntimeException
{
    public const NOT_FOUND = 'not_found';
    public const IDEMPOTENCY = 'idempotency_conflict';
    public const NOT_PENDING = 'payment_not_pending';
    public const UNAVAILABLE = 'payment_unavailable';

    public function status(): int
    {
        return match ($this->getMessage()) {
            self::NOT_FOUND => 404,
            self::UNAVAILABLE => 503,
            default => 409,
        };
    }
}
