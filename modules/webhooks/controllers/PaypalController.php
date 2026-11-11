<?php

namespace app\modules\webhooks\controllers;

use Yii;
use app\components\Log;
use app\components\Throttle;
use app\components\association\PayPalPayments;
use app\components\payments\PayPal;
use app\components\payments\PayPalException;
use app\models\MembershipPayments;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\rest\Controller;
use yii\web\Response;

/**
 * POST /api/paypal/v1/webhook: webhook PayPal (equivalente REST del vecchio IPN).
 * Firma verificata presso PayPal, evento elaborato una sola volta, stato riletto da PayPal (mai dal payload).
 * 200 = elaborato o da ignorare (PayPal non riprova); 500 = errore temporaneo (PayPal riprova).
 */
class PaypalController extends Controller
{
    private const MAX_BODY = 65536;
    private const LIMIT_IP = [120, 60];
    private const HEADERS = [
        'auth_algo' => 'PAYPAL-AUTH-ALGO', 'cert_url' => 'PAYPAL-CERT-URL', 'transmission_id' => 'PAYPAL-TRANSMISSION-ID',
        'transmission_sig' => 'PAYPAL-TRANSMISSION-SIG', 'transmission_time' => 'PAYPAL-TRANSMISSION-TIME',
    ];

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
        } catch (PayPalException $error) {
            Yii::error('PayPal webhook temporary failure: ' . $error->getMessage(), __METHOD__);
            return $this->reply(500, 'retry');
        } catch (\Throwable $error) {
            Yii::error('PayPal webhook failure: ' . get_class($error), __METHOD__);
            return $this->reply(500, 'retry');
        }
    }

    private function receive()
    {
        $request = Yii::$app->request;
        if (PayPal::webhookId() === null || !PayPal::enabled()) return $this->reply(503, 'disabled');
        if (($wait = Throttle::hit('paypal-webhook|' . $request->getUserIP(), self::LIMIT_IP)) > 0) {
            Yii::$app->response->headers->set('Retry-After', (string) $wait);
            return $this->reply(429, 'rate_limited');
        }

        // formato prima della verifica: niente chiamate a PayPal per richieste palesemente non valide
        $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
        $raw = $request->getRawBody();
        $transmission = [];
        foreach (self::HEADERS as $field => $header) {
            $value = $request->headers->get($header);
            if (!is_string($value) || $value === '' || strlen($value) > 2048) return $this->reply(400, 'invalid');
            $transmission[$field] = $value;
        }
        $certHost = parse_url($transmission['cert_url'], PHP_URL_HOST);
        if ($type !== 'application/json' || $raw === '' || strlen($raw) > self::MAX_BODY
            || parse_url($transmission['cert_url'], PHP_URL_SCHEME) !== 'https' || !in_array($certHost, ['api.paypal.com', 'api.sandbox.paypal.com', 'api-m.paypal.com', 'api-m.sandbox.paypal.com'], true)) {
            return $this->reply(400, 'invalid');
        }
        try {
            $event = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            return $this->reply(400, 'invalid');
        }
        $eventId = $event['id'] ?? null;
        $eventType = $event['event_type'] ?? null;
        if (!is_string($eventId) || !preg_match('/^WH-[A-Z0-9-]{8,60}$/D', $eventId) || !is_string($eventType) || !preg_match('/^[A-Z._]{3,64}$/D', $eventType)
            || !is_array($event['resource'] ?? null)) {
            return $this->reply(400, 'invalid');
        }

        if (!PayPal::verifyWebhook($transmission, $raw)) {
            Log::security("Webhook PayPal con firma non valida ($eventType)");
            return $this->reply(401, 'invalid_signature');
        }

        $db = Yii::$app->db;
        $known = $db->createCommand('SELECT processed_at FROM paypal_webhook_events WHERE event_id=:e', [':e' => $eventId])->queryOne();
        if ($known && $known['processed_at'] !== null) return $this->reply(200, 'duplicate');
        $resourceId = is_string($event['resource']['id'] ?? null) ? mb_substr($event['resource']['id'], 0, 64) : null;
        if (!$known) {
            $db->createCommand()->insert('paypal_webhook_events', ['event_id' => $eventId, 'event_type' => $eventType, 'resource_id' => $resourceId, 'received_at' => time()])->execute();
        }

        $result = $this->handle($eventType, $event['resource']);
        $db->createCommand()->update('paypal_webhook_events', ['processed_at' => time(), 'result' => $result], ['event_id' => $eventId])->execute();
        Log::event("Webhook PayPal $eventType: $result");
        return $this->reply(200, $result);
    }

    /** @return string esito registrato; PayPalException = PayPal non raggiungibile, si riprova */
    private function handle(string $type, array $resource): string
    {
        switch ($type) {
            case 'CHECKOUT.ORDER.APPROVED':
                $payment = $this->paymentByOrder($resource['id'] ?? null);
                if ($payment === null) return 'not_found';
                if ($payment->status !== 'pending') return 'already';
                // il socio ha approvato: si incassa anche se non torna sul sito (capture idempotente)
                return PayPalPayments::captureAndComplete($payment);

            case 'PAYMENT.CAPTURE.COMPLETED':
            case 'PAYMENT.CAPTURE.DENIED':
                $payment = $this->paymentByOrder($resource['supplementary_data']['related_ids']['order_id'] ?? null);
                if ($payment === null) return 'not_found';
                if ($payment->status !== 'pending') return 'already';
                // stato reale riletto da PayPal, non dal contenuto dell'evento
                $capture = PayPal::getOrder((string) $payment->paypal_order_id)['purchase_units'][0]['payments']['captures'][0] ?? null;
                if (!is_array($capture)) return 'not_captured';
                if (($capture['status'] ?? null) === 'DENIED') {
                    MembershipPayments::updateAll(['status' => 'invalid'], ['id' => $payment->id, 'status' => 'pending']);
                    return 'denied';
                }
                if (($capture['status'] ?? null) !== 'COMPLETED') return 'not_completed';
                return PayPalPayments::complete($payment, ['id' => (string) ($capture['id'] ?? ''), 'amount' => (string) ($capture['amount']['value'] ?? ''),
                    'currency' => (string) ($capture['amount']['currency_code'] ?? ''), 'custom_id' => (string) ($capture['custom_id'] ?? '')]);

            case 'PAYMENT.CAPTURE.REFUNDED':
            case 'PAYMENT.CAPTURE.REVERSED':
                $captureId = $type === 'PAYMENT.CAPTURE.REVERSED' ? ($resource['id'] ?? null) : $this->upCaptureId($resource);
                if (!is_string($captureId) || !preg_match('/^[A-Z0-9]{8,60}$/D', $captureId)) return 'not_found';
                $payment = MembershipPayments::findOne(['paypal_txn_id' => $captureId]);
                if ($payment === null) return 'not_found';
                $status = PayPal::getCapture($captureId)['status'] ?? null;
                $new = ['REFUNDED' => 'refunded', 'REVERSED' => 'reversed'][$status] ?? null;
                if ($new === null) return 'partial_or_unchanged';
                if ($payment->status === $new) return 'already';
                MembershipPayments::updateAll(['status' => $new], ['id' => $payment->id]);
                Log::security("Pagamento quota #{$payment->id} $new da PayPal");
                return $new;
        }
        return 'ignored';
    }

    private function paymentByOrder($orderId): ?MembershipPayments
    {
        if (!is_string($orderId) || !preg_match('/^[A-Z0-9]{8,36}$/D', $orderId)) return null;
        return MembershipPayments::findOne(['paypal_order_id' => $orderId]);
    }

    /** Rimborso: la capture originale è nel link "up" del rimborso */
    private function upCaptureId(array $refund): ?string
    {
        foreach ($refund['links'] ?? [] as $link) {
            if (($link['rel'] ?? null) === 'up' && preg_match('#/v2/payments/captures/([A-Z0-9]{8,60})$#', (string) ($link['href'] ?? ''), $m)) return $m[1];
        }
        return null;
    }

    private function reply(int $status, string $result): array
    {
        Yii::$app->response->statusCode = $status;
        return ['result' => $result];
    }
}
