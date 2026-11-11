<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\BadRequestHttpException;
use yii\web\Response;
use yii\filters\VerbFilter;
use yii\filters\AccessControl;
use yii\helpers\ArrayHelper;
use yii\helpers\Json;

use app\components\Crypt;
use app\components\User;
use app\components\legacy\LegacyImporter;
use app\models\AssemblyMinutes;
use app\models\AssociationRoles;
use app\models\Members;
use app\models\MembershipFees;
use app\models\MembershipPayments;
use app\models\PaymentMethods;
use app\models\Settings;
use app\models\search\MembersSearch;
use app\models\search\MembershipPaymentsSearch;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * Gestione associazione (TIP-020): soci, quote associative, ricevute, verbali, tabelle.
 * Sola lettura finché l'import dal vecchio Napay è attivo (LEGACY_DB_DSN).
 */
class AssociationController extends Controller
{
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'members' => ['GET'], 'member' => ['GET'], 'payments' => ['GET'], 'receipt' => ['GET'],
                    'minutes' => ['GET'], 'minute-file' => ['GET'], 'tables' => ['GET'], 'export' => ['POST'],
                ],
            ],
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['@'],
                        'matchCallback' => function ($rule, $action) {
                            return User::isAdministrator();
                        },
                    ],
                ],
            ],
        ];
    }

    public function beforeAction($action)
    {
        $this->view->params['associationReadOnly'] = LegacyImporter::active();
        return parent::beforeAction($action);
    }

    public function actionMembers()
    {
        $searchModel = new MembersSearch();
        return $this->render('members', [
            'searchModel' => $searchModel,
            'dataProvider' => $searchModel->search(Yii::$app->request->queryParams),
            'roles' => ArrayHelper::map(AssociationRoles::find()->orderBy('id')->all(), 'id', 'description'),
        ]);
    }

    public function actionMember($id)
    {
        $member = Members::find()->alias('u')->select(['u.*', 'last_expiry' => Members::lastExpirySql()])
            ->andWhere(['u.id' => $this->decodeId($id)])->with(['profile.role', 'privilege'])->one();
        if ($member === null) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        return $this->render('member', [
            'member' => $member,
            'payments' => MembershipPayments::find()->where(['user_id' => $member->id])->with(['method', 'fee'])
                ->orderBy(['registered_on' => SORT_DESC, 'id' => SORT_DESC])->all(),
        ]);
    }

    public function actionPayments()
    {
        $searchModel = new MembershipPaymentsSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);
        return $this->render('payments', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
            'totals' => MembershipPaymentsSearch::totals($dataProvider),
            'methods' => ArrayHelper::map(PaymentMethods::find()->orderBy('description')->all(), 'id', 'description'),
            'years' => array_column(MembershipPayments::find()->select(['y' => 'YEAR(registered_on)'])->distinct()->orderBy(['y' => SORT_DESC])->asArray()->all(), 'y', 'y'),
            'queryParams' => Json::encode(Yii::$app->request->queryParams),
        ]);
    }

    /** Export Excel dei pagamenti filtrati */
    public function actionExport()
    {
        $raw = Yii::$app->request->post('queryParams');
        if (!is_string($raw) || strlen($raw) > 65536) throw new BadRequestHttpException('Invalid export filters.');
        try {
            $queryParams = Json::decode($raw);
        } catch (\yii\base\InvalidArgumentException $error) {
            throw new BadRequestHttpException('Invalid export filters.');
        }
        if (!is_array($queryParams)) throw new BadRequestHttpException('Invalid export filters.');
        $searchModel = new MembershipPaymentsSearch();
        $dataProvider = $searchModel->search($queryParams);
        $dataProvider->pagination = false;

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $columns = [
            Yii::t('app', 'Ricevuta') => fn($p) => $p->receiptLabel(),
            Yii::t('app', 'Data') => fn($p) => $p->registered_on,
            Yii::t('app', 'Cognome') => fn($p) => $p->user->last_name ?? '',
            Yii::t('app', 'Nome') => fn($p) => $p->user->first_name ?? '',
            Yii::t('app', 'Email') => fn($p) => $p->user->email ?? '',
            Yii::t('app', 'Quota') => fn($p) => $p->fee->description ?? '',
            Yii::t('app', 'Importo') => fn($p) => $p->amount,
            Yii::t('app', 'Metodo') => fn($p) => $p->method->description ?? '',
            Yii::t('app', 'Stato') => fn($p) => $p->statusLabel(),
            Yii::t('app', 'Inizio') => fn($p) => $p->starts_on,
            Yii::t('app', 'Scadenza') => fn($p) => $p->expires_on,
        ];
        $col = 1;
        foreach (array_keys($columns) as $title) $sheet->setCellValue([$col++, 1], $title);
        $row = 2;
        foreach ($dataProvider->getModels() as $payment) {
            $col = 1;
            foreach ($columns as $value) {
                // testo inserito dagli utenti: mai formule Excel
                $sheet->setCellValueExplicit([$col++, $row], trim((string) $value($payment)), DataType::TYPE_STRING);
            }
            $row++;
        }
        $response = Yii::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment;filename="' . date('Y-m-d_H-i-s') . '-quote-associative.xlsx"');
        $response->headers->set('Cache-Control', 'no-store');
        ob_start();
        try {
            IOFactory::createWriter($spreadsheet, 'Xlsx')->save('php://output');
            $response->content = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        return $response;
    }

    /** Ricevuta stampabile (Stampa del browser -> PDF) */
    public function actionReceipt($id)
    {
        $payment = MembershipPayments::find()->where(['id' => $this->decodeId($id)])->with(['user', 'fee', 'method', 'profile'])->one();
        if ($payment === null) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        $codes = ['gdpr_titolare', 'gdpr_address', 'gdpr_cap', 'gdpr_city', 'gdpr_vat'];
        return $this->render('receipt', [
            'payment' => $payment,
            'association' => ArrayHelper::map(Settings::find()->where(['code' => $codes])->all(), 'code', 'value'),
        ]);
    }

    public function actionMinutes()
    {
        return $this->render('minutes', [
            'minutes' => AssemblyMinutes::listing()->orderBy(['meeting_date' => SORT_DESC])->all(),
        ]);
    }

    public function actionMinuteFile($id)
    {
        $minute = AssemblyMinutes::findOne($this->decodeId($id));
        if ($minute === null || $minute->file_content === null) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        $name = 'verbale-' . $minute->meeting_date . '.pdf';
        return Yii::$app->response->sendContentAsFile($minute->file_content, $name, ['mimeType' => 'application/pdf', 'inline' => false]);
    }

    public function actionTables()
    {
        return $this->render('tables', [
            'roles' => AssociationRoles::find()->orderBy('id')->all(),
            'fees' => MembershipFees::find()->orderBy('id')->all(),
            'methods' => PaymentMethods::find()->orderBy('id')->all(),
        ]);
    }

    /** ID cifrato negli URL; valori manomessi -> 404, mai errore 500 */
    private function decodeId($id): int
    {
        if (!is_string($id) || $id === '' || strlen($id) > 512) return 0;
        try {
            $plain = Crypt::decrypt($id);
        } catch (\Throwable $error) {
            return 0;
        }
        return ctype_digit($plain) ? (int) $plain : 0;
    }
}
