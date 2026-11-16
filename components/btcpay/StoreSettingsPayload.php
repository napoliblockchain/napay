<?php

namespace app\components\btcpay;

/**
 * Impostazioni dello store BTCPay del negozio (SIP-030 punto 4, SIP-022 passo 3): nome e valuta scelti dal merchant,
 * tutto il resto fisso (valori salvati alla creazione del negozio). Un solo costruttore per web (negozi v1) e worker.
 */
final class StoreSettingsPayload
{
    /** Colonne di `storesettings` lette per costruire il payload (anche grant di lettura del worker). */
    public const COLUMNS = ['store_id', 'website', 'defaultCurrency', 'invoiceExpiration', 'displayExpirationTimer', 'monitoringExpiration', 'speedPolicy',
        'lightningDescriptionTemplate', 'paymentTolerance', 'anyoneCanCreateInvoice', 'requiresRefundEmail', 'checkoutType', 'receipt',
        'lightningAmountInSatoshi', 'lightningPrivateRouteHints', 'onChainWithLnInvoiceFallback', 'redirectAutomatically', 'showRecommendedFee',
        'recommendedFeeBlockTarget', 'defaultLang', 'customLogo', 'customCSS', 'htmlTitle', 'networkFeeMode', 'payJoinEnabled', 'lazyPaymentMethods',
        'defaultPaymentMethod'];
    private const INTS = ['invoiceExpiration', 'displayExpirationTimer', 'monitoringExpiration', 'paymentTolerance', 'recommendedFeeBlockTarget'];
    private const BOOLS = ['anyoneCanCreateInvoice', 'requiresRefundEmail', 'lightningAmountInSatoshi', 'lightningPrivateRouteHints',
        'onChainWithLnInvoiceFallback', 'redirectAutomatically', 'showRecommendedFee', 'payJoinEnabled', 'lazyPaymentMethods'];
    private const STRINGS = ['website', 'defaultCurrency', 'speedPolicy', 'lightningDescriptionTemplate', 'checkoutType', 'defaultLang', 'customLogo',
        'customCSS', 'htmlTitle', 'networkFeeMode', 'defaultPaymentMethod'];

    /** @param array $settings riga di `storesettings` (array associativo); valori nulli omessi */
    public static function build(string $name, array $settings): array
    {
        $name = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $name) ?? '');
        if ($name === '') throw new \InvalidArgumentException('invalid_store_name');
        // BTCPay accetta al massimo 50 caratteri (StoreLinkStep::MAX_NAME)
        $payload = ['name' => mb_substr($name, 0, 50)];
        foreach (self::INTS as $field) if (isset($settings[$field]) && is_numeric($settings[$field])) $payload[$field] = (int) $settings[$field];
        foreach (self::BOOLS as $field) if (isset($settings[$field])) $payload[$field] = (int) $settings[$field] === 1;
        foreach (self::STRINGS as $field) if (isset($settings[$field]) && is_string($settings[$field]) && $settings[$field] !== '') $payload[$field] = $settings[$field];
        // colonna JSON: lettura grezza (worker) = testo JSON di una stringa JSON (ActiveRecord codifica due volte)
        $receipt = $settings['receipt'] ?? null;
        for ($i = 0; $i < 2 && is_string($receipt); ++$i) $receipt = json_decode($receipt, true);
        if (is_array($receipt)) {
            $payload['receipt'] = [];
            foreach (['enabled', 'showQR', 'showPayments'] as $field) if (isset($receipt[$field])) $payload['receipt'][$field] = (int) $receipt[$field] === 1;
        }
        return $payload;
    }
}
