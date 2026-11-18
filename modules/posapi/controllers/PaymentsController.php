<?php
namespace app\modules\posapi\controllers;

use Yii;
use app\components\Log;
use app\components\pos\OpeningException;
use app\components\pos\PosApi;
use app\components\pos\TerminalOpenings;
use app\components\pos\btc\BtcPayments;
use app\components\pos\tts\PaymentException;
use app\components\pos\PosHistory;
use app\components\pos\tts\TtsPayments;
use app\models\PosOperatorSessions;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\web\Response;

/**
 * SIP-021 fase 3 (PAGAMENTO-TTS-CONTRATTO): richieste di pagamento TTS dall'apertura del terminale.
 * Stessa autenticazione e fencing delle aperture; errori a codici fissi, mai merchant, sessione o dettagli di chain.
 * SIP-033: pagamenti BTC (fattura dell'app POS di BTCPay del terminale) e metodi di incasso disponibili per l'apertura.
 */
class PaymentsController extends \yii\rest\Controller
{
    private const MAX_BODY = 1024;
    private const MESSAGES = [
        'unauthenticated' => 'Client authentication required.', 'invalid_token' => 'Operator authentication required.',
        'validation_failed' => 'Invalid request.', 'not_found' => 'Not found.', 'opening_invalid' => 'Opening no longer valid.',
        'idempotency_conflict' => 'Idempotency key reused with a different request.', 'payment_not_pending' => 'Payment is no longer pending.',
        'payment_unavailable' => 'Payments unavailable for this terminal.', 'rate_limited' => 'Too many attempts. Retry later.',
        'backend_unavailable' => 'Service unavailable.',
    ];

