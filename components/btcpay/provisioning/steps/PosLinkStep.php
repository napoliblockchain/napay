<?php
namespace app\components\btcpay\provisioning\steps;

/**
 * SIP-022 B1 tabella 3.2: app POS di BTCPay per il terminale, nello store già sincronizzato del suo negozio.
 * Stesse regole dello store: un solo POST, poi solo GET di riconciliazione con adozione del marker unico.
 * SIP-033: l'app nasce configurata per gli incassi del POS Napay (importo libero, tastierino, valuta del negozio) e
 * store_update la riallinea con configure(): il PUT di BTCPay sostituisce tutta la configurazione, quindi sempre completa.
 */
final class PosLinkStep
{
    public const APP_TYPE = 'PointOfSale';
    public const VIEW = 'Light';

    public function __construct(private readonly \Closure $withKey) {}

    /**
     * @param array $snapshot pos_link (state, marker, remote_app_id, in_flight) + account_state + remote_store_id + terminal_name
     *                        + currency (valuta del negozio, opzionale)
     */
    public function create(#[\SensitiveParameter] array $snapshot, #[\SensitiveParameter] ?string $apiKey): StepOutcome
    {
        $ready = self::ready($snapshot, $apiKey);
        if ($ready !== null) return $ready;
        if (!empty($snapshot['in_flight']) || $snapshot['state'] !== 'pending' || ($snapshot['remote_app_id'] ?? null) !== null) return $this->reconcile($snapshot, $apiKey);
        $name = StoreLinkStep::name((string) ($snapshot['terminal_name'] ?? ''), $snapshot['marker']);
        [$response, $failure] = StepOutcome::call(fn() => ($this->withKey)('POST', '/api/v1/stores/' . $snapshot['remote_store_id'] . '/apps/pos',
            self::config($name, $snapshot['currency'] ?? null), $apiKey), StepOutcome::reconcile('app_outcome_unknown'));
        if ($failure !== null) return $failure;
        return match (true) {
            // Solo un'app POS (B0: appType PointOfSale) vale come creata.
            $response['status'] === 200 => ($id = StepOutcome::remoteId($response['json'])) !== null && ($response['json']['appType'] ?? null) === self::APP_TYPE
                ? StepOutcome::done(['remote_app_id' => $id]) : StepOutcome::reconcile('app_response_malformed'),
            $response['status'] === 429 => StepOutcome::retry('rate_limited', $response['retry_after']),
            in_array($response['status'], [401, 403], true) => StepOutcome::failed('merchant_key_denied'),
            $response['status'] >= 500 => StepOutcome::reconcile('app_outcome_unknown'),
            default => StepOutcome::failed('app_rejected'),
        };
    }

    /** SIP-033: configurazione completa dell'app già collegata (PUT idempotente: esito ignoto = nuovo tentativo). */
    public function configure(string $remoteAppId, string $appName, ?string $currency, #[\SensitiveParameter] ?string $apiKey): StepOutcome
    {
        if (preg_match(StepOutcome::REMOTE_ID, $remoteAppId) !== 1 || trim($appName) === '') throw new \InvalidArgumentException('invalid_snapshot');
        if ($apiKey === null || $apiKey === '') return StepOutcome::pending('account_not_ready');
        [$response, $failure] = StepOutcome::call(fn() => ($this->withKey)('PUT', '/api/v1/apps/pos/' . $remoteAppId, self::config($appName, $currency), $apiKey),
            StepOutcome::retry('app_update_unavailable'));
        if ($failure !== null) return $failure->kind === StepOutcome::FAILED ? StepOutcome::retry('request_not_sent') : $failure;
        return match (true) {
            $response['status'] === 200 => StepOutcome::done(),
            $response['status'] === 429 => StepOutcome::retry('rate_limited', $response['retry_after']),
            in_array($response['status'], [401, 403], true) => StepOutcome::failed('merchant_key_denied'),
            $response['status'] === 404 => StepOutcome::failed('app_missing'),
            $response['status'] >= 500 => StepOutcome::retry('app_update_unavailable'),
            default => StepOutcome::failed('app_update_rejected'),
        };
    }

    /** Importo libero e vista tastierino (incasso dal POS Napay); valuta solo se valida, altrimenti quella dello store. */
    public static function config(string $appName, ?string $currency): array
    {
        $config = ['appName' => $appName, 'defaultView' => self::VIEW, 'showCustomAmount' => true];
        if (is_string($currency) && preg_match('/^[A-Z]{3}$/D', $currency) === 1) $config['currency'] = $currency;
        return $config;
    }

    public function reconcile(#[\SensitiveParameter] array $snapshot, #[\SensitiveParameter] ?string $apiKey): StepOutcome
    {
        $ready = self::ready($snapshot, $apiKey);
        if ($ready !== null) return $ready;
        [$response, $failure] = StepOutcome::call(fn() => ($this->withKey)('GET', '/api/v1/stores/' . $snapshot['remote_store_id'] . '/apps', null, $apiKey),
            StepOutcome::reconcile('app_lookup_unavailable'));
        if ($failure !== null) return $failure;
        if ($response['status'] === 429) return StepOutcome::retry('rate_limited', $response['retry_after']);
        if (in_array($response['status'], [401, 403], true)) return StepOutcome::failed('merchant_key_denied');
        if ($response['status'] !== 200 || !is_array($response['json']) || !array_is_list($response['json'])) return StepOutcome::reconcile('app_lookup_unavailable');
        // Si adotta solo un'app PointOfSale con il marker: un'app di altro tipo con lo stesso nome non conta.
        $apps = array_values(array_filter($response['json'], fn($app) => is_array($app) && ($app['appType'] ?? null) === self::APP_TYPE));
        return StoreLinkStep::adopt($apps, 'appName', $snapshot['marker'], 'remote_app_id', 'app');
    }

    /** Stato del mapping, poi dipendenze: account sincronizzato con chiave e store del negozio sincronizzato. */
    private static function ready(#[\SensitiveParameter] array $snapshot, #[\SensitiveParameter] ?string $apiKey): ?StepOutcome
    {
        $state = StepOutcome::linkState($snapshot, 'remote_app_id');
        if ($state !== null) return $state;
        if (($snapshot['account_state'] ?? null) !== 'synced' || $apiKey === null || $apiKey === '') return StepOutcome::pending('account_not_ready');
        // Lo store del negozio deve essere già sincronizzato con un ID remoto valido.
        if (!is_string($snapshot['remote_store_id'] ?? null) || preg_match(StepOutcome::REMOTE_ID, $snapshot['remote_store_id']) !== 1) return StepOutcome::pending('store_not_ready');
        return null;
    }
}
