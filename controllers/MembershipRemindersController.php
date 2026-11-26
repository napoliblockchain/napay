<?php

namespace app\controllers;

use Yii;
use yii\data\ArrayDataProvider;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;

use app\components\User;
use app\components\association\MembershipReminders;
use app\components\legacy\LegacyImporter;

/**
 * Promemoria della quota (SIP-030 punto 2): solo admin. Soci in scadenza entro 45 giorni o scaduti da meno di un anno,
 * con invii nel periodo e ultimo invio; invio manuale multiplo con pausa di 7 giorni. Sola lettura durante l'import legacy.
 */
class MembershipRemindersController extends Controller
{
    public function behaviors()
    {
        return [
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['index' => ['GET'], 'send' => ['POST']]],
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => ['@'], 'matchCallback' => static fn() => User::isAdministrator()]],
            ],
        ];
    }

    public function actionIndex()
    {
        return $this->render('index', [
            'dataProvider' => new ArrayDataProvider(['allModels' => MembershipReminders::rows(date('Y-m-d')), 'key' => 'id', 'pagination' => ['pageSize' => 50]]),
            'readOnly' => LegacyImporter::active(),
        ]);
    }

    public function actionSend()
    {
        if (LegacyImporter::active()) {
            throw new ForbiddenHttpException(Yii::t('app', 'Dati sincronizzati dal vecchio Napay: modifica disattivata.'));
        }
        $selection = Yii::$app->request->post('selection', []);
        $result = MembershipReminders::sendManual(is_array($selection) ? $selection : [], (int) Yii::$app->user->id, date('Y-m-d'), time());
        Yii::$app->session->setFlash($result['sent'] > 0 ? 'success' : 'warning', Yii::t('app', 'Reminders sent: {sent}. Skipped: {skipped} (reminders turned off or sent in the last {days} days).',
            ['sent' => $result['sent'], 'skipped' => $result['skipped'], 'days' => MembershipReminders::PAUSE_DAYS]));
        return $this->redirect(['index']);
    }
}
