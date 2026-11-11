<?php

namespace app\modules\posapi\controllers;

use Yii;
use app\components\Log;
use app\components\pos\PosApi;
use app\models\PosOperatorSessions;

/** SIP-021: idempotent, client-scoped revocation; never disclose token existence. */
class DeleteSessionAction extends \yii\base\Action
{
    public function run()
    {
        try {
            $request = Yii::$app->request;
            $client = PosApi::authenticateClient($request->headers->get('X-POS-Client-Key'));
            if ($client === null) return $this->error(401, 'unauthenticated', 'Client authentication required.');
            $authorization = $request->headers->get('Authorization');
            if (!is_string($authorization) || !preg_match('/^Bearer (napos_[A-Za-z0-9_-]{43})$/D', $authorization, $match)) {
                return $this->error(401, 'invalid_token', 'Operator authentication required.');
            }
            if ($request->getRawBody() !== '') return $this->error(400, 'validation_failed', 'Invalid request.');
            $wait = PosApi::throttle('logout|client|' . $client, PosApi::LIMIT_CLIENT);
            if ($wait > 0) {
                Yii::$app->response->headers->set('Retry-After', (string) $wait);
                return $this->error(429, 'rate_limited', 'Too many attempts. Retry later.');
            }
            $changed = PosOperatorSessions::updateAll(['revoked_at' => time(), 'revoked_reason' => 'logout'], [
                'token_sha256' => hash('sha256', $match[1]), 'client_key_prefix' => $client, 'revoked_at' => null,
            ]);
            if ($changed > 0) Log::save('posapi', 'sessions', "Sessione POS revocata per logout (client $client)");
            Yii::$app->response->statusCode = 204;
            Yii::$app->response->format = \yii\web\Response::FORMAT_RAW;
            return null;
        } catch (\Throwable $error) {
            Yii::error('POS logout failure: ' . get_class($error), __METHOD__);
            return $this->error(503, 'backend_unavailable', 'Service unavailable.');
        }
    }

    private function error(int $status, string $code, string $message): array
    {
        Yii::$app->response->statusCode = $status;
        return ['error' => ['code' => $code, 'message' => $message]];
    }
}
