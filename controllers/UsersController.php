<?php

namespace app\controllers;

use Yii;
use app\models\Users;
use app\models\Merchants;
use app\models\Privileges;
use app\models\search\UsersSearch;

use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\ServerErrorHttpException;
use yii\web\Cookie;
use yii\web\Response;
use yii\filters\VerbFilter;
use yii\filters\AccessControl;
use yii\helpers\ArrayHelper;


use app\components\Crypt;
use app\components\User;
use app\components\Log;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use yii\helpers\Json;


/**
 * UsersController implements the CRUD actions for Users model.
 */
class UsersController extends Controller
{
    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'view' => ['GET'],
                    'index' => ['GET'],
                    'update' => ['POST'],
                    'enable' => ['POST'],
                    'disable' => ['POST'],
                    'export' => ['POST'],
                    'change-collapsed' => ['POST'],
                ],
            ],
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'actions' => [
                            'view',
                            'change-collapsed',
                        ],
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                    [
                        'actions' => [
                            'index',
                            'update',
                            'disable',
                            'enable',
                            'export',
                        ],
                        'allow' => true,
                        'roles' => ['@'],
                        'matchCallback' => function ($rule, $action) {
                            return User::can(40);
                        },
                    ],
                ]
            ]
        ];
    }

    /**
     * Cambia menu laterale collapsed/not collapsed
     * @param
     */
    public function actionChangeCollapsed()
    {
        if (Yii::$app->request->cookies->has('collapsed')) {
            Yii::$app->response->cookies->remove('collapsed');
        } else {
            Yii::$app->response->cookies->add(new Cookie([
                'name' => 'collapsed',
                'value' => Json::encode(['body' => 'sidebar-collapse']),
                'httpOnly' => true,
                'sameSite' => Cookie::SAME_SITE_LAX,
                'secure' => Yii::$app->request->isSecureConnection,
            ]));
        }
        return true;
    }

    /**
     * Esporta la selezione in un file excel
     */
    public function actionExport()
    {
        $raw = Yii::$app->request->post('queryParams');
        if (!is_string($raw) || strlen($raw) > 65536) {
            throw new BadRequestHttpException('Invalid export filters.');
        }
        try {
            $queryParams = Json::decode($raw);
        } catch (\yii\base\InvalidArgumentException $error) {
            throw new BadRequestHttpException('Invalid export filters.');
        }
        if (!is_array($queryParams)) {
            throw new BadRequestHttpException('Invalid export filters.');
        }

        $searchModel = new UsersSearch();
        $dataProvider = $searchModel->search($queryParams);
        $dataProvider->sort->defaultOrder = ['last_name' => SORT_ASC];
        $dataProvider->pagination->pageSize = false;
        // Scope the query before counts/pagination/export, for every manager level.
        $dataProvider->query->andWhere(['privilege_id' => Privileges::find()
            ->select('id')->where(['<=', 'level', User::privilegeLevel()])]);
        $allModels = $dataProvider->getModels();

        // inizializzo la classe Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Attributi da scaricare
        $attributeLabels = [
            'id' => Yii::t('app', 'ID'),
            'username' => Yii::t('app', 'Nome utente'),
            // 'oauth_provider' => Yii::t('app', 'OAuth Provider'),
            // 'oauth_uid' => Yii::t('app', 'OAuth ID'),
            // 'authKey' => Yii::t('app', 'Auth Key'),
            // 'accessToken' => Yii::t('app', 'Access Token'),
            'first_name' => Yii::t('app', 'Nome'),
            'last_name' => Yii::t('app', 'Cognome'),
            'email' => Yii::t('app', 'Email'),
            // 'picture' => Yii::t('app', 'Picture'),
            'privilege.description' => Yii::t('app', 'Profilo'),
            'merchant.description' => Yii::t('app', 'Esercente'),
            'store.description' => Yii::t('app', 'Negozio'),
            'is_active' => Yii::t('app', 'Abilitato'),
        ];
        

        // create header
        $x = 0;
        foreach ($attributeLabels as $field => $text) {
            $sheet->setCellValue($this->getCellFromColnum($x) . '1', $text);
            $x++;
        }

        // load rows
        // adesso fare il ciclo sui campi dei titoli...
        $row = 2;
        foreach ($allModels as $n => $model) {
            $col = 0;
            foreach ($attributeLabels as $field => $text) {
                $explode = explode('.', $field);

                if (!isset($explode[1])) {
                    $writeText = $model->$field;
                } else {
                    $submodel = $explode[0];
                    $field = $explode[1];

                    $writeText = $model->$submodel->$field ?? '';
                }
                // User-controlled text must never become an Excel formula.
                $sheet->setCellValueExplicit($this->getCellFromColnum($col) . $row, trim((string) ($writeText ?? '')), DataType::TYPE_STRING);
                $col++;
            }
            $row++;
        }

        // output the file
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $date = date('Y-m-d_H-i-s');
        $filename = $date . '-users.xlsx';
        $response = Yii::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $headers = $response->getHeaders();
        $headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $headers->set('Content-Disposition', 'attachment;filename="' . $filename . '"');
        $headers->set('Cache-Control', 'no-store');
        ob_start();
        try {
            $writer->save('php://output');
            $content = ob_get_contents();
        } finally {
            ob_end_clean();
            $spreadsheet->disconnectWorksheets();
        }
        return $content;
    }

    private function getCellFromColnum($colNum)
    {
        return ($colNum < 26 ? chr(65 + $colNum) : chr(65 + floor($colNum / 26) - 1) . chr(65 + ($colNum % 26)));
    }   
    

    /**
     * Lists all Users models.
     * @return mixed
     */
    public function actionIndex()
    {
        
        if (User::can(40)){
            $searchModel = new UsersSearch();
            $dataProvider = $searchModel->search(Yii::$app->request->queryParams);
            $dataProvider->sort->defaultOrder = ['last_name' => SORT_ASC];
            $dataProvider->query->andWhere(['privilege_id' => Privileges::find()
                ->select('id')->where(['<=', 'level', User::privilegeLevel()])]);

            return $this->render('index', [
                'searchModel' => $searchModel,
                'dataProvider' => $dataProvider,
                'queryParams' => Json::encode(Yii::$app->request->queryParams),
            ]);
        } else {
            return $this->redirect(['site/index']);
        }
    }

    /**
     * Displays a single Users model.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionView($id)
    {
        $model = $this->findModel($id);
        $merchants_list = User::can(40)
            ? ArrayHelper::map(Merchants::find()->all(), 'id', 'description') : [];
        asort($merchants_list);

        return $this->render('view', [
            'model' => $model,
            'merchants_list' => $merchants_list,
        ]);
    }

    /**
     * Updates an existing Users model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionUpdate($id)
    {
        $model = $this->findModel($id);
        $input = Yii::$app->request->post('Users');
        // This form changes account status only, never credentials or privileges.
        if (!is_array($input) || array_keys($input) !== ['is_active']
            || !in_array($input['is_active'], ['0', '1', 0, 1], true)) {
            throw new BadRequestHttpException('Invalid account status update.');
        }
        return $this->changeStatus($model, (int) $input['is_active']);
    }

    /**
     * Activates an existing Users model.
     * If deletion is successful, the browser will be redirected to the 'index' page.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionEnable($id)
    {
        return $this->changeStatus($this->findModel($id), Users::STATUS_ACTIVE);
    }

    public function actionDisable($id)
    {
        return $this->changeStatus($this->findModel($id), Users::STATUS_INSERTED);
    }

    private function changeStatus(Users $model, int $status)
    {
        if ((string) $model->id === (string) Yii::$app->user->id) {
            throw new ForbiddenHttpException("Self status changes are not allowed.");
        }
        $attributes = ["is_active"];
        // Pending and disabled both use 0: explicit disable must revoke even pending links.
        if ((int) $model->is_active !== $status || $status === Users::STATUS_INSERTED) {
            $model->is_active = $status;
            if ($status !== Users::STATUS_ACTIVE) {
                // Revoke existing sessions, remember-me cookies and API tokens.
                $model->generateAuthKey();
                $model->accessToken = Yii::$app->security->generateRandomString(60);
                $model->activationCode = null;
                $attributes = array_merge($attributes, ["authKey", "accessToken", "activationCode"]);
            }
            if (!$model->save(false, $attributes)) {
                throw new ServerErrorHttpException("Unable to update account status.");
            }
            Log::save($this->id, $this->action->id, "Account status changed: user ID " . $model->id . ", status " . $status);
        }
        return $this->redirect(["view", "id" => Crypt::encrypt($model->id)]);
    }

    /**
     * Resolves a session-encrypted user ID and enforces record-level access.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param string $id encrypted identifier
     * @return Users the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    protected function findModel($id)
    {
        if (!is_string($id) || strlen($id) > 1024 || base64_decode($id, true) === false) {
            throw new NotFoundHttpException('The requested page does not exist.');
        }
        try {
            $plain = @Crypt::decrypt($id);
        } catch (\Throwable $error) {
            $plain = '';
        }
        if (!preg_match('/^[1-9][0-9]*$/D', $plain)
            || filter_var($plain, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) === false
            || ($model = Users::findOne((int) $plain)) === null) {
            throw new NotFoundHttpException('The requested page does not exist.');
        }
        $actor = Yii::$app->user->identity;
        $ownProfile = $actor !== null && (string) $actor->id === (string) $model->id;
        $actorLevel = $actor->privilege->level ?? null;
        $targetLevel = $model->privilege->level ?? null;
        if (!$ownProfile && ($actorLevel === null || $targetLevel === null
            || $actorLevel < 40 || $targetLevel > $actorLevel)) {
            throw new ForbiddenHttpException('You are not allowed to access this account.');
        }
        return $model;
    }


}
