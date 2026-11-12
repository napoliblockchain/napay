<?php

namespace app\controllers;

use Yii;
use app\models\Merchants;

use app\models\search\MerchantsSearch;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\ForbiddenHttpException;
use yii\filters\VerbFilter;
use yii\filters\AccessControl;

use app\components\Crypt;
use app\components\User;
use app\components\Log;
use app\components\identity\AccountEligibility;
use app\components\identity\MerchantActivation;
use app\models\Users;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use yii\helpers\Json;

/**
 * MerchantsController implements the CRUD actions for Merchants model.
 */
class MerchantsController extends Controller
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
                    'delete' => ['POST'],
                    'company' => ['GET', 'POST'],
                    'create' => ['GET', 'POST'],
                    'activate' => ['GET', 'POST'],
                ],
            ],
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'actions'=>['activate'], 'allow'=>true, 'roles'=>['@'],
                        'matchCallback'=>static fn() => AccountEligibility::canActivateMerchant((int)Yii::$app->user->id)
                            || AccountEligibility::merchantForManager((int)Yii::$app->user->id) !== null,
                    ],
                    [
                        'actions' => [
                            'view',
                            'update',
                            'company',
                        ],
                        'allow' => true,
                        'roles' => ['@'],
                        'matchCallback' => function ($rule, $action) {
                            return User::isMerchant();
                        },
                    ],
                    [
                        'actions' => [
                            'index',
                            'create',
                            'view',
                            'update',
                            'delete',
                            'export'
                        ],
                        'allow' => true,
                        'roles' => ['@'],
                        'matchCallback' => function ($rule, $action) {
                            return User::isAdministrator();
                        },
                    ],
                ]
            ]
        ];
    }


    /**
     * Lists all Merchants models.
     * @return mixed
     */
    public function actionIndex()
    {
        $searchModel = new MerchantsSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);
        $dataProvider->sort->defaultOrder = ['description' => SORT_ASC];
        
        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * Displays a single Merchants model.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionView($id)
    {
        return $this->render('view', [
            'model' => $this->findModel($id),
        ]);
    }

    /**
     * Creates a new Merchants model.
     * If creation is successful, the browser will be redirected to the 'view' page.
     * @return mixed
     */
    public function actionCreate()
    {
        $model = new Merchants(['scenario'=>Merchants::SCENARIO_ACTIVATION]);
        if ($model->load(Yii::$app->request->post())) {
            $owner = $model->user_id;
            if (!is_scalar($owner) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$owner) || (float)$owner > 2147483647) {
                throw new \yii\web\BadRequestHttpException('Invalid account.');
            }
            if (($id = $this->activateMerchant($model, (int)$owner)) !== null) {
                return $this->redirect(['view', 'id'=>Crypt::encrypt($id)]);
            }
        }
        $users_list = [];
        foreach (Users::find()->joinWith(['privilege'])->andWhere(['privileges.level'=>User::ROLE_USER])->all() as $candidate) {
            if (AccountEligibility::canActivateMerchant((int)$candidate->id)) $users_list[$candidate->id] = $candidate->username;
        }

        return $this->render('create', [
            'model' => $model,
            'users_list' => $users_list
        ]);
    }

    public function actionActivate()
    {
        $owner = (int)Yii::$app->user->id;
        if (AccountEligibility::merchantForManager($owner) !== null) return $this->redirect(['company']);
        $model = new Merchants(['scenario'=>Merchants::SCENARIO_ACTIVATION, 'user_id'=>$owner]);
        if ($model->load(Yii::$app->request->post()) && $this->activateMerchant($model, $owner) !== null) {
            Yii::$app->session->setFlash('success', Yii::t('app', 'Merchant attivato localmente. La configurazione dei pagamenti non è ancora completata.'));
            return $this->redirect(['company']);
        }
        $model->user_id = $owner;
        return $this->render('activate', ['model'=>$model, 'users_list'=>[]]);
    }

    private function activateMerchant(Merchants $model, int $owner): ?int
    {
        try {
            return MerchantActivation::activate((int)Yii::$app->user->id, $owner, $model);
        } catch (\DomainException $error) {
            throw new ForbiddenHttpException('Merchant activation unavailable.');
        } catch (\Throwable $error) {
            Yii::warning('Local merchant activation failed.', __METHOD__);
            throw new \yii\web\ServiceUnavailableHttpException('Merchant activation unavailable.');
        }
    }

    /**
     * Updates an existing Merchants model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionUpdate($id)
    {
        return $this->updateMerchant($this->findModel($id));
    }

    public function actionCompany()
    {
        $actor = Yii::$app->user->identity;
        if ($actor === null || (int) ($actor->privilege->level ?? -1) !== User::ROLE_MERCHANT) {
            throw new ForbiddenHttpException('You are not allowed to access this company.');
        }
        // Never select a company from request parameters or arbitrarily choose among duplicates.
        $models = Merchants::find()->where(['user_id' => $actor->id])->limit(2)->all();
        if (count($models) !== 1) {
            throw new NotFoundHttpException('Company unavailable for this account.');
        }
        return $this->updateMerchant($models[0], true);
    }

    private function updateMerchant(Merchants $model, bool $ownCompany = false)
    {
        $model->scenario = Merchants::SCENARIO_UPDATE;
        $ownerId = $model->user_id;

        $loaded = $model->load(Yii::$app->request->post());
        // Ownership changes require a separate workflow, never this details form.
        $model->user_id = $ownerId;
        if ($loaded && $model->save()) {
            Yii::$app->session->setFlash('success', Yii::t('app', 'Esercente aggiornato correttamente'));
            // Log message
            $message_log = Yii::t('app', 'User {user} has updated {item}: {itemname}', [
                'user' => Yii::$app->user->identity->username,
                'item' => Yii::$app->controller->id,
                'itemname' => $model->description
            ]);
            Log::save(Yii::$app->controller->id, (Yii::$app->controller->action->id), $message_log);
            // end log message
            return $this->redirect($ownCompany ? ['company'] : ['view', 'id' => Crypt::encrypt($model->id)]);
        }

        return $this->render('update', [
            'model' => $model,
            'ownCompany' => $ownCompany,
        ]);
    }

    /**
     * Deletes an existing Merchants model.
     * If deletion is successful, the browser will be redirected to the 'index' page.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionDelete($id)
    {
        $model = $this->findModel($id);
        
        // Log message
        $message_log = Yii::t('app', 'User {user} has deleted {item}: {itemname}', [
            'user' => Yii::$app->user->identity->username,
            'item' => Yii::$app->controller->id,
            'itemname' => $model->description
        ]);
        Log::save(Yii::$app->controller->id, (Yii::$app->controller->action->id), $message_log);
        // end log message

        $model->delete();
        return $this->redirect(['index']);
    }

    /**
     * Esporta la selezione in un file excel
     */
    public function actionExport()
    {
//         echo '<pre>' . print_r(Yii::$app->request->queryParams, true) . '</pre>';
// exit;


        $searchModel = new MerchantsSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);
        $dataProvider->sort->defaultOrder = ['description' => SORT_ASC];
        $dataProvider->pagination->pageSize = false;

        $allModels = $dataProvider->getModels();

        // inizializzo la classe Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Attributi da scaricare
        $attributeLabels = [
            'id' => Yii::t('app', 'ID'),
            'vatNumber' => Yii::t('app', 'P.Iva'),
            'description' => Yii::t('app', 'Descrizione'),
            'email' => Yii::t('app', 'Email'),
            'phone' => Yii::t('app', 'Telefono'),
            'mobile' => Yii::t('app', 'Mobile'),
            'addressStreet' => Yii::t('app', 'Indirizzo'),
            'addressNumberHouse' => Yii::t('app', 'Civico'),
            'addressCity' => Yii::t('app', 'Città'),
            'addressZip' => Yii::t('app', 'Zip'),
            'addressProvince' => Yii::t('app', 'Provincia'),
            'addressCountry' => Yii::t('app', 'Nazione'),

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
                $writeText = $model->$field;
                $sheet->setCellValue($this->getCellFromColnum($col) . $row, trim($writeText ?? ''), null);

                $col++;
            }
            $row++;
        }

        // output the file
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $date = date('Y/m/d H:i:s', time());
        $filename = $date . '-merchants.xlsx';
        $response = Yii::$app->getResponse();
        $headers = $response->getHeaders();
        $headers->set('Content-Type', 'application/vnd.ms-excel');
        $headers->set('Content-Disposition', 'attachment;filename="' . $filename . '"');
        $headers->set('Cache-Control: max-age=0');
        ob_start();
        $writer->save("php://output");
        $content = ob_get_contents();
        ob_clean();
        return $content;
    }

    private function getCellFromColnum($colNum)
    {
        return ($colNum < 26 ? chr(65 + $colNum) : chr(65 + floor($colNum / 26) - 1) . chr(65 + ($colNum % 26)));
    }  

    /**
     * Finds the Merchants model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return Merchants the loaded model
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
            || ($model = Merchants::findOne((int) $plain)) === null) {
            throw new NotFoundHttpException('The requested page does not exist.');
        }
        $actor = Yii::$app->user->identity;
        $level = (int) ($actor->privilege->level ?? -1);
        if ($actor === null || ($level !== User::ROLE_ADMIN
            && ($level !== User::ROLE_MERCHANT || (string) $model->user_id !== (string) $actor->id))) {
            throw new ForbiddenHttpException('You are not allowed to access this merchant.');
        }
        return $model;
    }
}
