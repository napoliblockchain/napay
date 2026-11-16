<?php

namespace app\controllers;

use Yii;
use app\models\Privileges;
use app\models\search\PrivilegesSearch;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;
use yii\filters\AccessControl;

use app\components\Crypt;
use app\components\User;
use app\components\Log;

/**
 * PrivilegesController implements the CRUD actions for Privileges model.
 */
class PrivilegesController extends Controller
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
                ],
            ],
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'actions' => [
                            'index',
                            'view',
                            'create',
                            'delete',
                            'update'
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
     * Lists all Privileges models.
     * @return mixed
     */
    public function actionIndex()
    {
        $searchModel = new PrivilegesSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * Displays a single Privileges model.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionView($id)
    {
        return $this->render('view', [
            'model' => $this->findModel(Crypt::decrypt($id)),
        ]);
    }

    /**
     * Creates a new Privileges model.
     * If creation is successful, the browser will be redirected to the 'view' page.
     * @return mixed
     */
    public function actionCreate()
    {
        $model = new Privileges();

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', Yii::t('app', 'Profilo creato correttamente'));
            $message_log = Yii::t('app', 'User {user} has created a new {item}: {itemname}', [
                'user' => Yii::$app->user->identity->username,
                'item' => Yii::$app->controller->id,
                'itemname' => $model->description
            ]);
            Log::save(Yii::$app->controller->id, (Yii::$app->controller->action->id), $message_log);
            return $this->redirect(['view', 'id' => Crypt::encrypt($model->id)]);
        }

        return $this->render('create', [
            'model' => $model,
        ]);
    }

    /**
     * Updates an existing Privileges model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionUpdate($id)
    {
        $model = $this->findModel(Crypt::decrypt($id));

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', Yii::t('app', 'Profilo aggiornato correttamente'));
            $message_log = Yii::t('app', 'User {user} has updated {item}: {itemname}', [
                'user' => Yii::$app->user->identity->username,
                'item' => Yii::$app->controller->id,
                'itemname' => $model->description
            ]);
            Log::save(Yii::$app->controller->id, (Yii::$app->controller->action->id), $message_log);
            return $this->redirect(['view', 'id' => Crypt::encrypt($model->id)]);
        }

        return $this->render('update', [
            'model' => $model,
        ]);
    }

    /**
     * Deletes an existing Privileges model.
     * If deletion is successful, the browser will be redirected to the 'index' page.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionDelete($id)
    {
        $message_log = Yii::t('app', 'User {user} has deleted {item}: {itemname}', [
            'user' => Yii::$app->user->identity->username,
            'item' => Yii::$app->controller->id,
            'itemname' => $model->description
        ]);
        Log::save(Yii::$app->controller->id, (Yii::$app->controller->action->id), $message_log);
        // il delete viene dopo il log, altrimenti va in errore
        $this->findModel(Crypt::encrypt($id))->delete();
        return $this->redirect(['index']);
    }

    /**
     * Finds the Privileges model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return Privileges the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    protected function findModel($id)
    {
        if (($model = Privileges::findOne($id)) !== null) {
            return $model;
        }

        throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
    }
}
