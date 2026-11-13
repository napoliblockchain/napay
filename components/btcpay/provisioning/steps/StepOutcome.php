<?php
namespace app\components\btcpay\provisioning\steps;

use app\components\btcpay\provisioning\ProvisioningHttpException;

/**
 * SIP-022 B1 W2: esito tipizzato di un passo remoto. Il passo non persiste nulla: l'esecutore W3 applica l'esito
 * (stato, dati in allowlist, Retry-After grezzo per RetryPolicy). Una chiave API nuova viaggia solo avvolta in
 * SensitiveParameterValue, quindi non compare nei dump né nelle trace. Mai testo o corpo remoto.
 */
final class StepOutcome
{
    /** Passo riuscito: dati in allowlist (ID remoti, generazione). */
    public const DONE = 'done';
    /** A-r: utente assente su BTCPay, si può ripetere A con la stessa password salvata. */
    public const ABSENT = 'absent';
    /** A-r: utente presente, proprietà da provare con il passo B (password salvata in Basic). */
    public const FOUND = 'found';
    /** B: esito ignoto, ammessa una nuova generazione entro il limite. */
    public const REPEAT = 'repeat';
    /** Nessuna esecuzione remota (429) o lettura ripetibile: riprovare più tardi, Retry-After grezzo se presente. */
    public const RETRY = 'retry';
    /** B1 §3.1 passo B: 429 sulla creazione della chiave. Retry-After grezzo; nessuna nuova generazione né chiamata automatica. */
    public const DEFERRED = 'deferred';
    /** Esito remoto ignoto o ambiguo: solo riconciliazione, mai un nuovo POST di creazione. */
    public const RECONCILE = 'reconcile';
    /** Errore definitivo con esito noto (es. 401/403): nessuna escalation. */
    public const FAILED = 'failed';
    /** Dipendenza non pronta (account o store non sincronizzati): nessuna chiamata. */
    public const PENDING = 'pending';

    private const DATA = ['remote_user_id', 'remote_store_id', 'remote_app_id', 'generation'];
    public const LINK_STATES = ['pending', 'synced', 'reconcile_required', 'failed', 'suspended'];
    public const REMOTE_ID = '/^[A-Za-z0-9_-]{1,64}$/D';

    private function __construct(public readonly string $kind, public readonly ?string $code, public readonly array $data,
        public readonly ?string $retryAfter, private readonly ?\SensitiveParameterValue $secret) {}

    public static function done(array $data = [], ?\SensitiveParameterValue $secret = null): self { return self::make(self::DONE, null, $data, null, $secret); }
    public static function absent(): self { return self::make(self::ABSENT, null, [], null, null); }
    public static function found(string $remoteUserId): self { return self::make(self::FOUND, null, ['remote_user_id' => $remoteUserId], null, null); }
    public static function repeat(string $code): self { return self::make(self::REPEAT, $code, [], null, null); }
    public static function retry(string $code, ?string $retryAfter = null): self { return self::make(self::RETRY, $code, [], $retryAfter, null); }
    public static function deferred(string $code, ?string $retryAfter): self { return self::make(self::DEFERRED, $code, [], $retryAfter, null); }
    public static function reconcile(string $code): self { return self::make(self::RECONCILE, $code, [], null, null); }
    public static function failed(string $code): self { return self::make(self::FAILED, $code, [], null, null); }
    public static function pending(string $code): self { return self::make(self::PENDING, $code, [], null, null); }

    /** Segreto restituito dal passo (oggi solo la chiave API creata in B). */
    public function secret(): ?string
    {
        return $this->secret?->getValue();
    }

    /**
     * Esegue una sola chiamata HTTP. null = nessuna risposta utilizzabile: $unknown se la richiesta può essere
     * arrivata (esito ignoto), FAILED se il client l'ha rifiutata prima di inviarla.
     * @return array{0: ?array, 1: ?self} [risposta, esito di trasporto]
     */
    public static function call(#[\SensitiveParameter] callable $request, self $unknown): array
    {
        try {
            return [$request(), null];
        } catch (ProvisioningHttpException $error) {
            return [null, $error->notSent() ? self::failed('request_not_sent') : $unknown];
        }
    }

    /** Lista JSON di soli oggetti con ID remoto valido (store o app), altrimenti null. */
    public static function objectList(?array $json): ?array
    {
        if (!is_array($json) || !array_is_list($json)) return null;
        foreach ($json as $item) if (!is_array($item) || self::remoteId($item) === null) return null;
        return $json;
    }

    /** ID remoto dal corpo JSON (oggetto con chiave `id`), o null se assente/malformato. */
    public static function remoteId(?array $json): ?string
    {
        $id = $json['id'] ?? null;
        return is_string($id) && preg_match(self::REMOTE_ID, $id) === 1 ? $id : null;
    }

    /**
     * Mapping store/app: stato in allowlist; `failed`/`suspended` restano tali anche con un vecchio ID; solo un mapping
     * `synced` con ID valido è già fatto (ID assente o malformato = snapshot rifiutato). Altrimenti null: si procede.
     */
    public static function linkState(#[\SensitiveParameter] array $snapshot, string $idField): ?self
    {
        $state = $snapshot['state'] ?? null;
        if (!in_array($state, self::LINK_STATES, true) || !is_string($snapshot['marker'] ?? null) || preg_match('/^[0-9a-f]{32}$/D', $snapshot['marker']) !== 1) {
            throw new \InvalidArgumentException('invalid_snapshot');
        }
        $id = $snapshot[$idField] ?? null;
        if ($id !== null && (!is_string($id) || preg_match(self::REMOTE_ID, $id) !== 1)) throw new \InvalidArgumentException('invalid_snapshot');
        if ($state === 'failed' || $state === 'suspended') return self::failed('link_' . $state);
        if ($state === 'synced') return $id !== null ? self::done([$idField => $id]) : throw new \InvalidArgumentException('invalid_snapshot');
        return null;
    }

    private static function make(string $kind, ?string $code, array $data, ?string $retryAfter, ?\SensitiveParameterValue $secret): self
    {
        if ($code !== null && preg_match('/^[a-z0-9_]{1,64}$/D', $code) !== 1) throw new \InvalidArgumentException('invalid_code');
        if (array_diff(array_keys($data), self::DATA) !== []) throw new \InvalidArgumentException('invalid_data');
        return new self($kind, $code, $data, $retryAfter, $secret);
    }
}
