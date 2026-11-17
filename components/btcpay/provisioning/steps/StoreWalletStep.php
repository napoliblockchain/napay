<?php
namespace app\components\btcpay\provisioning\steps;

/**
 * SIP-022 xpub (decisione A): metodo di pagamento on-chain dello store BTCPay con la xpub del merchant, chiave del merchant.
 * PUT/DELETE /api/v1/stores/{id}/payment-methods/BTC-CHAIN (BTCPay 2.x, canmodifystoresettings): idempotenti, un esito
 * ignoto si ripete; DELETE con 404 = già assente. La xpub non compare mai nei codici di errore.
 */
final class StoreWalletStep
{
    public const PAYMENT_METHOD = 'BTC-CHAIN';

    public function __construct(private readonly \Closure $withKey) {}

    public function set(string $remoteStoreId, #[\SensitiveParameter] string $derivationScheme, #[\SensitiveParameter] ?string $apiKey): StepOutcome
    {
        if (preg_match(StepOutcome::REMOTE_ID, $remoteStoreId) !== 1 || $derivationScheme === '' || strlen($derivationScheme) > 1000) throw new \InvalidArgumentException('invalid_snapshot');
        if ($apiKey === null || $apiKey === '') return StepOutcome::pending('account_not_ready');
        [$response, $failure] = StepOutcome::call(fn() => ($this->withKey)('PUT', self::path($remoteStoreId), ['enabled' => true, 'config' => $derivationScheme], $apiKey),
            StepOutcome::retry('wallet_update_unavailable'));
        return $failure !== null ? self::unsent($failure) : self::outcome($response, false);
    }

    public function remove(string $remoteStoreId, #[\SensitiveParameter] ?string $apiKey): StepOutcome
    {
        if (preg_match(StepOutcome::REMOTE_ID, $remoteStoreId) !== 1) throw new \InvalidArgumentException('invalid_snapshot');
        if ($apiKey === null || $apiKey === '') return StepOutcome::pending('account_not_ready');
        [$response, $failure] = StepOutcome::call(fn() => ($this->withKey)('DELETE', self::path($remoteStoreId), null, $apiKey), StepOutcome::retry('wallet_update_unavailable'));
        return $failure !== null ? self::unsent($failure) : self::outcome($response, true);
    }

    private static function path(string $remoteStoreId): string
    {
        return '/api/v1/stores/' . $remoteStoreId . '/payment-methods/' . self::PAYMENT_METHOD;
    }

    private static function unsent(StepOutcome $failure): StepOutcome
    {
        return $failure->kind === StepOutcome::FAILED ? StepOutcome::retry('request_not_sent') : $failure;
    }

    private static function outcome(array $response, bool $removing): StepOutcome
    {
        return match (true) {
            $response['status'] === 200, $removing && $response['status'] === 404 => StepOutcome::done(),
            $response['status'] === 429 => StepOutcome::retry('rate_limited', $response['retry_after']),
            in_array($response['status'], [401, 403], true) => StepOutcome::failed('merchant_key_denied'),
            $response['status'] === 404 => StepOutcome::failed('store_missing'),
            $response['status'] >= 500 => StepOutcome::retry('wallet_update_unavailable'),
            // 400/422: xpub non valida per questa rete o formato non accettato da BTCPay
            default => StepOutcome::failed('wallet_rejected'),
        };
    }
}
