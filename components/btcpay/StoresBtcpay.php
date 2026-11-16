<?php

namespace app\components\btcpay;

use app\components\BTCPayServer;

/**
 * Client BTCPay per la gestione dei negozi (SIP-030 punto 4): server e chiave dalle variabili d'ambiente,
 * non più dalle impostazioni nel database. Stesso server delle quote (BTCPAY_URL), chiave distinta
 * (BTCPAY_STORES_API_KEY). Ponte fino alle chiavi per merchant di SIP-022 (modello P3).
 */
final class StoresBtcpay
{
    /** @return BTCPayServer|null null se il server o la chiave dei negozi non sono configurati */
    public static function client(): ?BTCPayServer
    {
        $url = rtrim(trim((string) getenv('BTCPAY_URL')), '/');
        $key = trim((string) getenv('BTCPAY_STORES_API_KEY'));
        if (preg_match('/^[A-Za-z0-9]{20,128}$/D', $key) !== 1) return null;
        $parts = parse_url($url);
        $https = is_array($parts) && ($parts['scheme'] ?? null) === 'https' && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['query']);
        // server finto solo nella suite di test isolata, come per le quote
        $mock = getenv('NAPAY_TEST_DB_CONFIRM') === 'isolated-fixture' && preg_match('#^http://127\.0\.0\.1:\d{2,5}$#D', $url) === 1;
        return $https || $mock ? new BTCPayServer($url, $key) : null;
    }
}
