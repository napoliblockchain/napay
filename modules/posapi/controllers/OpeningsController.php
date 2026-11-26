<?php
namespace app\modules\posapi\controllers;

use Yii;
use app\components\Log;
use app\components\pos\OpeningException;
use app\components\pos\PosApi;
use app\components\pos\TerminalOpenings;
use app\models\PosOperatorSessions;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\web\Response;

/**
 * SIP-021 fase 2 (APERTURE-CONTRATTO): aperture esclusive dei terminali. Stessa autenticazione del catalogo
 * (chiave client + bearer di sessione); errori a codici fissi, mai titolare, token o dettagli interni.
 */
class OpeningsController extends \yii\rest\Controller
{
    private const MAX_BODY = 1024;
    private const MESSAGES = [
        'unauthenticated' => 'Client authentication required.', 'invalid_token' => 'Operator authentication required.',
        'validation_failed' => 'Invalid request.', 'not_found' => 'Not found.', 'terminal_in_use' => 'Terminal already in use.',
        'idempotency_conflict' => 'Idempotency key reused with a different request.', 'opening_invalid' => 'Opening no longer valid.',
        'rate_limited' => 'Too many attempts. Retry later.', 'backend_unavailable' => 'Service unavailable.',
    ];

    public function behaviors()
    {
        return [
            'contentNegotiator' => ['class' => ContentNegotiator::class, 'formats' => ['application/json' => Response::FORMAT_JSON]],
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['create' => ['POST'], 'view' => ['GET'], 'heartbeat' => ['POST'], 'release' => ['POST']]],
        ];
    }

    public function actionCreate(string $terminal_id)
    {
        return $this->guard(function (PosOperatorSessions $session, string $token) use ($terminal_id) {
            $key = Yii::$app->request->headers->get('Idempotency-Key');
            $body = $this->body(['instance_id']);
            if (!is_string($key) || preg_match(TerminalOpenings::UUID, $key) !== 1 || $body === null) return $this->error(400, 'validation_failed');
            if (preg_match(TerminalOpenings::UUID, $terminal_id) !== 1) return $this->error(404, 'not_found');
            if (($wait = PosApi::throttle('openings|session|' . hash('sha256', $token), [30, 60])) > 0) return $this->limited($wait);
            [$status, $opening] = TerminalOpenings::open($session, $terminal_id, $body['instance_id'], $key);
            if ($status === 201) Log::save('posapi', 'openings', "Apertura POS {$opening['id']} del terminale {$terminal_id} (sessione #{$session->id}, generazione {$opening['generation']})");
            Yii::$app->response->statusCode = $status;
            return $opening;
        });
    }

    public function actionView(string $opening_id)
    {
        return $this->guard(function (PosOperatorSessions $session, string $token) use ($opening_id) {
            if (Yii::$app->request->getRawBody() !== '') return $this->error(400, 'validation_failed');
            if (($wait = PosApi::throttle('openings|session|' . hash('sha256', $token), [60, 60])) > 0) return $this->limited($wait);
            return TerminalOpenings::view($session, $opening_id);
        });
    }

    public function actionHeartbeat(string $opening_id)
    {
        return $this->guard(function (PosOperatorSessions $session) use ($opening_id) {
            $body = $this->body(['instance_id', 'generation']);
            if ($body === null) return $this->error(400, 'validation_failed');
            if (($wait = PosApi::throttle('openings|heartbeat|' . hash('sha256', $opening_id), [6, 60])) > 0) return $this->limited($wait);
            TerminalOpenings::heartbeat($session, $opening_id, $body['instance_id'], $body['generation']);
            return $this->noContent();
        });
    }

    public function actionRelease(string $opening_id)
    {
        return $this->guard(function (PosOperatorSessions $session, string $token) use ($opening_id) {
            $body = $this->body(['instance_id', 'generation']);
            if ($body === null) return $this->error(400, 'validation_failed');
            if (($wait = PosApi::throttle('openings|session|' . hash('sha256', $token), [60, 60])) > 0) return $this->limited($wait);
            TerminalOpenings::release($session, $opening_id, $body['instance_id'], $body['generation']);
            Log::save('posapi', 'openings', "Apertura POS {$opening_id} chiusa dal titolare (sessione #{$session->id})");
            return $this->noContent();
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
            if (($wait = PosApi::throttle('openings|client|' . $client, [240, 60])) > 0) return $this->limited($wait);
            $session = PosApi::findActiveSession($match[1], $client);
            if ($session === null) return $this->error(401, 'invalid_token');
            return $operation($session, $match[1]);
        } catch (OpeningException $e) {
            return $this->error($e->status(), $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->error(400, 'validation_failed');
        } catch (\Throwable $e) {
            Yii::error('POS opening failure: ' . get_class($e), __METHOD__);
            return $this->error(503, 'backend_unavailable');
        }
    }

    /** Oggetto JSON con esattamente i campi richiesti: instance_id UUID v4, generation intero positivo come stringa. */
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
            if (!is_string($value)) return null;
            if ($name === 'instance_id' && preg_match(TerminalOpenings::UUID, $value) !== 1) return null;
            if ($name === 'generation' && preg_match('/^[1-9][0-9]{0,18}$/D', $value) !== 1) return null;
        }
        return $data;
    }

    private function noContent()
    {
        Yii::$app->response->statusCode = 204;
        Yii::$app->response->format = Response::FORMAT_RAW;
        return null;
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
