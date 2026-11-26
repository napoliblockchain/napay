<?php

namespace app\controllers;

use Yii;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

use app\models\LegalDocuments;

/**
 * SIP-040: pagine pubbliche (anche anonime) di informativa privacy, condizioni di utilizzo e cookie policy.
 * Solo versioni pubblicate: la versione in vigore o una storica con ?version=N; mai le bozze.
 */
class LegalController extends Controller
{
    public $layout = 'auth';

    public function behaviors()
    {
        return ['verbs' => ['class' => VerbFilter::class, 'actions' => ['view' => ['GET']]]];
    }

    public function actionView($type, $version = null)
    {
        if (!LegalDocuments::validType($type)) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        if ($version !== null && (!is_string($version) || !preg_match('/^[1-9][0-9]{0,5}$/D', $version))) {
            throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        }
        $document = $version === null ? LegalDocuments::current($type) : LegalDocuments::published($type, (int) $version);
        $current = LegalDocuments::current($type);
        if ($document === null) {
            // mai pubblicato (o versione inesistente): nessun testo non approvato mostrato come valido
            Yii::$app->response->statusCode = 404;
            return $this->render('missing', ['type' => $type]);
        }
        return $this->render('view', ['document' => $document, 'isCurrent' => $current !== null && (int) $current->id === (int) $document->id, 'current' => $current]);
    }
}