    public function behaviors()
    {
        return [
            'contentNegotiator' => ['class' => ContentNegotiator::class, 'formats' => ['application/json' => Response::FORMAT_JSON]],
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['index' => ['GET'], 'create' => ['POST'], 'view' => ['GET'], 'cancel' => ['POST'],
                'methods' => ['GET'], 'btc-create' => ['POST'], 'btc-view' => ['GET']]],
        ];
    }

    public function actionCreate(string $opening_id)
    {
        return $this->guard(function (PosOperatorSessions $session, string $token) use ($opening_id) {
            $key = Yii::$app->request->headers->get('Idempotency-Key');
            $body = $this->body(['instance_id', 'generation', 'amount_cents']);
            if (!is_string($key) || preg_match(TtsPayments::UUID, $key) !== 1 || $body === null) return $this->error(400, 'validation_failed');
            if (preg_match(TerminalOpenings::UUID, $opening_id) !== 1) return $this->error(404, 'not_found');
            if (($wait = PosApi::throttle('payments|session|' . hash('sha256', $token), [30, 60])) > 0) return $this->limited($wait);
            [$status, $payment] = TtsPayments::create($session, $opening_id, $body['instance_id'], $body['generation'], (int) $body['amount_cents'], $key);
            if ($status === 201) Log::save('posapi', 'payments', "Richiesta di pagamento TTS {$payment['id']} di {$payment['amount']} (apertura {$opening_id}, sessione #{$session->id})");
            Yii::$app->response->statusCode = $status;
            return $payment;
        });
    }

    public function actionIndex(string $opening_id)
    {
        return $this->guard(function (PosOperatorSessions $session, string $token) use ($opening_id) {
            if (Yii::$app->request->getRawBody() !== '' || Yii::$app->request->getQueryParams() !== ['opening_id' => $opening_id]) return $this->error(400, 'validation_failed');
            if (preg_match(TerminalOpenings::UUID, $opening_id) !== 1) return $this->error(404, 'not_found');
            if (($wait = PosApi::throttle('payments|view|' . hash('sha256', $token), [60, 60])) > 0) return $this->limited($wait);
            // SIP-033 passo 5: TTS e BTC insieme, con i totali di oggi
            return PosHistory::forOpening($session, $opening_id);
        });
    }

    public function actionView(string $payment_id)
    {
        return $this->guard(function (PosOperatorSessions $session, string $token) use ($payment_id) {
            if (Yii::$app->request->getRawBody() !== '') return $this->error(400, 'validation_failed');
            if (($wait = PosApi::throttle('payments|view|' . hash('sha256', $token), [60, 60])) > 0) return $this->limited($wait);
            return TtsPayments::view($session, $payment_id);
        });
    }

    public function actionCancel(string $payment_id)
    {
        return $this->guard(function (PosOperatorSessions $session, string $token) use ($payment_id) {
            $body = $this->body(['instance_id', 'generation']);
            if ($body === null) return $this->error(400, 'validation_failed');
            if (($wait = PosApi::throttle('payments|session|' . hash('sha256', $token), [30, 60])) > 0) return $this->limited($wait);
            $payment = TtsPayments::cancel($session, $payment_id, $body['instance_id'], $body['generation']);
            Log::save('posapi', 'payments', "Richiesta di pagamento TTS {$payment_id} annullata (sessione #{$session->id})");
            return $payment;
        });
    }

    /** SIP-033: metodi di incasso disponibili per l'apertura (tasti TTS e BTC del POS). */
    public function actionMethods(string $opening_id)
    {
        return $this->guard(function (PosOperatorSessions $session, string $token) use ($opening_id) {
            if (Yii::$app->request->getRawBody() !== '' || Yii::$app->request->getQueryParams() !== ['opening_id' => $opening_id]) return $this->error(400, 'validation_failed');
            if (preg_match(TerminalOpenings::UUID, $opening_id) !== 1) return $this->error(404, 'not_found');
            if (($wait = PosApi::throttle('payments|view|' . hash('sha256', $token), [60, 60])) > 0) return $this->limited($wait);
            return BtcPayments::methods($session, $opening_id);
        });
    }

    /** SIP-033: pagamento BTC (una fattura BTCPay per richiesta; limite più stretto del TTS). */
    public function actionBtcCreate(string $opening_id)
    {
        return $this->guard(function (PosOperatorSessions $session, string $token) use ($opening_id) {
            $key = Yii::$app->request->headers->get('Idempotency-Key');
            $body = $this->body(['instance_id', 'generation', 'amount_cents']);
            if (!is_string($key) || preg_match(BtcPayments::UUID, $key) !== 1 || $body === null) return $this->error(400, 'validation_failed');
            if (preg_match(TerminalOpenings::UUID, $opening_id) !== 1) return $this->error(404, 'not_found');
            if (($wait = PosApi::throttle('payments|btc|' . hash('sha256', $token), [10, 60])) > 0) return $this->limited($wait);
            [$status, $payment] = BtcPayments::create($session, $opening_id, $body['instance_id'], $body['generation'], (int) $body['amount_cents'], $key);
            Yii::$app->response->statusCode = $status;
            return $payment;
        });
    }

    public function actionBtcView(string $payment_id)
    {
        return $this->guard(function (PosOperatorSessions $session, string $token) use ($payment_id) {
            if (Yii::$app->request->getRawBody() !== '') return $this->error(400, 'validation_failed');
            if (($wait = PosApi::throttle('payments|view|' . hash('sha256', $token), [60, 60])) > 0) return $this->limited($wait);
            return BtcPayments::view($session, $payment_id);
        });
    }

    /** Chiave client, bearer e sessione valida; poi l'operazione. Errori interni = 503 senza messaggio. */
    private function guard(callable $operation)
    {
        try {
            $request = Yii::$app->request;
            $client = PosApi::authenticateClient($request->headers->get('X-POS-Client-Key'));
            if ($client === null) return $this->error(401, 'unauthenticated');
            $bearer = $request->headers->get('Authorization');
            if (!is_string($bearer) || !preg_match('/^Bearer (napos_[A-Za-z0-9_-]{43})$/D', $bearer, $match)) return $this->error(401, 'invalid_token');
            if (($wait = PosApi::throttle('payments|client|' . $client, [240, 60])) > 0) return $this->limited($wait);
            $session = PosApi::findActiveSession($match[1], $client);
            if ($session === null) return $this->error(401, 'invalid_token');
            return $operation($session, $match[1]);
        } catch (PaymentException $e) {
            return $this->error($e->status(), $e->getMessage());
        } catch (OpeningException $e) {
            // apertura altrui o inesistente = 404; propria non più valida = 409
            return $this->error($e->status(), $e->getMessage() === OpeningException::NOT_FOUND ? 'not_found' : 'opening_invalid');
        } catch (\InvalidArgumentException $e) {
            return $this->error(400, 'validation_failed');
        } catch (\Throwable $e) {
            Yii::error('POS payment failure: ' . get_class($e), __METHOD__);
            return $this->error(503, 'backend_unavailable');
        }
    }

    /**
     * Oggetto JSON con esattamente i campi richiesti: instance_id UUID v4, generation intero positivo come stringa,
     * amount_cents intero JSON tra 1 e il massimo.
     */
    private function body(array $fields): ?array
    {
        $request = Yii::$app->request;
        $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
        $raw = $request->getRawBody();
        if ($type !== 'application/json' || $raw === '' || strlen($raw) > self::MAX_BODY) return null;
        try { $data = json_decode($raw, true, 2, JSON_THROW_ON_ERROR); } catch (\JsonException $e) { return null; }
        if (!is_array($data) || array_is_list($data)) return null;
        $keys = array_keys($data); sort($keys); $wanted = $fields; sort($wanted);
        if ($keys !== $wanted) return null;
        foreach ($data as $name => $value) {
            if ($name === 'amount_cents') {
                if (!is_int($value) || $value < 1 || $value > TtsPayments::MAX_CENTS) return null;
                continue;
            }
            if (!is_string($value)) return null;
            if ($name === 'instance_id' && preg_match(TerminalOpenings::UUID, $value) !== 1) return null;
            if ($name === 'generation' && preg_match('/^[1-9][0-9]{0,18}$/D', $value) !== 1) return null;
        }
        return $data;
    }

    private function limited(int $wait): array
    {
        Yii::$app->response->headers->set('Retry-After', (string) $wait);
        return $this->error(429, 'rate_limited');
    }

    private function error(int $status, string $code): array
    {
        Yii::$app->response->statusCode = $status;
        return ['error' => ['code' => $code, 'message' => self::MESSAGES[$code]]];
    }
}
