<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\filters\VerbFilter;

/**
 * SIP-033: logo pubblico del merchant, aperto dal browser del cliente sulla pagina di pagamento BTCPay (altra origine).
 * Indirizzo = SHA-256 del PNG ricodificato da Napay: contenuto immutabile, nessun dato del merchant nell'indirizzo.
 */
class MerchantLogoController extends Controller
{
    public function behaviors()
    {
        return ['verbs' => ['class' => VerbFilter::class, 'actions' => ['view' => ['GET', 'HEAD']]]];
    }

    public function actionView($id)
    {
        if (!is_string($id) || preg_match('/^[0-9a-f]{64}$/D', $id) !== 1) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        $content = Yii::$app->db->createCommand('SELECT content FROM {{%merchant_logos}} WHERE sha256=:s LIMIT 1', [':s' => $id])->queryScalar();
        if (!is_string($content) || !hash_equals($id, hash('sha256', $content))) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'image/png');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        // immagine incorporata dalla pagina BTCPay: altra origine ammessa, contenuto immutabile
        $response->headers->set('Cross-Origin-Resource-Policy', 'cross-origin');
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        $response->content = $content;
        return $response;
    }
}
