<?php
namespace app\components\btcpay\provisioning\steps;

/**
 * SIP-022 B1 tabella 3.1: account tecnico del merchant, una chiamata per metodo, nessuna persistenza.
 * $withKey(method, path, ?body, apiKey) e $withBasic(method, path, ?body, user, password) sono i metodi del
 * ProvisioningHttpClient approvato. L'esecutore W3 salva il checkpoint prima di ogni POST e applica l'esito.
 */
final class MerchantAccountStep
{
    /** Limite di generazioni di chiave per tentativo (B1 §3.1): oltre, solo riconciliazione. */
    public const MAX_GENERATIONS = 3;
    public const STORE_PERMISSION = 'btcpay.store.canmodifystoresettings';

    public function __construct(private readonly \Closure $withKey, private readonly \Closure $withBasic) {}

    /** A: crea l'utente tecnico con la password casuale già salvata cifrata (chiave del provisioner). */
    public function createUser(string $email, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $provisionerKey): StepOutcome
    {
        self::email($email);
        [$response, $failure] = StepOutcome::call(fn() => ($this->withKey)('POST', '/api/v1/users',
            ['email' => $email, 'password' => $password, 'isAdministrator' => false], $provisionerKey), StepOutcome::reconcile('user_outcome_unknown'));
        if ($failure !== null) return $failure;
        return match (true) {
            in_array($response['status'], [200, 201], true) => ($id = StepOutcome::remoteId($response['json'])) !== null
                ? StepOutcome::done(['remote_user_id' => $id]) : StepOutcome::reconcile('user_response_malformed'),
            // Stessa email già registrata: chi l'ha creata si scopre solo con A-r + prova della password (B).
            $response['status'] === 422 => StepOutcome::reconcile('user_exists'),
            $response['status'] === 429 => StepOutcome::retry('rate_limited', $response['retry_after']),
            in_array($response['status'], [401, 403], true) => StepOutcome::failed('provisioner_denied'),
            $response['status'] >= 500 => StepOutcome::reconcile('user_outcome_unknown'),
            default => StepOutcome::failed('user_rejected'),
        };
    }

    /** A-r: cerca l'utente per email tecnica. Mai reset o modifica di un utente esistente. */
    public function findUser(string $email, #[\SensitiveParameter] string $provisionerKey): StepOutcome
    {
        self::email($email);
        [$response, $failure] = StepOutcome::call(fn() => ($this->withKey)('GET', '/api/v1/users/' . $email, null, $provisionerKey), StepOutcome::retry('lookup_unavailable'));
        if ($failure !== null) return $failure;
        return match (true) {
            $response['status'] === 200 => ($id = StepOutcome::remoteId($response['json'])) !== null && ($response['json']['email'] ?? null) === $email
                ? StepOutcome::found($id) : StepOutcome::reconcile('user_lookup_malformed'),
            $response['status'] === 404 => StepOutcome::absent(),
            $response['status'] === 429 => StepOutcome::retry('rate_limited', $response['retry_after']),
            in_array($response['status'], [401, 403], true) => StepOutcome::failed('provisioner_denied'),
            default => StepOutcome::retry('lookup_unavailable'),
        };
    }

    /**
     * B: l'utente tecnico crea la propria chiave (Basic con la password salvata), limitata agli store.
     * Riuscita anche dopo A-r = prova di proprietà. Esito ignoto: nuova generazione entro il limite.
     */
    public function createKey(string $email, #[\SensitiveParameter] string $password, string $ref, int $generation): StepOutcome
    {
        self::email($email);
        if (preg_match('/^[0-9a-f]{32}$/D', $ref) !== 1 || $generation < 1) throw new \InvalidArgumentException('invalid_key_request');
        if ($generation > self::MAX_GENERATIONS) return StepOutcome::reconcile('generations_exhausted');
        [$response, $failure] = StepOutcome::call(fn() => ($this->withBasic)('POST', '/api/v1/api-keys',
            ['label' => 'napay-' . $ref . '-g' . $generation, 'permissions' => [self::STORE_PERMISSION]], $email, $password), StepOutcome::repeat('key_outcome_unknown'));
        if ($failure !== null) return $failure;
        if ($response['status'] === 200) {
            $key = $response['json']['apiKey'] ?? null;
            // Chiave illeggibile: forse creata ma non ricevuta, come un esito ignoto.
            if (!is_string($key) || preg_match('/^[\x21-\x7e]{16,256}$/D', $key) !== 1) return StepOutcome::repeat('key_response_malformed');
            // Solo il permesso richiesto: una chiave più ampia non diventa mai un segreto utilizzabile.
            if (($response['json']['permissions'] ?? null) !== [self::STORE_PERMISSION]) return StepOutcome::reconcile('key_permissions_unexpected');
            return StepOutcome::done(['generation' => $generation], new \SensitiveParameterValue($key));
        }
        return match (true) {
            $response['status'] === 401 => StepOutcome::reconcile('password_rejected'),
            $response['status'] === 429 => StepOutcome::deferred('rate_limited', $response['retry_after']),
            $response['status'] === 403 => StepOutcome::failed('key_forbidden'),
            $response['status'] >= 500 => StepOutcome::repeat('key_outcome_unknown'),
            default => StepOutcome::failed('key_rejected'),
        };
    }

    /** C: la nuova chiave legge gli store del merchant; solo dopo questo esito l'esecutore la marca verificata e corrente. */
    public function verifyKey(#[\SensitiveParameter] string $apiKey): StepOutcome
    {
        [$response, $failure] = StepOutcome::call(fn() => ($this->withKey)('GET', '/api/v1/stores', null, $apiKey), StepOutcome::retry('verify_unavailable'));
        if ($failure !== null) return $failure;
        return match (true) {
            $response['status'] === 200 => StepOutcome::objectList($response['json']) !== null
                ? StepOutcome::done() : StepOutcome::retry('verify_response_malformed'),
            $response['status'] === 429 => StepOutcome::retry('rate_limited', $response['retry_after']),
            in_array($response['status'], [401, 403], true) => StepOutcome::reconcile('key_not_accepted'),
            default => StepOutcome::retry('verify_unavailable'),
        };
    }

    private static function email(string $email): void
    {
        if (preg_match('/^m-[0-9a-f]{32}@btcpay-merchants\.jamprojects\.it$/D', $email) !== 1) throw new \InvalidArgumentException('invalid_technical_email');
    }
}
