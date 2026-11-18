<?php
namespace app\components\pos\btc;

use app\components\btcpay\provisioning\ProvisioningOutbox;
use app\components\payments\Egress;

/**
 * SIP-033 passo 3: fatture dall'app POS di BTCPay del terminale, senza chiavi (verifica su BTCPay 2.4.7: il POST del
 * modulo dell'app crea la fattura e risponde 302 verso /i/{id}; lo stato pubblico per BTC-CHAIN dà indirizzo e importo).
 * Origine = quella del provisioning (BTCPAY_PROVISION_ORIGIN), coerente con il digest BTCPAY_PROVISION_INSTANCE.
 * Solo HTTPS, CA e hostname verificati, nessun redirect seguito, proxy di uscita se configurato (Egress), timeout 5/15 s,
 * corpo <= 256 KiB e header <= 16 KiB. Mai testo di cURL, URL o risposte nei messaggi di errore.
 */
final class BtcpayPosGateway implements PosInvoiceGateway
{
    public const CONNECT_TIMEOUT = 5;
    public const TIMEOUT = 15;
    public const MAX_BODY = 262144;
    public const MAX_HEADERS = 16384;
    private const ID = '/^[A-Za-z0-9]{8,64}$/D';
    private const REMOTE_ID = '/^[A-Za-z0-9_-]{1,64}$/D';
    /** cURL: errori prima dell'invio della richiesta (risoluzione, connessione, TLS): la fattura non può esistere */
    private const NOT_SENT = [5, 6, 7, 35, 60, 97];

    private function __construct(private readonly string $origin, private readonly ?string $caFile, private readonly ?string $proxy) {}

    /** null = pagamenti BTC non configurati o configurazione incoerente (fail closed). */
    public static function fromEnv(): ?self
    {
        $origin = (string) getenv('BTCPAY_PROVISION_ORIGIN');
        $ca = (string) getenv('BTCPAY_PROVISION_CA_FILE');
        try {
            $instance = ProvisioningOutbox::instance();
        } catch (\Throwable $error) {
            return null;
        }
        $proxy = Egress::proxy();
        if ($instance === null || $origin === '' || $proxy === false || !hash_equals($instance, hash('sha256', $origin))) return null;
        if (preg_match('#^https://([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*)(?::([0-9]{1,5}))?$#D', $origin, $m) !== 1
            || strlen($m[1]) > 253 || (isset($m[2]) && ((int) $m[2] < 1 || (int) $m[2] > 65535))) return null;
        if ($ca !== '' && (!str_starts_with($ca, '/') || !is_file($ca) || !is_readable($ca))) return null;
        return new self($origin, $ca === '' ? null : $ca, $proxy);
    }

    public function instance(): string
    {
        return hash('sha256', $this->origin);
    }

    public function checkoutUrl(string $invoiceId): string
    {
        if (preg_match(self::ID, $invoiceId) !== 1) throw new \InvalidArgumentException('invalid_invoice');
        return $this->origin . '/i/' . $invoiceId;
    }

    public function createInvoice(string $appId, int $amountCents, string $orderId): array
    {
        if (preg_match(self::REMOTE_ID, $appId) !== 1 || $amountCents < 1 || preg_match('/^[0-9a-f-]{36}$/D', $orderId) !== 1) throw new \InvalidArgumentException('invalid_invoice_request');
        $form = http_build_query(['amount' => intdiv($amountCents, 100) . '.' . str_pad((string) ($amountCents % 100), 2, '0', STR_PAD_LEFT), 'orderId' => $orderId]);
        [$status, $headers] = $this->send('POST', '/apps/' . $appId . '/pos', $form, true);
        if ($status === null) return ['outcome' => 'rejected', 'code' => 'btcpay_unavailable'];
        if ($status === 302 || $status === 303) {
            $location = $headers['location'][0] ?? '';
            $prefix = '#^(?:' . preg_quote($this->origin, '#') . ')?/i/([A-Za-z0-9]{8,64})(?:[/?\#]|$)#D';
            // 302 verso la pagina dell'app (non verso una fattura) = importo o modulo rifiutati
            return preg_match($prefix, $location, $m) === 1 ? ['outcome' => 'created', 'invoice_id' => $m[1]] : ['outcome' => 'rejected', 'code' => 'invoice_rejected'];
        }
        return match (true) {
            // 200 = pagina dell'app di nuovo, senza fattura (modulo non accettato)
            $status < 300 => ['outcome' => 'rejected', 'code' => 'invoice_rejected'],
            $status === 404 => ['outcome' => 'rejected', 'code' => 'app_missing'],
            $status === 429 => ['outcome' => 'rejected', 'code' => 'rate_limited'],
            $status >= 400 && $status < 500 => ['outcome' => 'rejected', 'code' => 'invoice_rejected'],
            default => throw new PosInvoiceUnknown(),
        };
    }

