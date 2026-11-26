<?php
namespace app\components\btcpay\provisioning\steps;

/**
 * SIP-022 passo 4: webhook dello store BTCPay verso Napay, con la chiave del merchant. Idempotente: si cerca per URL,
 * se c'è si aggiorna (PUT, il segreto non è rileggibile da BTCPay), altrimenti si crea (POST). Un solo webhook Napay.
 */
final class StoreWebhookStep
{
    public const EVENTS = ['InvoiceReceivedPayment', 'InvoiceProcessing', 'InvoiceSettled', 'InvoiceExpired', 'InvoiceInvalid'];

    public function __construct(private readonly \Closure $withKey) {}

    public function ensure(string $remoteStoreId, string $url, #[\SensitiveParameter] string $secret, #[\SensitiveParameter] ?string $apiKey): StepOutcome
    {
        if (preg_match(StepOutcome::REMOTE_ID, $remoteStoreId) !== 1 || preg_match('#^https://[^\s/]+(/[^\s]*)?$#D', $url) !== 1 || $secret === '') throw new \InvalidArgumentException('invalid_snapshot');
        if ($apiKey === null || $apiKey === '') return StepOutcome::pending('account_not_ready');
        $base = '/api/v1/stores/' . $remoteStoreId . '/webhooks';
        [$response, $failure] = StepOutcome::call(fn() => ($this->withKey)('GET', $base, null, $apiKey), StepOutcome::retry('webhook_lookup_unavailable'));
        if ($failure !== null) return self::unsent($failure);
        if ($response['status'] !== 200) return self::outcome($response);
        $existing = null; $count = 0;
        foreach (is_array($response['json']) && array_is_list($response['json']) ? $response['json'] : [] as $hook) {
            if (is_array($hook) && ($hook['url'] ?? null) === $url) { ++$count; $existing = StepOutcome::remoteId($hook); }
        }
        if ($count > 1) return StepOutcome::failed('webhook_ambiguous');
        $body = ['url' => $url, 'enabled' => true, 'automaticRedelivery' => true, 'secret' => $secret,
            'authorizedEvents' => ['everything' => false, 'specificEvents' => self::EVENTS]];
        [$response, $failure] = StepOutcome::call(fn() => ($this->withKey)($existing === null ? 'POST' : 'PUT', $existing === null ? $base : $base . '/' . $existing, $body, $apiKey),
            StepOutcome::retry('webhook_update_unavailable'));
        // POST con esito ignoto: la prossima volta la GET per URL trova il webhook e lo aggiorna, mai un doppione
        return $failure !== null ? self::unsent($failure) : self::outcome($response);
    }

    private static function unsent(StepOutcome $failure): StepOutcome
    {
        return $failure->kind === StepOutcome::FAILED ? StepOutcome::retry('request_not_sent') : $failure;
    }

    private static function outcome(array $response): StepOutcome
    {
        return match (true) {
            $response['status'] === 200 => StepOutcome::done(),
            $response['status'] === 429 => StepOutcome::retry('rate_limited', $response['retry_after']),
            in_array($response['status'], [401, 403], true) => StepOutcome::failed('merchant_key_denied'),
            $response['status'] === 404 => StepOutcome::failed('store_missing'),
            $response['status'] >= 500 => StepOutcome::retry('webhook_update_unavailable'),
            default => StepOutcome::failed('webhook_rejected'),
        };
    }
}
