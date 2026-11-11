<?php

namespace app\modules\posapi\controllers;

use Yii;
use app\components\Log;
use app\components\pos\PosApi;
use app\models\forms\LoginForm;
use yii\base\InvalidConfigException;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\rest\Controller;
use yii\web\Response;

/**
 * POST /api/pos/v1/sessions: login operatore POS (SIP-021).
 * Chiamata solo dal server POS (X-POS-Client-Key); credenziali verificate con il LoginForm centrale.
 * Risposte JSON no-store; errori con messaggi fissi, mai credenziali, token o chiavi.
 */
class SessionsController extends Controller
{
    private const MAX_BODY = 4096;
    private const MESSAGES = [
        'unauthenticated' => 'Client authentication required.',
        'validation_failed' => 'Invalid request.',
        'invalid_credentials' => 'Invalid credentials.',
        'rate_limited' => 'Too many attempts. Retry later.',
        'backend_unavailable' => 'Service unavailable.',
    ];

    public function behaviors()
    {
        // niente authenticator/rateLimiter generici: autenticazione e limiti espliciti qui sotto
        return [
            'contentNegotiator' => ['class' => ContentNegotiator::class, 'formats' => ['application/json' => Response::FORMAT_JSON]],
            'verbFilter' => ['class' => VerbFilter::class, 'actions' => ['create' => ['POST', 'DELETE'], 'delete' => ['DELETE']]],
        ];
    }

    public function actionCreate()
    {
        // Existing public URL maps all verbs here; keep dispatch local to the API.
        if (Yii::$app->request->isDelete) return $this->runAction('delete');
        try {
            return $this->login();
        } catch (InvalidConfigException $error) {
            Yii::error('POS API not configured: ' . get_class($error), __METHOD__);
            return $this->error(503, 'backend_unavailable');
        } catch (\Throwable $error) {
            // mai il messaggio: potrebbe contenere SQL o dati
            Yii::error('POS API failure: ' . get_class($error), __METHOD__);
            return $this->error(503, 'backend_unavailable');
        }
    }

    public function actions()
    {
        return ['delete' => DeleteSessionAction::class];
    }

    private function login()
    {
        $request = Yii::$app->request;
        $client = PosApi::authenticateClient($request->headers->get('X-POS-Client-Key'));
        if ($client === null) {
            Log::security('Login POS rifiutato: client non autenticato');
            return $this->error(401, 'unauthenticated');
        }

        $operatorIp = $request->headers->get('X-POS-Operator-IP');
        if ($operatorIp !== null && filter_var($operatorIp, FILTER_VALIDATE_IP) === false) {
            return $this->error(400, 'validation_failed');
        }
        $ip = $operatorIp ?? (string) $request->getUserIP();
        foreach ([['client|' . $client, PosApi::LIMIT_CLIENT], ['ip|' . $ip, PosApi::LIMIT_IP]] as [$key, $limit]) {
            if (($wait = PosApi::throttle($key, $limit)) > 0) return $this->rateLimited($wait, 'client/IP');
        }

        $body = $this->jsonBody();
        if ($body === null) return $this->error(400, 'validation_failed');
        [$email, $password] = [$body['email'], $body['password']];

        $account = PosApi::accountKey($email);
        if (($wait = PosApi::throttle($account, PosApi::LIMIT_ACCOUNT_FAILURES, false)) > 0) return $this->rateLimited($wait, 'account ' . Log::quote($email));

        $form = new LoginForm(['email' => $email, 'password' => $password]);
        $user = $form->validate() ? $form->getUser() : null;
        if ($user === null) {
            PosApi::throttle($account, PosApi::LIMIT_ACCOUNT_FAILURES);
            Log::security('Login POS fallito per ' . Log::quote($email) . " (client $client)");
            return $this->error(401, 'invalid_credentials');
        }
        PosApi::resetThrottle($account);

        [$token, $session] = PosApi::createSession($user, $client, $ip);
        Log::save('posapi', 'sessions', "Sessione POS #{$session->id} creata per utente #{$user->id} (client $client)");
        Yii::$app->response->statusCode = 201;
        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $session->expires_at),
            'operator' => ['display_name' => trim($user->first_name . ' ' . $user->last_name)],
        ];
    }

    /** Oggetto JSON con esattamente email e password stringa, altrimenti null */
    private function jsonBody(): ?array
    {
        $request = Yii::$app->request;
        $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
        $raw = $request->getRawBody();
        if ($type !== 'application/json' || $raw === '' || strlen($raw) > self::MAX_BODY) return null;
        try {
            $data = json_decode($raw, true, 3, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $error) {
            return null;
        }
        if (!is_array($data) || array_is_list($data) || count($data) !== 2 || !isset($data['email'], $data['password'])
            || !is_string($data['email']) || !is_string($data['password'])
            || strlen($data['email']) > 254 || $data['password'] === '' || strlen($data['password']) > 1024) return null;
        return ['email' => trim($data['email']), 'password' => $data['password']];
    }

    private function rateLimited(int $wait, string $scope)
    {
        Log::security("Login POS limitato ($scope)");
        Yii::$app->response->headers->set('Retry-After', (string) $wait);
        return $this->error(429, 'rate_limited');
    }

    private function error(int $status, string $code): array
    {
        Yii::$app->response->statusCode = $status;
        return ['error' => ['code' => $code, 'message' => self::MESSAGES[$code]]];
    }
}
