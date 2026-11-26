<?php
namespace app\components\btcpay\provisioning;

use app\components\payments\Egress;

/**
 * SIP-022 B1: client HTTP Greenfield del provisioning. Solo HTTPS verso l'origine configurata, sempre tramite il proxy
 * di uscita (Egress, obbligatorio), CA e hostname verificati, nessun redirect, timeout 5/15 s, corpo <= 1 MiB e
 * header <= 16 KiB. Credenziali e payload passati a ogni richiesta, mai letti dall'ambiente né registrati.
 * Nessun ritentativo: stato e Retry-After vanno al chiamante (RetryPolicy, ProvisioningJobs).
 * L'SDK Greenfield non offre limiti di corpo/header, autenticazione Basic né Retry-After: qui cURL diretto,
 * con le stesse opzioni di trasporto di payments/BTCPay.
 */
final class ProvisioningHttpClient
{
    public const CONNECT_TIMEOUT = 5;
    public const TIMEOUT = 15;
    public const MAX_BODY = 1048576;
    public const MAX_HEADERS = 16384;
    private const METHODS = ['GET', 'POST', 'PUT', 'DELETE'];
    private const MAX_CREDENTIAL = 256;

    private readonly string $origin;
    private readonly string $proxy;

    /** @param string $origin https://host[:porta] senza percorso; $caFile CA aggiuntiva (es. regtest locale) */
    public function __construct(string $origin, private readonly ?string $caFile = null)
    {
        if (preg_match('#^https://([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*)(?::([0-9]{1,5}))?$#D', $origin, $m) !== 1
            || strlen($m[1]) > 253 || (isset($m[2]) && ((int) $m[2] < 1 || (int) $m[2] > 65535))) $this->refuse(ProvisioningHttpException::INVALID_CONFIG);
        if ($caFile !== null && (!str_starts_with($caFile, '/') || !is_file($caFile) || !is_readable($caFile))) $this->refuse(ProvisioningHttpException::INVALID_CONFIG);
        $proxy = Egress::proxy();
        if (!is_string($proxy)) $this->refuse(ProvisioningHttpException::INVALID_CONFIG);
        $this->origin = $origin;
        $this->proxy = $proxy;
    }

    /** Chiave API del merchant o del provisioner (header `Authorization: token`). */
    public function withKey(string $method, string $path, #[\SensitiveParameter] ?array $body, #[\SensitiveParameter] string $apiKey): array
    {
        if ($apiKey === '' || strlen($apiKey) > self::MAX_CREDENTIAL || preg_match('/^[\x21-\x7e]+$/D', $apiKey) !== 1) $this->refuse(ProvisioningHttpException::INVALID_REQUEST);
        return $this->send($method, $path, $body, 'token ' . $apiKey);
    }

    /** Utente tecnico con password (Basic): solo per creare o revocare le proprie chiavi. */
    public function withBasic(string $method, string $path, #[\SensitiveParameter] ?array $body, string $user, #[\SensitiveParameter] string $password): array
    {
        if ($user === '' || strlen($user) > self::MAX_CREDENTIAL || preg_match('/^[\x21-\x39\x3b-\x7e]+$/D', $user) !== 1
            || $password === '' || strlen($password) > self::MAX_CREDENTIAL) $this->refuse(ProvisioningHttpException::INVALID_REQUEST);
        return $this->send($method, $path, $body, 'Basic ' . base64_encode($user . ':' . $password));
    }

    /**
     * @return array{status:int, retry_after:?string, json:?array} solo risposte complete entro i limiti
     * @throws ProvisioningHttpException
     */
    private function send(string $method, string $path, #[\SensitiveParameter] ?array $body, #[\SensitiveParameter] string $authorization): array
    {
        // Solo percorsi API fissi: niente host, userinfo, query, frammento, percent-encoding o segmenti vuoti/punto.
        if (!in_array($method, self::METHODS, true) || preg_match('#^/api/v1(?:/[A-Za-z0-9_@~-][A-Za-z0-9._@~-]*)+$#D', $path) !== 1
            || preg_match('#/\.\.?(?:/|$)#', $path) === 1 || ($body !== null && $method === 'GET')) $this->refuse(ProvisioningHttpException::INVALID_REQUEST);
        $payload = null;
        if ($body !== null) {
            try { $payload = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); } catch (\JsonException $error) { $this->refuse(ProvisioningHttpException::INVALID_REQUEST); }
            if (strlen($payload) > self::MAX_BODY) $this->refuse(ProvisioningHttpException::INVALID_REQUEST);
        }
        $headers = ['Accept: application/json', 'Authorization: ' . $authorization, 'Expect:'];
        if ($payload !== null) $headers[] = 'Content-Type: application/json';
        $received = ''; $headerBytes = 0; $responseHeaders = [];
        $curl = curl_init();
        try {
            $options = [
                CURLOPT_URL => $this->origin . $path, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_PROXY => $this->proxy, CURLOPT_PROXYTYPE => CURLPROXY_HTTP, CURLOPT_HTTPPROXYTUNNEL => true, CURLOPT_NOPROXY => '',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT, CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headerBytes, &$responseHeaders): int {
                    $headerBytes += strlen($line);
                    if ($headerBytes > self::MAX_HEADERS) return -1;
                    // Nuova riga di stato (risposta del proxy al CONNECT, poi quella finale): restano solo gli header finali.
                    if (str_starts_with($line, 'HTTP/')) $responseHeaders = [];
                    elseif (($colon = strpos($line, ':')) !== false) $responseHeaders[strtolower(trim(substr($line, 0, $colon)))][] = trim(substr($line, $colon + 1));
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$received): int {
                    if (strlen($received) + strlen($chunk) > self::MAX_BODY) return -1;
                    $received .= $chunk;
                    return strlen($chunk);
                },
            ];
            if ($this->caFile !== null) $options[CURLOPT_CAINFO] = $this->caFile;
            if ($payload !== null) $options[CURLOPT_POSTFIELDS] = $payload;
            if (!curl_setopt_array($curl, $options)) $this->refuse(ProvisioningHttpException::INVALID_CONFIG);
            // Da qui ogni fallimento può arrivare dopo l'invio: esito ignoto, senza testo di cURL (contiene URL e host).
            if (curl_exec($curl) !== true || curl_errno($curl) !== 0) $this->refuse(ProvisioningHttpException::OUTCOME_UNKNOWN);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($curl);
            unset($payload, $headers, $options);
        }
        if ($status < 200 || $status > 599 || ($status >= 300 && $status < 400)) $this->refuse(ProvisioningHttpException::OUTCOME_UNKNOWN);
        $json = null;
        if ($received !== '') {
            try { $json = json_decode($received, true, 64, JSON_THROW_ON_ERROR); } catch (\JsonException $error) { $this->refuse(ProvisioningHttpException::OUTCOME_UNKNOWN); }
            if (!is_array($json)) $this->refuse(ProvisioningHttpException::OUTCOME_UNKNOWN);
        }
        $retryAfter = $responseHeaders['retry-after'] ?? null;
        return ['status' => $status, 'retry_after' => $retryAfter === null ? null : implode(', ', $retryAfter), 'json' => $json];
    }

    private function refuse(string $code): never
    {
        throw new ProvisioningHttpException($code);
    }
}
