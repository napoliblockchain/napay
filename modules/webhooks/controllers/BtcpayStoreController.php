<?php

namespace app\modules\webhooks\controllers;

use Yii;
use app\components\Log;
use app\components\Throttle;
use app\components\btcpay\StoreWebhookSecret;
use app\components\btcpay\provisioning\ProvisioningOutbox;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\rest\Controller;
use yii\web\Response;

/**
 * POST /api/btcpay/v1/store-webhook: webhook degli store BTCPay dei negozi (SIP-022 passo 4), registrati dal worker.
 * Lo store si riconosce dal suo ID nel corpo; la firma BTCPay-Sig si verifica con il segreto derivato per quel negozio
 * (BTCPAY_STORE_WEBHOOK_KEY), quindi un evento firmato per uno store non vale per un altro. Ogni consegna è registrata
 * una sola volta con il negozio di provenienza; l'uso degli eventi (storico pagamenti, POS BTC) è un passo successivo.
 */
class BtcpayStoreController extends Controller
{
    private const MAX_BODY = 65536;
    private const LIMIT_IP = [120, 60];

    public function behaviors()
    {
        return [
            'contentNegotiator' => ['class' => ContentNegotiator::class, 'formats' => ['application/json' => Response::FORMAT_JSON]],
            'verbFilter' => ['class' => VerbFilter::class, 'actions' => ['receive' => ['POST']]],
        ];
    }

    public function actionReceive()
    {
        try {
            return $this->receive();
        } catch (\Throwable $error) {
            Yii::error('BTCPay store webhook failure: ' . get_class($error), __METHOD__);
            return $this->reply(500, 'retry');
        }
    }

    private function receive()
    {
        $request = Yii::$app->request;
        $master = (string) getenv('BTCPAY_STORE_WEBHOOK_KEY');
        $instance = ProvisioningOutbox::instance();
        if ($instance === null || !StoreWebhookSecret::valid($master)) return $this->reply(503, 'disabled');
        if (($wait = Throttle::hit('btcpay-store-webhook|' . $request->getUserIP(), self::LIMIT_IP)) > 0) {
            Yii::$app->response->headers->set('Retry-After', (string) $wait);
            return $this->reply(429, 'rate_limited');
        }
        $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
        $raw = $request->getRawBody();
        if ($type !== 'application/json' || $raw === '' || strlen($raw) > self::MAX_BODY) return $this->reply(400, 'invalid');
        try {
            $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            return $this->reply(400, 'invalid');
        }
        $remoteStore = is_array($event) ? ($event['storeId'] ?? null) : null;
        if (!is_string($remoteStore) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $remoteStore) !== 1) return $this->reply(400, 'invalid');
        $db = Yii::$app->db;
        $storeId = $db->createCommand('SELECT store_id FROM {{%btcpay_store_links}} WHERE instance=:i AND remote_store_id=:r', [':i' => $instance, ':r' => $remoteStore])->queryScalar();
        // store senza collegamento Napay (o firma non valida): nessun dettaglio su quale dei due
        if ($storeId === false || !StoreWebhookSecret::signatureValid($raw, $request->headers->get('BTCPay-Sig'), StoreWebhookSecret::derive($master, $instance, (int) $storeId))) {
            Log::security('Webhook BTCPay di negozio rifiutato (store sconosciuto o firma non valida)');
            return $this->reply(401, 'invalid_signature');
        }
        $delivery = $event['originalDeliveryId'] ?? $event['deliveryId'] ?? null;
        $eventType = $event['type'] ?? null;
        if (!is_string($delivery) || !preg_match('/^[A-Za-z0-9]{8,64}$/D', $delivery) || !is_string($eventType) || !preg_match('/^[A-Za-z]{3,64}$/D', $eventType)) {
            return $this->reply(400, 'invalid');
        }
        if ($db->createCommand('SELECT id FROM {{%btcpay_webhook_events}} WHERE delivery_id=:d', [':d' => $delivery])->queryScalar() !== false) return $this->reply(200, 'duplicate');
        $invoiceId = is_string($event['invoiceId'] ?? null) && preg_match('/^[A-Za-z0-9]{8,64}$/D', $event['invoiceId']) ? $event['invoiceId'] : null;
        $db->createCommand()->insert('{{%btcpay_webhook_events}}', ['delivery_id' => $delivery, 'event_type' => $eventType, 'invoice_id' => $invoiceId,
            'store_id' => (int) $storeId, 'received_at' => time(), 'processed_at' => time(), 'result' => 'recorded'])->execute();
        return $this->reply(200, 'recorded');
    }

    private function reply(int $status, string $result): array
    {
        Yii::$app->response->statusCode = $status;
        return ['result' => $result];
    }
}
