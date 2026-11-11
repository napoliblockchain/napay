<?php

namespace app\modules\webhooks\controllers;

use Yii;
use app\components\Log;
use app\components\Throttle;
use app\components\association\BtcpayPayments;
use app\components\payments\BTCPay;
use app\components\payments\PaymentGatewayException;
use app\models\MembershipPayments;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\rest\Controller;
use yii\web\Response;

/**
 * POST /api/btcpay/v1/webhook: webhook BTCPay Greenfield per i pagamenti quota (TIP-020 passo 5c).
 * Firma BTCPay-Sig (HMAC-SHA256 con BTCPAY_WEBHOOK_SECRET), consegna elaborata una sola volta,
 * stato riletto dalla fattura su BTCPay. 200 = elaborato o da ignorare; 500 = BTCPay riprova.
 */
class BtcpayController extends Controller
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
        } catch (PaymentGatewayException $error) {
            Yii::error('BTCPay webhook temporary failure: ' . $error->getMessage(), __METHOD__);
            return $this->reply(500, 'retry');
        } catch (\Throwable $error) {
            Yii::error('BTCPay webhook failure: ' . get_class($error), __METHOD__);
            return $this->reply(500, 'retry');
        }
    }

    private function receive()
    {
        $request = Yii::$app->request;
        if (!BTCPay::enabled()) return $this->reply(503, 'disabled');
        if (($wait = Throttle::hit('btcpay-webhook|' . $request->getUserIP(), self::LIMIT_IP)) > 0) {
            Yii::$app->response->headers->set('Retry-After', (string) $wait);
            return $this->reply(429, 'rate_limited');
        }
        $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
        $raw = $request->getRawBody();
        if ($type !== 'application/json' || $raw === '' || strlen($raw) > self::MAX_BODY) return $this->reply(400, 'invalid');

        if (!BTCPay::isWebhookValid($raw, $request->headers->get('BTCPay-Sig'))) {
            Log::security('Webhook BTCPay con firma non valida');
            return $this->reply(401, 'invalid_signature');
        }
        try {
            $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            return $this->reply(400, 'invalid');
        }
        $delivery = $event['originalDeliveryId'] ?? $event['deliveryId'] ?? null;
        $eventType = $event['type'] ?? null;
        if (!is_string($delivery) || !preg_match('/^[A-Za-z0-9]{8,64}$/D', $delivery) || !is_string($eventType) || !preg_match('/^[A-Za-z]{3,64}$/D', $eventType)) {
            return $this->reply(400, 'invalid');
        }
        // notifiche di altri store dello stesso server: non sono pagamenti quota
        if (($event['storeId'] ?? null) !== BTCPay::storeId()) return $this->reply(200, 'other_store');

        $db = Yii::$app->db;
        $known = $db->createCommand('SELECT processed_at FROM btcpay_webhook_events WHERE delivery_id=:d', [':d' => $delivery])->queryOne();
        if ($known && $known['processed_at'] !== null) return $this->reply(200, 'duplicate');
        $invoiceId = is_string($event['invoiceId'] ?? null) && preg_match('/^[A-Za-z0-9]{8,64}$/D', $event['invoiceId']) ? $event['invoiceId'] : null;
        if (!$known) {
            $db->createCommand()->insert('btcpay_webhook_events', ['delivery_id' => $delivery, 'event_type' => $eventType, 'invoice_id' => $invoiceId, 'received_at' => time()])->execute();
        }

        $result = 'ignored';
        if (str_starts_with($eventType, 'Invoice')) {
            $payment = $invoiceId === null ? null : MembershipPayments::findOne(['btcpay_invoice_id' => $invoiceId]);
            $result = $payment === null ? 'not_found' : BtcpayPayments::sync($payment);
        }
        $db->createCommand()->update('btcpay_webhook_events', ['processed_at' => time(), 'result' => $result], ['delivery_id' => $delivery])->execute();
        Log::event("Webhook BTCPay $eventType: $result");
        return $this->reply(200, $result);
    }

    private function reply(int $status, string $result): array
    {
        Yii::$app->response->statusCode = $status;
        return ['result' => $result];
    }
}
