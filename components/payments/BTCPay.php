<?php

namespace app\components\payments;

use BTCPayServer\Client\Invoice;
use BTCPayServer\Client\InvoiceCheckoutOptions;
use BTCPayServer\Client\Webhook;
use BTCPayServer\Http\CurlClient;
use BTCPayServer\Util\PreciseNumber;
use yii\base\InvalidConfigException;

/**
 * BTCPay Server, API Greenfield (TIP-020 passo 5c), con la libreria ufficiale btcpayserver-greenfield-php.
 * Configurazione solo da env: BTCPAY_URL (https), BTCPAY_STORE_ID, BTCPAY_API_KEY (solo crea/vedi fatture
 * dello store), BTCPAY_WEBHOOK_SECRET. Timeout, niente redirect, TLS verificato; mai chiavi nei log.
 */
final class BTCPay
{
    public static function enabled(): bool
    {
        try {
            self::config();
            return true;
        } catch (InvalidConfigException $error) {
            return false;
        }
    }

    /**
     * @return array{url: string, store: string, key: string, secret: string, scheme: string}
     * @throws InvalidConfigException
     */
    public static function config(): array
    {
        $url = rtrim(trim((string) getenv('BTCPAY_URL')), '/');
        $store = trim((string) getenv('BTCPAY_STORE_ID'));
        $key = (string) getenv('BTCPAY_API_KEY');
        $secret = (string) getenv('BTCPAY_WEBHOOK_SECRET');
        if (!preg_match('/^[A-Za-z0-9]{20,64}$/D', $store) || $key === '' || strlen($key) > 256 || strlen($secret) < 16 || strlen($secret) > 256) {
            throw new InvalidConfigException('BTCPay not configured.');
        }
        $parts = parse_url($url);
        $https = ($parts['scheme'] ?? null) === 'https' && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['query']);
        // server finto solo nella suite di test isolata
        $mock = getenv('NAPAY_TEST_DB_CONFIRM') === 'isolated-fixture' && preg_match('#^http://127\.0\.0\.1:\d{2,5}$#D', $url);
        if (!$https && !$mock) throw new InvalidConfigException('BTCPay not configured.');
        return ['url' => $url, 'store' => $store, 'key' => $key, 'secret' => $secret, 'scheme' => $https ? 'https' : 'http'];
    }

    /**
     * Fattura in EUR per il pagamento quota; restituisce [invoice id, link di pagamento verificato].
     * @throws PaymentGatewayException
     */
    public static function createInvoice(int $paymentId, string $amount, string $description, string $redirectUrl): array
    {
        return self::guard(static function () use ($paymentId, $amount, $description, $redirectUrl) {
            $config = self::config();
            $options = (new InvoiceCheckoutOptions())->setRedirectURL($redirectUrl)->setRedirectAutomatically(true);
            $invoice = self::client($config)->createInvoice($config['store'], 'EUR', PreciseNumber::parseString($amount),
                self::orderId($paymentId), null, ['itemDesc' => mb_substr($description, 0, 120)], $options);
            $id = (string) $invoice->getId();
            $link = (string) $invoice->getCheckoutLink();
            if (!preg_match('/^[A-Za-z0-9]{8,64}$/D', $id) || !self::isCheckoutUrl($link, $config)) {
                throw new PaymentGatewayException('Unexpected BTCPay invoice response.');
            }
            return [$id, $link];
        });
    }

    /**
     * Stato attuale di una fattura letto da BTCPay: id, status, amount, currency, orderId.
     * @throws PaymentGatewayException
     */
    public static function getInvoice(string $invoiceId): array
    {
        if (!preg_match('/^[A-Za-z0-9]{8,64}$/D', $invoiceId)) throw new PaymentGatewayException('Invalid BTCPay invoice id.');
        return self::guard(static function () use ($invoiceId) {
            $config = self::config();
            $data = self::client($config)->getInvoice($config['store'], $invoiceId)->getData();
            return [
                'id' => (string) ($data['id'] ?? ''),
                'status' => (string) ($data['status'] ?? ''),
                'amount' => (string) ($data['amount'] ?? ''),
                'currency' => (string) ($data['currency'] ?? ''),
                'orderId' => (string) ($data['metadata']['orderId'] ?? ''),
            ];
        });
    }

    /** Firma BTCPay-Sig (HMAC-SHA256 del corpo) con il secret del webhook, confronto a tempo costante */
    public static function isWebhookValid(string $body, ?string $signature): bool
    {
        try {
            $secret = self::config()['secret'];
        } catch (InvalidConfigException $error) {
            return false;
        }
        return is_string($signature) && strlen($signature) <= 128 && Webhook::isIncomingWebhookRequestValid($body, $signature, $secret);
    }

    public static function storeId(): ?string
    {
        try {
            return self::config()['store'];
        } catch (InvalidConfigException $error) {
            return null;
        }
    }

    public static function orderId(int $paymentId): string
    {
        return 'napay-fee-' . $paymentId;
    }

    /** Il socio va solo sul checkout del server BTCPay configurato */
    public static function isCheckoutUrl(string $url, ?array $config = null): bool
    {
        try {
            $config = $config ?? self::config();
        } catch (InvalidConfigException $error) {
            return false;
        }
        $base = parse_url($config['url']);
        $parts = parse_url($url);
        return ($parts['scheme'] ?? null) === $config['scheme'] && ($parts['host'] ?? null) === ($base['host'] ?? null)
            && ($parts['port'] ?? null) === ($base['port'] ?? null) && !isset($parts['user']);
    }

    private static function client(array $config): Invoice
    {
        $proxy = Egress::proxy();
        if ($proxy === false) throw new PaymentGatewayException('Invalid PAYMENT_PROXY.');
        $http = new CurlClient();
        $http->setCurlOptions(($proxy !== null ? [CURLOPT_PROXY => $proxy, CURLOPT_PROXYTYPE => CURLPROXY_HTTP] : []) + [
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => $config['scheme'] === 'https' ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
        ]);
        return new Invoice($config['url'], $config['key'], $http);
    }

    /** Errori della libreria convertiti in messaggi sicuri (classe soltanto: i messaggi contengono URL e risposte) */
    private static function guard(callable $call)
    {
        try {
            return $call();
        } catch (PaymentGatewayException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new PaymentGatewayException('BTCPay unavailable (' . get_class($error) . ').');
        }
    }
}
