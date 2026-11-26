<?php
namespace app\components\btcpay\provisioning\steps;

/**
 * SIP-022 passo 3b: rimozione di un'app POS dallo store BTCPay di un negozio eliminato, con la chiave del merchant.
 * DELETE idempotente: 404 (già rimossa) vale come fatto, un esito ignoto si ripete. Lo store non viene mai cancellato.
 */
final class StoreArchiveStep
{
    public function __construct(private readonly \Closure $withKey) {}

    public function deleteApp(string $remoteAppId, #[\SensitiveParameter] ?string $apiKey): StepOutcome
    {
        if (preg_match(StepOutcome::REMOTE_ID, $remoteAppId) !== 1) throw new \InvalidArgumentException('invalid_snapshot');
        if ($apiKey === null || $apiKey === '') return StepOutcome::pending('account_not_ready');
        [$response, $failure] = StepOutcome::call(fn() => ($this->withKey)('DELETE', '/api/v1/apps/' . $remoteAppId, null, $apiKey), StepOutcome::retry('app_delete_unavailable'));
        if ($failure !== null) return $failure->kind === StepOutcome::FAILED ? StepOutcome::retry('request_not_sent') : $failure;
        return match (true) {
            in_array($response['status'], [200, 404], true) => StepOutcome::done(),
            $response['status'] === 429 => StepOutcome::retry('rate_limited', $response['retry_after']),
            in_array($response['status'], [401, 403], true) => StepOutcome::failed('merchant_key_denied'),
            $response['status'] >= 500 => StepOutcome::retry('app_delete_unavailable'),
            default => StepOutcome::failed('app_delete_rejected'),
        };
    }
}
