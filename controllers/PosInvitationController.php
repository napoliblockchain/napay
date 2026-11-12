<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\filters\VerbFilter;
use yii\filters\AccessControl;

use app\components\pos\management\ManagementException;
use app\components\pos\management\PosInvitations;
use app\models\forms\pos\AcceptInvitationForm;

/**
 * C4 parte D: pagina pubblica del link d'invito. GET mostra il form senza consumare il token;
 * POST con CSRF crea l'account operatore dedicato. Un utente gia' connesso non viene mai collegato.
 */
class PosInvitationController extends Controller
{
    public $layout = 'login';

    public function behaviors()
    {
        return [
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['accept' => ['GET', 'POST']]],
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['actions' => ['accept'], 'allow' => true]],
            ],
        ];
    }

    public function actionAccept()
    {
        // il link contiene il token: niente cache, niente referrer verso altri siti
        $headers = Yii::$app->response->headers;
        $headers->set('Cache-Control', 'no-store');
        $headers->set('Referrer-Policy', 'no-referrer');
        if (!Yii::$app->user->isGuest) return $this->render('signed-in');

        $request = Yii::$app->request;
        $form = new AcceptInvitationForm();
        $error = null;
        if ($request->isPost) {
            $form->load($request->post());
            if ($form->validate()) {
                try {
                    PosInvitations::accept((string) $form->token, $form->first_name, $form->last_name, $form->password, $request->getUserIP());
                    return $this->render('done');
                } catch (ManagementException $e) {
                    $error = $e->getMessage() === ManagementException::RATE_LIMITED ? $e->userMessage() : null;
                }
            }
            $token = is_string($form->token) ? $form->token : '';
        } else {
            $token = $request->get('token');
            $token = is_string($token) ? $token : '';
        }
        $invitation = PosInvitations::preview($token);
        if ($invitation === null) return $this->render('invalid');
        $form->token = $token;
        $form->password = $form->repeat_password = null;
        return $this->render('accept', ['form' => $form, 'invitation' => $invitation, 'error' => $error]);
    }
}
