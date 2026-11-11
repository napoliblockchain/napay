<?php

namespace app\components\payments;

use Yii;
use GuzzleHttp\Client;
use yii\base\InvalidConfigException;

/**
 * PayPal Checkout, Orders v2 (TIP-020 passo 5b): ordine CAPTURE creato e catturato dal server.
 * Configurazione solo da env: PAYPAL_CLIENT_ID, PAYPAL_CLIENT_SECRET, PAYPAL_MODE=sandbox|live.
 * Host fissi, TLS verificato, nessun redirect seguito, risposte limitate. Mai credenziali o token nei log.
 */
final class PayPal
{
    private const API = ['sandbox' => 'https://api-m.sandbox.paypal.com', 'live' => 'https://api-m.paypal.com'];
    /** Host ammessi per il link di approvazione verso cui si reindirizza il socio */
    public const APPROVE_HOSTS = ['www.sandbox.paypal.com', 'www.paypal.com'];
    private const MAX_RESPONSE = 65536;

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
     * @return array{base: string, id: string, secret: string}
     * @throws InvalidConfigException
     */
    public static function config(): array
    {
        $mode = trim((string) getenv('PAYPAL_MODE'));
        $id = (string) getenv('PAYPAL_CLIENT_ID');
        $secret = (string) getenv('PAYPAL_CLIENT_SECRET');
        if ($id === '' || $secret === '' || strlen($id) > 256 || strlen($secret) > 256) throw new InvalidConfigException('PayPal not configured.');
        if (isset(self::API[$mode])) return ['base' => self::API[$mode], 'id' => $id, 'secret' => $secret];
        // server finto solo nella suite di test isolata, mai in un ambiente reale
        if ($mode === 'mock' && getenv('NAPAY_TEST_DB_CONFIRM') === 'isolated-fixture'
            && preg_match('#^http://127\.0\.0\.1:\d{2,5}$#D', (string) getenv('PAYPAL_MOCK_BASE'))) {
            return ['base' => (string) getenv('PAYPAL_MOCK_BASE'), 'id' => $id, 'secret' => $secret];
        }
        throw new InvalidConfigException('PayPal not configured.');
    }

    /**
     * Crea l'ordine e restituisce [order id, URL di approvazione verificato].
     * @throws PayPalException
     */
    public static function createOrder(int $paymentId, string $amount, string $description, string $returnUrl, string $cancelUrl): array
    {
        $response = self::call('POST', '/v2/checkout/orders', 'napay-order-' . $paymentId, [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => 'membership-fee',
                'custom_id' => (string) $paymentId,
                'description' => mb_substr($description, 0, 127),
                'amount' => ['currency_code' => 'EUR', 'value' => $amount],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'return_url' => $returnUrl, 'cancel_url' => $cancelUrl,
                'user_action' => 'PAY_NOW', 'shipping_preference' => 'NO_SHIPPING',
            ]]],
        ]);
        $id = $response['id'] ?? null;
        $approve = null;
        foreach ($response['links'] ?? [] as $link) {
            if (in_array($link['rel'] ?? null, ['payer-action', 'approve'], true)) $approve = $link['href'] ?? null;
        }
        if (!is_string($id) || !preg_match('/^[A-Z0-9]{8,36}$/D', $id) || !is_string($approve) || !self::isApproveUrl($approve)) {
            throw new PayPalException('Unexpected PayPal order response.');
        }
        return [$id, $approve];
    }

    /**
     * Cattura l'ordine approvato e restituisce la capture verificata (id, importo, valuta, riferimento).
     * @throws PayPalException
     */
    public static function captureOrder(string $orderId, int $paymentId): array
    {
        if (!preg_match('/^[A-Z0-9]{8,36}$/D', $orderId)) throw new PayPalException('Invalid PayPal order id.');
        $response = self::call('POST', '/v2/checkout/orders/' . $orderId . '/capture', 'napay-capture-' . $paymentId, null);
        $capture = $response['purchase_units'][0]['payments']['captures'][0] ?? null;
        if (($response['status'] ?? null) !== 'COMPLETED' || !is_array($capture) || ($capture['status'] ?? null) !== 'COMPLETED') {
            throw new PayPalException('PayPal capture not completed.');
        }
        return [
            'id' => (string) ($capture['id'] ?? ''),
            'amount' => (string) ($capture['amount']['value'] ?? ''),
            'currency' => (string) ($capture['amount']['currency_code'] ?? ''),
            'custom_id' => (string) ($capture['custom_id'] ?? ''),
        ];
    }

    public static function isApproveUrl(string $url): bool
    {
        $parts = parse_url($url);
        return ($parts['scheme'] ?? null) === 'https' && in_array($parts['host'] ?? null, self::APPROVE_HOSTS, true)
            && !isset($parts['user']) && !isset($parts['port']);
    }

    /** @throws PayPalException */
    private static function call(string $method, string $path, string $requestId, ?array $body): array
    {
        try {
            $config = self::config();
            $client = new Client(['base_uri' => $config['base'], 'timeout' => 15, 'connect_timeout' => 5,
                'allow_redirects' => false, 'http_errors' => false, 'verify' => true]);
            $options = ['headers' => [
                'Authorization' => 'Bearer ' . self::token($client, $config),
                'PayPal-Request-Id' => $requestId,
                'Prefer' => 'return=representation',
                'Accept' => 'application/json',
            ]];
            $options[$body === null ? 'body' : 'json'] = $body ?? '';
            if ($body === null) $options['headers']['Content-Type'] = 'application/json';
            $response = $client->request($method, $path, $options);
            return self::decode($response, [200, 201]);
        } catch (PayPalException $error) {
            throw $error;
        } catch (\Throwable $error) {
            // classe soltanto: i messaggi possono contenere URL o intestazioni
            throw new PayPalException('PayPal unavailable (' . get_class($error) . ').');
        }
    }

    private static function token(Client $client, array $config): string
    {
        $cacheKey = 'paypal-token-' . hash('sha256', $config['base'] . '|' . $config['id']);
        $token = Yii::$app->cache->get($cacheKey);
        if (is_string($token) && $token !== '') return $token;
        $response = $client->request('POST', '/v1/oauth2/token', [
            'auth' => [$config['id'], $config['secret']],
            'form_params' => ['grant_type' => 'client_credentials'],
            'headers' => ['Accept' => 'application/json'],
        ]);
        $data = self::decode($response, [200]);
        $token = $data['access_token'] ?? null;
        if (!is_string($token) || $token === '') throw new PayPalException('PayPal authentication failed.');
        $ttl = max(60, min(32400, (int) ($data['expires_in'] ?? 0) - 120));
        Yii::$app->cache->set($cacheKey, $token, $ttl);
        return $token;
    }

    private static function decode($response, array $statuses): array
    {
        $raw = (string) $response->getBody()->read(self::MAX_RESPONSE + 1);
        if (strlen($raw) > self::MAX_RESPONSE) throw new PayPalException('PayPal response too large.');
        if (!in_array($response->getStatusCode(), $statuses, true)) throw new PayPalException('PayPal HTTP ' . $response->getStatusCode() . '.');
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new PayPalException('PayPal response not JSON.');
        return $data;
    }
}
