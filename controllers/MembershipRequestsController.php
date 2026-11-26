<?php

namespace app\controllers;

use Yii;
use yii\data\ArrayDataProvider;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;

use app\components\User;
use app\components\association\MembershipRequests;
use app\components\legacy\LegacyImporter;

/**
 * Richieste di iscrizione (SIP-030 punto 1): solo admin. Approva o rifiuta più richieste insieme;
 * il rifiuto richiede una motivazione, inviata per mail. Sola lettura finché è attivo l'import dal vecchio Napay.
 */
class MembershipRequestsController extends Controller
{
    public function behaviors()
    {
        return [
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['index' => ['GET'], 'approve' => ['POST'], 'reject' => ['POST']]],
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => ['@'], 'matchCallback' => static fn() => User::isAdministrator()]],
            ],
        ];
    }

    public function actionIndex()
    {
        return $this->render('index', [
            'dataProvider' => new ArrayDataProvider(['allModels' => MembershipRequests::query()->all(), 'key' => 'id', 'pagination' => ['pageSize' => 50]]),
            'readOnly' => LegacyImporter::active(),
        ]);
    }

    public function actionApprove()
    {
        $this->writable();
        $this->requireDecision('approve');
        $count = MembershipRequests::approve($this->selection(), (int) Yii::$app->user->id);
        Yii::$app->session->setFlash($count > 0 ? 'success' : 'warning', Yii::t('app', 'Approved requests: {n}.', ['n' => $count]));
        return $this->redirect(['index']);
    }

    public function actionReject()
    {
        $this->writable();
        $this->requireDecision('reject');
        $reason = Yii::$app->request->post('reason', '');
        $reason = is_string($reason) ? trim($reason) : '';
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 1000) {
            Yii::$app->session->setFlash('danger', Yii::t('app', 'Write the reason for the rejection (3 to 1000 characters): it is sent by email.'));
            return $this->redirect(['index']);
        }
        $count = MembershipRequests::reject($this->selection(), $reason);
        Yii::$app->session->setFlash($count > 0 ? 'success' : 'warning', Yii::t('app', 'Rejected requests: {n}. The accounts have been deleted.', ['n' => $count]));
        return $this->redirect(['index']);
    }

    private function selection(): array
    {
        $selection = Yii::$app->request->post('selection', []);
        return is_array($selection) ? $selection : [];
    }

    private function requireDecision(string $decision): void
    {
        if (Yii::$app->request->post('decision') !== $decision) {
            throw new BadRequestHttpException(Yii::t('app', 'Invalid request.'));
        }
    }

    /** @throws ForbiddenHttpException finché i soci arrivano dall'import legacy */
    private function writable(): void
    {
        if (LegacyImporter::active()) {
            throw new ForbiddenHttpException(Yii::t('app', 'Dati sincronizzati dal vecchio Napay: modifica disattivata.'));
        }
    }
}