    public function status(string $invoiceId): ?array
    {
        if (preg_match(self::ID, $invoiceId) !== 1) throw new \InvalidArgumentException('invalid_invoice');
        try {
            [$status, , $body] = $this->send('GET', '/i/' . $invoiceId . '/BTC-CHAIN/status', null, false);
        } catch (PosInvoiceUnknown $error) {
            return null;
        }
        if ($status !== 200 || $body === '') return null;
        try { $d = json_decode($body, true, 16, JSON_THROW_ON_ERROR); } catch (\JsonException $error) { return null; }
        if (!is_array($d) || ($d['paymentMethodId'] ?? null) !== 'BTC-CHAIN') return null;
        foreach (['invoiceId' => self::ID, 'storeId' => self::REMOTE_ID, 'invoiceCurrency' => '/^[A-Z]{3}$/D', 'status' => '/^[A-Za-z]{3,24}$/D'] as $field => $pattern) {
            if (!is_string($d[$field] ?? null) || preg_match($pattern, $d[$field]) !== 1) return null;
        }
        $fiat = is_string($d['orderAmountFiat'] ?? null) ? ltrim(preg_replace('/[^0-9]/', '', $d['orderAmountFiat']) ?? '', '0') : '';
        $address = is_string($d['address'] ?? null) && preg_match('/^[A-Za-z0-9]{14,90}$/D', $d['address']) === 1 ? $d['address'] : null;
        $due = is_string($d['due'] ?? null) ? self::satoshis($d['due']) : null;
        $orderId = is_string($d['orderId'] ?? null) && strlen($d['orderId']) <= 64 ? $d['orderId'] : null;
        $seconds = is_int($d['expirationSeconds'] ?? null) && $d['expirationSeconds'] >= 0 && $d['expirationSeconds'] <= 86400 ? $d['expirationSeconds'] : null;
        $confirmations = is_int($d['receivedConfirmations'] ?? null) && $d['receivedConfirmations'] >= 0 ? min($d['receivedConfirmations'], 1000000) : 0;
        // SIP-033 passo 5: importo dell'ordine (senza fee di rete), BTC già ricevuti, cambio usato da BTCPay
        $order = is_string($d['orderAmount'] ?? null) ? self::satoshis($d['orderAmount']) : null;
        $paid = ($d['paid'] ?? null) === '0.00000000' ? 0 : (is_string($d['paid'] ?? null) ? self::satoshis($d['paid']) : null);
        return ['invoice_id' => $d['invoiceId'], 'order_id' => $orderId, 'store_id' => $d['storeId'], 'currency' => $d['invoiceCurrency'], 'fiat_digits' => $fiat,
            'status' => strtolower($d['status']), 'address' => $address, 'due_satoshis' => $address === null ? null : $due, 'confirmations' => $confirmations,
            'expires_in' => $seconds, 'order_satoshis' => $order, 'paid_satoshis' => $paid, 'rate_cents' => is_string($d['rate'] ?? null) ? self::rateCents($d['rate']) : null];
    }

    /**
     * Ricevuta pubblica della fattura (HTML, pagamenti mostrati: impostazione fissa degli store): solo i txid dei link
     * all'explorer ("/tx/<64 esadecimali>"), in ordine e senza doppioni. Nient'altro della pagina viene usato.
     */
    public function transactions(string $invoiceId): ?array
    {
        if (preg_match(self::ID, $invoiceId) !== 1) throw new \InvalidArgumentException('invalid_invoice');
        try {
            [$status, , $body] = $this->send('GET', '/i/' . $invoiceId . '/receipt', null, true);
        } catch (PosInvoiceUnknown $error) {
            return null;
        }
        if ($status !== 200) return null;
        return self::txids($body);
    }

