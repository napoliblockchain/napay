<?php

namespace app\modules\posapi;

use Yii;
use yii\web\NotFoundHttpException;

/**
 * API server-to-server per i server POS (SIP-021), raggiungibile solo da /api/pos/v1/*.
 * Nessuna sessione PHP o cookie: niente CSRF browser da gestire, nessun header CORS.
 */
class Module extends \yii\base\Module
{
    public $controllerNamespace = 'app\modules\posapi\controllers';

    public function init()
    {
        parent::init();
        // identità solo per richiesta: mai cookie di sessione/identità Napay
        Yii::$app->user->enableSession = false;
        Yii::$app->user->enableAutoLogin = false;
        Yii::$app->user->loginUrl = null;
    }

    public function beforeAction($action)
    {
        // solo dalle rotte pubbliche dell'API, non dal percorso di default del modulo
        if (!str_starts_with(Yii::$app->request->getPathInfo(), 'api/pos/v1/')) {
            throw new NotFoundHttpException();
        }
        $headers = Yii::$app->response->headers;
        $headers->set('Cache-Control', 'no-store');
        $headers->set('Pragma', 'no-cache');
        $headers->set('X-Content-Type-Options', 'nosniff');
        return parent::beforeAction($action);
    }
}
