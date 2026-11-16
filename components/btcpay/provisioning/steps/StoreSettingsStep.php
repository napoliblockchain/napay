<?php
namespace app\components\btcpay\provisioning\steps;

/**
 * SIP-022 passo 3: impostazioni dello store BTCPay del negozio con la chiave corrente del merchant (PUT idempotente:
 * stesso payload, stesso risultato, quindi un esito ignoto si ripete senza riconciliazione). Nessuna persistenza qui.
 */
final class StoreSettingsStep
{
    public function __construct(private readonly \Closure $withKey) {}

    public function update(string $remoteStoreId, #[\SensitiveParameter] array $payload, #[\SensitiveParameter] ?string $apiKey): StepOutcome
    {
        if (preg_match(StepOutcome::REMOTE_ID, $remoteStoreId) !== 1 || ($payload['name'] ?? '') === '') throw new \InvalidArgumentException('invalid_snapshot');
        if ($apiKey === null || $apiKey === '') return StepOutcome::pending('account_not_ready');
        [$response, $failure] = StepOutcome::call(fn() => ($this->withKey)('PUT', '/api/v1/stores/' . $remoteStoreId, $payload, $apiKey), StepOutcome::retry('store_update_unavailable'));
        if ($failure !== null) return $failure->kind === StepOutcome::FAILED ? StepOutcome::retry('request_not_sent') : $failure;
        return match (true) {
            $response['status'] === 200 => StepOutcome::done(),
            $response['status'] === 429 => StepOutcome::retry('rate_limited', $response['retry_after']),
            in_array($response['status'], [401, 403], true) => StepOutcome::failed('merchant_key_denied'),
            $response['status'] === 404 => StepOutcome::failed('store_missing'),
            $response['status'] >= 500 => StepOutcome::retry('store_update_unavailable'),
            default => StepOutcome::failed(self::rejected($response['json'])),
        };
    }

    /** 422 di validazione: codice con il primo campo rifiutato (es. store_update_rejected_name), mai il messaggio. */
    private static function rejected(?array $json): string
    {
        $field = is_array($json) && array_is_list($json) && is_array($json[0] ?? null) && is_string($json[0]['path'] ?? null) ? strtolower($json[0]['path']) : '';
        $field = preg_replace('/[^a-z0-9_]+/', '_', $field) ?? '';
        return $field === '' ? 'store_update_rejected' : substr('store_update_rejected_' . trim($field, '_'), 0, 64);
    }
}
