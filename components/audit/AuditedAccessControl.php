<?php

namespace app\components\audit;

use app\components\Log;
use Yii;
use yii\filters\AccessControl;
use yii\web\ForbiddenHttpException;

/**
 * AccessControl che registra gli accessi negati (pagine o action non consentite al profilo:
 * anche i tentativi di accesso "verticale" a funzioni riservate), come in Aura.
 * Sostituisce yii\filters\AccessControl in tutti i controller tramite il container (config/web.php).
 */
class AuditedAccessControl extends AccessControl
{
    /** Evita che lo stesso 403 venga registrato anche dal gestore errori. */
    public static bool $deniedLogged = false;

    public function beforeAction($action)
    {
        try {
            $allowed = parent::beforeAction($action);
        } catch (ForbiddenHttpException $e) {
            self::logDenied($action->getUniqueId());
            throw $e;
        }

        if (!$allowed) {
            self::logDenied($action->getUniqueId());
        }

        return $allowed;
    }

    private static function logDenied(string $route): void
    {
        self::$deniedLogged = true;
        $who = Yii::$app->user->isGuest ? 'utente non autenticato' : 'utente senza permesso';
        Log::security(sprintf('Accesso negato a %s (%s)', $route, $who));
    }
}
