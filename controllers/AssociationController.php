<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\UploadedFile;
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
use app\models\MemberProfiles;
use app\models\Members;
use app\models\MembershipFees;
use app\models\MembershipPayments;
use app\models\PaymentMethods;
use app\models\Settings;
use app\models\Users;
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
                    'payment-create' => ['GET', 'POST'], 'payment-update' => ['GET', 'POST'], 'payment-delete' => ['POST'],
                    'member-update' => ['GET', 'POST'], 'table-edit' => ['GET', 'POST'], 'table-delete' => ['POST'],
                    'minute-create' => ['GET', 'POST'], 'minute-update' => ['GET', 'POST'], 'minute-delete' => ['POST'],
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

    // ---- scritture: solo amministratori, POST + CSRF, mai mentre l'import dal vecchio Napay è attivo ----

    /** Tabelle gestibili: tipo => [classe, campi del form] */
    private const TABLES = [
        'role' => [AssociationRoles::class, ['description']],
        'fee' => [MembershipFees::class, ['description', 'duration', 'amount']],
        'method' => [PaymentMethods::class, ['description', 'min_level']],
    ];

    /** @throws ForbiddenHttpException finché i dati arrivano dall'import legacy */
    public static function ensureWritable(): void
    {
        if (LegacyImporter::active()) {
            throw new ForbiddenHttpException(Yii::t('app', 'Dati sincronizzati dal vecchio Napay: modifica disattivata.'));
        }
    }

    public function actionPaymentCreate($user = null)
    {
        self::ensureWritable();
        $payment = new MembershipPayments(['status' => 'paid', 'registered_on' => date('Y-m-d')]);
        if ($user !== null) $payment->user_id = $this->decodeId($user) ?: null;
        $fee = MembershipFees::find()->orderBy('id')->one();
        if ($fee !== null) {
            $year = (int) date('Y');
            $payment->fee_id = $fee->id;
            $payment->amount = $fee->amount;
            $payment->starts_on = $year . '-01-01';
            $payment->expires_on = ($year + max(1, (int) $fee->duration) - 1) . '-12-31';
        }
        return $this->savePayment($payment);
    }

    public function actionPaymentUpdate($id)
    {
        self::ensureWritable();
        return $this->savePayment($this->findPayment($id));
    }

    public function actionPaymentDelete($id)
    {
        self::ensureWritable();
        $payment = $this->findPayment($id);
        $payment->delete();
        Yii::$app->session->setFlash('success', Yii::t('app', 'Pagamento eliminato.'));
        return $this->redirect(['member', 'id' => Crypt::encrypt($payment->user_id)]);
    }

    public function actionMemberUpdate($id)
    {
        self::ensureWritable();
        $user = Users::findOne($this->decodeId($id));
        if ($user === null) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        $profile = MemberProfiles::findOne(['user_id' => $user->id]) ?? new MemberProfiles(['user_id' => $user->id]);
        // solo campi del profilo: user_id e collegamenti legacy non sono modificabili dal form
        if ($profile->load(Yii::$app->request->post(), 'MemberProfiles') && $profile->save(true, ['association_role_id', 'is_corporate', 'denomination', 'vat', 'address', 'cap', 'city', 'country', 'user_id'])) {
            Yii::$app->session->setFlash('success', Yii::t('app', 'Profilo socio aggiornato.'));
            return $this->redirect(['member', 'id' => Crypt::encrypt($user->id)]);
        }
        return $this->render('member-form', [
            'user' => $user, 'profile' => $profile,
            'roles' => ArrayHelper::map(AssociationRoles::find()->orderBy('id')->all(), 'id', 'description'),
        ]);
    }

    public function actionTableEdit($type, $id = null)
    {
        self::ensureWritable();
        [$class, $fields] = self::TABLES[$type] ?? throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        $model = $id === null ? new $class() : $class::findOne($this->decodeId($id));
        if ($model === null) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        if ($model->load(Yii::$app->request->post()) && $model->save(true, $fields)) {
            Yii::$app->session->setFlash('success', Yii::t('app', 'Tabella aggiornata.'));
            return $this->redirect(['tables']);
        }
        return $this->render('table-form', ['model' => $model, 'type' => $type, 'fields' => $fields]);
    }

    public function actionTableDelete($type, $id)
    {
        self::ensureWritable();
        [$class] = self::TABLES[$type] ?? throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        $model = $class::findOne($this->decodeId($id));
        if ($model === null) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        $used = match ($type) {
            'role' => MemberProfiles::find()->where(['association_role_id' => $model->id])->exists(),
            'fee' => MembershipPayments::find()->where(['fee_id' => $model->id])->exists(),
            'method' => MembershipPayments::find()->where(['payment_method_id' => $model->id])->exists(),
        };
        if ($used) {
            Yii::$app->session->setFlash('danger', Yii::t('app', 'Voce in uso: non può essere eliminata.'));
        } else {
            $model->delete();
            Yii::$app->session->setFlash('success', Yii::t('app', 'Voce eliminata.'));
        }
        return $this->redirect(['tables']);
    }

    public function actionMinuteCreate()
    {
        self::ensureWritable();
        return $this->saveMinute(new AssemblyMinutes(['meeting_date' => date('Y-m-d')]), true);
    }

    public function actionMinuteUpdate($id)
    {
        self::ensureWritable();
        return $this->saveMinute($this->findMinute($id), false);
    }

    public function actionMinuteDelete($id)
    {
        self::ensureWritable();
        $this->findMinute($id)->delete();
        Yii::$app->session->setFlash('success', Yii::t('app', 'Verbale eliminato.'));
        return $this->redirect(['minutes']);
    }

    private function savePayment(MembershipPayments $payment)
    {
        $post = Yii::$app->request->post();
        if ($payment->load($post)) {
            // numero ricevuta e anno assegnati dal server, mai dal form
            $saved = $payment->validate(['user_id', 'fee_id', 'payment_method_id', 'amount', 'registered_on', 'starts_on', 'expires_on', 'status', 'paypal_txn_id', 'btcpay_invoice_id'])
                && $this->persistPayment($payment);
            if ($saved) {
                Yii::$app->session->setFlash('success', Yii::t('app', 'Pagamento salvato.'));
                return $this->redirect(['member', 'id' => Crypt::encrypt($payment->user_id)]);
            }
        }
        return $this->render('payment-form', [
            'payment' => $payment,
            'users' => ArrayHelper::map(Users::find()->orderBy(['last_name' => SORT_ASC, 'first_name' => SORT_ASC])->all(), 'id', fn($u) => trim($u->last_name . ' ' . $u->first_name) . ' - ' . $u->email),
            'fees' => ArrayHelper::map(MembershipFees::find()->orderBy('id')->all(), 'id', 'description'),
            'methods' => ArrayHelper::map(PaymentMethods::find()->orderBy('description')->all(), 'id', 'description'),
        ]);
    }

    /** Salvataggio con numero ricevuta progressivo per anno (solo pagamenti pagati), sotto lock */
    private function persistPayment(MembershipPayments $payment): bool
    {
        $db = Yii::$app->db;
        $year = (int) substr($payment->registered_on, 0, 4);
        $lock = 'napay-receipt-' . $year;
        if ($db->createCommand('SELECT GET_LOCK(:l, 10)', [':l' => $lock])->queryScalar() != 1) return false;
        $transaction = $db->beginTransaction();
        try {
            if ($payment->status === 'paid' && !$payment->receipt_number) {
                $payment->year = $year;
                $payment->receipt_number = 1 + (int) MembershipPayments::find()->where(['year' => $year])->max('receipt_number');
            }
            $ok = $payment->save(false);
            $ok ? $transaction->commit() : $transaction->rollBack();
            return $ok;
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        } finally {
            $db->createCommand('SELECT RELEASE_LOCK(:l)', [':l' => $lock])->execute();
        }
    }

    private function saveMinute(AssemblyMinutes $minute, bool $fileRequired)
    {
        $post = Yii::$app->request->post();
        if ($minute->load($post)) {
            $file = UploadedFile::getInstanceByName('minute_file');
            $fileError = $this->checkPdf($file, $fileRequired);
            if ($fileError !== null) $minute->addError('file_name', $fileError);
            if (!$minute->hasErrors() && $minute->validate(['meeting_date', 'description'])) {
                if ($file !== null) {
                    $content = file_get_contents($file->tempName);
                    // nome generato dal server, mai quello del client
                    $minute->file_name = 'verbale-' . $minute->meeting_date . '.pdf';
                    $minute->file_sha256 = hash('sha256', $content);
                    $minute->file_content = $content;
                }
                $minute->save(false);
                Yii::$app->session->setFlash('success', Yii::t('app', 'Verbale salvato.'));
                return $this->redirect(['minutes']);
            }
        }
        return $this->render('minute-form', ['minute' => $minute]);
    }

    /** PDF vero (firma %PDF- e tipo rilevato), massimo 20 MB */
    private function checkPdf(?UploadedFile $file, bool $required): ?string
    {
        if ($file === null) return $required ? Yii::t('app', 'Carica il PDF del verbale.') : null;
        if ($file->error !== UPLOAD_ERR_OK) {
            return in_array($file->error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? Yii::t('app', 'File troppo grande.') : Yii::t('app', 'Caricamento non riuscito.');
        }
        if ($file->size > 20 * 1024 * 1024) return Yii::t('app', 'File troppo grande.');
        $head = (string) file_get_contents($file->tempName, false, null, 0, 5);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->tempName);
        if ($head !== '%PDF-' || $mime !== 'application/pdf') return Yii::t('app', 'Il file deve essere un PDF.');
        return null;
    }

    private function findPayment($id): MembershipPayments
    {
        return MembershipPayments::findOne($this->decodeId($id)) ?? throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
    }

    private function findMinute($id): AssemblyMinutes
    {
        return AssemblyMinutes::findOne($this->decodeId($id)) ?? throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
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
