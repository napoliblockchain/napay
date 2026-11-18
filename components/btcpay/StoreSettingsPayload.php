<?php

namespace app\components\btcpay;

/**
 * Impostazioni dello store BTCPay del negozio (SIP-030 punto 4, SIP-022 passo 3, SIP-033): nome, valuta, sito e logo dal
 * negozio; tutto il resto fisso e uguale per ogni store (FIXED, standard del vecchio Napay confermati), anche per i negozi
 * già esistenti al primo aggiornamento. Nomi dei campi di BTCPay 2.4.7 (StoreBaseData): il PUT sostituisce lo store, i
 * campi omessi tornano al default di BTCPay. Un solo costruttore per web (negozi v1) e worker.
 */
final class StoreSettingsPayload
{
    /** Colonne di `storesettings` lette per costruire il payload (anche grant di lettura del worker). */
    public const COLUMNS = ['store_id', 'website', 'defaultCurrency', 'invoiceExpiration', 'displayExpirationTimer', 'monitoringExpiration', 'speedPolicy',
        'lightningDescriptionTemplate', 'paymentTolerance', 'anyoneCanCreateInvoice', 'requiresRefundEmail', 'checkoutType', 'receipt',
        'lightningAmountInSatoshi', 'lightningPrivateRouteHints', 'onChainWithLnInvoiceFallback', 'redirectAutomatically', 'showRecommendedFee',
        'recommendedFeeBlockTarget', 'defaultLang', 'customLogo', 'customCSS', 'htmlTitle', 'networkFeeMode', 'payJoinEnabled', 'lazyPaymentMethods',
        'defaultPaymentMethod'];
    /** Standard per tutti gli store: scadenza 15 min, monitoraggio 24 h, 1 conferma, fee di rete sempre, nessun CSS. */
    public const FIXED = [
        'invoiceExpiration' => 900,
        'displayExpirationTimer' => 300,
        'monitoringExpiration' => 86400,
        'speedPolicy' => 'MediumSpeed',
        'paymentTolerance' => 0,
        'networkFeeMode' => 'Always',
        'anyoneCanCreateInvoice' => false,
        'redirectAutomatically' => false,
        'showRecommendedFee' => false,
        'lightningAmountInSatoshi' => false,
        'lightningPrivateRouteHints' => false,
        'onChainWithLnInvoiceFallback' => false,
        'payJoinEnabled' => false,
        'lazyPaymentMethods' => false,
        'defaultPaymentMethod' => 'BTC-CHAIN',
        'defaultLang' => 'it-IT',
        'htmlTitle' => 'Attesa pagamento in cryptovaluta...',
        'receipt' => ['enabled' => true, 'showQR' => true, 'showPayments' => true],
    ];
    /** Logo di Napay (icona e scritta del marchio attuale) per i negozi senza un logo proprio (percorso sotto @web). */
    public const DEFAULT_LOGO = '/brand/logo-wordmark.png';
    private const MAX_URL = 255;

    /** @param array $settings riga di `storesettings` (array associativo); valori non validi omessi */
    public static function build(string $name, array $settings): array
    {
        $name = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $name) ?? '');
        if ($name === '') throw new \InvalidArgumentException('invalid_store_name');
        // BTCPay accetta al massimo 50 caratteri (StoreLinkStep::MAX_NAME)
        $payload = ['name' => mb_substr($name, 0, 50)];
        $currency = $settings['defaultCurrency'] ?? null;
        if (is_string($currency) && preg_match('/^[A-Z]{3}$/D', $currency) === 1) $payload['defaultCurrency'] = $currency;
        $payload += self::FIXED;
        if (($website = self::url($settings['website'] ?? null, true)) !== null) $payload['website'] = $website;
        if (($logo = self::url($settings['customLogo'] ?? null, false)) !== null) $payload['logoUrl'] = $logo;
        return $payload;
    }

    /**
     * URL assoluto http(s) senza credenziali, o null. $bare: "www.negozio.it" (come salvato dal vecchio Napay) diventa https.
     */
    public static function url(mixed $value, bool $bare): ?string
    {
        if (!is_string($value)) return null;
        $value = trim($value);
        if ($bare && $value !== '' && preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) !== 1) $value = 'https://' . $value;
        if ($value === '' || strlen($value) > self::MAX_URL || preg_match('/[\x00-\x20\x7f]/', $value) === 1
            || filter_var($value, FILTER_VALIDATE_URL) === false) return null;
        $parts = parse_url($value);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass'])) return null;
        return $value;
    }
}
