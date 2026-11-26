<?php

namespace app\components\btcpay;

/**
 * SIP-022 passo 4: segreto del webhook di ogni store BTCPay, derivato (HMAC-SHA256) da una chiave madre condivisa solo
 * da web (verifica della firma BTCPay-Sig) e worker (registrazione del webhook). Nessun segreto di webhook nel DB;
 * segreti diversi per store: la firma di uno store non vale per un altro.
 */
final class StoreWebhookSecret
{
    /** Chiave madre: almeno 32 byte in esadecimale (64-128 caratteri). */
    public static function valid(?string $master): bool
    {
        return is_string($master) && preg_match('/^[0-9a-f]{64,128}$/D', $master) === 1;
    }

    public static function derive(#[\SensitiveParameter] string $master, string $instance, int $storeId): string
    {
        if (!self::valid($master) || preg_match('/^[0-9a-f]{64}$/D', $instance) !== 1 || $storeId < 1) throw new \InvalidArgumentException('invalid_webhook_secret_input');
        return hash_hmac('sha256', 'napay-store-webhook|' . $instance . '|' . $storeId, hex2bin($master));
    }

    /** Firma BTCPay-Sig ("sha256=<hex>" dell'HMAC del corpo) confrontata a tempo costante. */
    public static function signatureValid(string $body, ?string $header, #[\SensitiveParameter] string $secret): bool
    {
        if (!is_string($header) || strlen($header) > 128 || !str_starts_with($header, 'sha256=')) return false;
        return hash_equals('sha256=' . hash_hmac('sha256', $body, $secret), strtolower($header));
    }
}
