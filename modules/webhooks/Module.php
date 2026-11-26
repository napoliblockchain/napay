<?php

namespace app\modules\webhooks;

use Yii;
use yii\web\NotFoundHttpException;

/**
 * Notifiche server-to-server dei fornitori di pagamento (TIP-020 5b-bis), raggiungibili solo da /api/<fornitore>/v1/*.
 * Autenticate dalla firma del fornitore: nessuna sessione o cookie, quindi niente CSRF browser.
 */
class Module extends \yii\base\Module
{
    public $controllerNamespace = 'app\modules\webhooks\controllers';

    public function init()
    {
        parent::init();
        Yii::$app->user->enableSession = false;
        Yii::$app->user->enableAutoLogin = false;
        Yii::$app->user->loginUrl = null;
    }

    public function beforeAction($action)
    {
        $path = Yii::$app->request->getPathInfo();
        if (!str_starts_with($path, 'api/paypal/v1/') && !str_starts_with($path, 'api/btcpay/v1/')) {
            throw new NotFoundHttpException();
        }
        $headers = Yii::$app->response->headers;
        $headers->set('Cache-Control', 'no-store');
        $headers->set('X-Content-Type-Options', 'nosniff');
        return parent::beforeAction($action);
    }
}