    /** @return list<string> txid dai link "/tx/<txid>" di una pagina HTML (al massimo 10, minuscoli, senza doppioni) */
    public static function txids(string $html): array
    {
        preg_match_all('~href="https?://[^"\s]{1,200}/tx/([0-9a-f]{64})"~', $html, $m);
        return array_slice(array_values(array_unique($m[1])), 0, 10);
    }

    /**
     * Cambio formattato da BTCPay nella lingua dello store ("57 464,70 €", "$57,464.70", "¥8,500,000") -> centesimi per 1 BTC.
     * Separatore decimale = ultimo punto o virgola seguito da esattamente 2 cifre; null se il formato non è riconosciuto.
     * Il chiamante lo accetta solo se coerente con importo e satoshi dell'ordine.
     */
    public static function rateCents(string $text): ?int
    {
        if (strlen($text) > 64) return null;
        $digits = preg_replace('/[^0-9.,]/', '', $text) ?? '';
        if (preg_match('/^([0-9.,]*?)[.,]([0-9]{2})$/D', $digits, $m) === 1) [$whole, $cents] = [preg_replace('/[.,]/', '', $m[1]) ?? '', $m[2]];
        else [$whole, $cents] = [preg_replace('/[.,]/', '', $digits) ?? '', '00'];
        if ($whole === '' || strlen($whole) > 11 || preg_match('/^[0-9]+$/D', $whole) !== 1) return null;
        $value = (int) $whole * 100 + (int) $cents;
        return $value >= 1 ? $value : null;
    }

    /** "0.00024680" -> 24680 (aritmetica intera, mai float); formato inatteso = null. */
    public static function satoshis(string $btc): ?int
    {
        if (preg_match('/^([0-9]{1,8})(?:\.([0-9]{1,8}))?$/D', $btc, $m) !== 1) return null;
        $value = (int) $m[1] * 100000000 + (int) str_pad($m[2] ?? '', 8, '0');
        return $value >= 1 && $value <= 2100000000000000 ? $value : null;
    }

    /**
     * @return array{0:?int, 1:array<string,list<string>>, 2:string} [stato HTTP o null se la richiesta non è partita, header, corpo]
     * @throws PosInvoiceUnknown errore dopo un possibile invio, risposta oltre i limiti o stato non HTTP
     */
    private function send(string $method, string $path, ?string $form, bool $html): array
    {
        $received = ''; $headerBytes = 0; $responseHeaders = [];
        $headers = ['Accept: ' . ($html ? 'text/html' : 'application/json'), 'Expect:'];
        if ($form !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $curl = curl_init();
        try {
            $options = [
                CURLOPT_URL => $this->origin . $path, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_PROXY => $this->proxy ?? '', CURLOPT_NOPROXY => '',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT, CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headerBytes, &$responseHeaders): int {
                    $headerBytes += strlen($line);
                    if ($headerBytes > self::MAX_HEADERS) return -1;
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
            if ($this->proxy !== null) $options += [CURLOPT_PROXYTYPE => CURLPROXY_HTTP, CURLOPT_HTTPPROXYTUNNEL => true];
            if ($this->caFile !== null) $options[CURLOPT_CAINFO] = $this->caFile;
            if ($form !== null) $options[CURLOPT_POSTFIELDS] = $form;
            if (!curl_setopt_array($curl, $options)) throw new PosInvoiceUnknown();
            $ok = curl_exec($curl) === true;
            $errno = curl_errno($curl);
            if (!$ok || $errno !== 0) {
                if (in_array($errno, self::NOT_SENT, true)) return [null, [], ''];
                throw new PosInvoiceUnknown();
            }
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($curl);
        }
        if ($status < 200 || $status > 599 || $status >= 500) throw new PosInvoiceUnknown();
        return [$status, $responseHeaders, $received];
    }
}
