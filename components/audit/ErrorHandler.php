<?php

namespace app\components\audit;

use app\components\Log;
use Yii;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;

/**
 * Gestore errori che registra nel registro di sicurezza CSRF non valido e 403 (come in Aura).
 */
class ErrorHandler extends \yii\web\ErrorHandler
{
    public function logException($exception)
    {
        parent::logException($exception);

        try {
            // solo il percorso: la query può contenere token (reset password, attivazione)
            $path = '/' . Yii::$app->request->getPathInfo();
            if ($exception instanceof BadRequestHttpException
                && $exception->getMessage() === Yii::t('yii', 'Unable to verify your data submission.')) {
                Log::security('Richiesta rifiutata: token CSRF non valido o mancante su ' . $path);
            } elseif ($exception instanceof ForbiddenHttpException && !AuditedAccessControl::$deniedLogged) {
                Log::security('Accesso vietato (403) a ' . $path);
            }
        } catch (\Throwable $e) {
            // il registro non deve interferire con la gestione dell'errore
        }
    }
}
