<?php

namespace app\controllers;

use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;

use app\components\Log;
use app\components\User;
use app\components\mail\Outbox;

/**
 * Posta in uscita salvata su file (SMTP non disponibile): solo amministratori, sola lettura.
 * I link delle email (attivazione, inviti, recupero password) si copiano e si girano al destinatario.
 */
class MailOutboxController extends Controller
{
    public function behaviors()
    {
        return [
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['index' => ['GET']]],
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => ['@'], 'matchCallback' => static fn() => User::isAdministrator()]],
            ],
        ];
    }

    public function actionIndex()
    {
        $lists = [];
        foreach (Outbox::sources() as $name => $source) {
            $lists[$name] = Outbox::messages($source);
        }
        // i link valgono come credenziali temporanee: niente cache e consultazione registrata
        Yii::$app->response->headers->set('Cache-Control', 'no-store');
        Log::event(sprintf('Mail outbox viewed: %s', $lists === [] ? 'no file mail' : implode(', ', array_keys($lists))), Log::TYPE_SECURITY);
        return $this->render('index', ['lists' => $lists]);
    }
}
