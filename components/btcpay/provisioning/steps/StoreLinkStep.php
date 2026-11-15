<?php
namespace app\components\btcpay\provisioning\steps;

/**
 * SIP-022 B1 tabella 3.2: store BTCPay del negozio, con la chiave corrente del merchant. Un solo POST di creazione:
 * dopo un checkpoint in volo o un esito ignoto solo GET di riconciliazione, che adotta il marker esatto se è unico.
 * Lo snapshot è dell'esecutore (riga del mapping + account); il passo non persiste nulla.
 */
final class StoreLinkStep
{
    public function __construct(private readonly \Closure $withKey) {}

    /**
     * @param array $snapshot store_link (state, marker, remote_store_id, in_flight) + account_state + store_name
     */
    public function create(#[\SensitiveParameter] array $snapshot, #[\SensitiveParameter] ?string $apiKey): StepOutcome
    {
        $ready = self::ready($snapshot, $apiKey);
        if ($ready !== null) return $ready;
        // Mai un secondo POST: con un tentativo in volo o già incerto si può solo riconciliare.
        if (!empty($snapshot['in_flight']) || $snapshot['state'] !== 'pending' || ($snapshot['remote_store_id'] ?? null) !== null) return $this->reconcile($snapshot, $apiKey);
        $name = self::name((string) ($snapshot['store_name'] ?? ''), $snapshot['marker']);
        [$response, $failure] = StepOutcome::call(fn() => ($this->withKey)('POST', '/api/v1/stores', ['name' => $name], $apiKey), StepOutcome::reconcile('store_outcome_unknown'));
        if ($failure !== null) return $failure;
        return match (true) {
            $response['status'] === 200 => ($id = StepOutcome::remoteId($response['json'])) !== null
                ? StepOutcome::done(['remote_store_id' => $id]) : StepOutcome::reconcile('store_response_malformed'),
            $response['status'] === 429 => StepOutcome::retry('rate_limited', $response['retry_after']),
            in_array($response['status'], [401, 403], true) => StepOutcome::failed('merchant_key_denied'),
            $response['status'] >= 500 => StepOutcome::reconcile('store_outcome_unknown'),
            default => StepOutcome::failed('store_rejected'),
        };
    }

    /** GET degli store del merchant: un solo store con il marker = adottato; zero o più = resta da riconciliare. */
    public function reconcile(#[\SensitiveParameter] array $snapshot, #[\SensitiveParameter] ?string $apiKey): StepOutcome
    {
        $ready = self::ready($snapshot, $apiKey);
        if ($ready !== null) return $ready;
        [$response, $failure] = StepOutcome::call(fn() => ($this->withKey)('GET', '/api/v1/stores', null, $apiKey), StepOutcome::reconcile('store_lookup_unavailable'));
        if ($failure !== null) return $failure;
        if ($response['status'] === 429) return StepOutcome::retry('rate_limited', $response['retry_after']);
        if (in_array($response['status'], [401, 403], true)) return StepOutcome::failed('merchant_key_denied');
        if ($response['status'] !== 200 || !is_array($response['json']) || !array_is_list($response['json'])) return StepOutcome::reconcile('store_lookup_unavailable');
        return self::adopt($response['json'], 'name', $snapshot['marker'], 'remote_store_id', 'store');
    }

    /** Nome remoto: descrizione del negozio (accorciata) + marker esatto, unica chiave di riconciliazione. */
    public static function name(string $description, string $marker): string
    {
        $description = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $description) ?? '');
        return mb_substr($description === '' ? 'Negozio' : $description, 0, 60) . ' [napay-' . $marker . ']';
    }

    /** @internal anche per PosLinkStep */
    public static function adopt(array $items, string $field, string $marker, string $key, string $what): StepOutcome
    {
        $token = '[napay-' . $marker . ']';
        $matches = array_values(array_filter($items, fn($item) => is_array($item) && is_string($item[$field] ?? null) && str_ends_with($item[$field], ' ' . $token)));
        if (count($matches) !== 1) return StepOutcome::reconcile(count($matches) === 0 ? $what . '_not_found' : $what . '_ambiguous');
        $id = StepOutcome::remoteId($matches[0]);
        return $id === null ? StepOutcome::reconcile($what . '_lookup_malformed') : StepOutcome::done([$key => $id]);
    }

    /** Già sincronizzato o dipendenze non pronte: nessuna chiamata. */
    /** Stato del mapping, poi dipendenza: account sincronizzato con chiave corrente. */
    private static function ready(#[\SensitiveParameter] array $snapshot, #[\SensitiveParameter] ?string $apiKey): ?StepOutcome
    {
        $state = StepOutcome::linkState($snapshot, 'remote_store_id');
        if ($state !== null) return $state;
        if (($snapshot['account_state'] ?? null) !== 'synced' || $apiKey === null || $apiKey === '') return StepOutcome::pending('account_not_ready');
        return null;
    }
}
