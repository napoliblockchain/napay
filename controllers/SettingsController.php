<?php

namespace app\controllers;

use Yii;
use app\models\Settings;
use app\models\search\SettingsSearch;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;
use yii\filters\AccessControl;

use app\components\Crypt;
use app\components\User;
use app\components\Log;

/**
 * SettingsController implements the CRUD actions for Settings model.
 */
class SettingsController extends Controller
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
                            'update',
                            'delete',
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
     * Lists all Settings models.
     * @return mixed
     */
    public function actionIndex()
    {
        $searchModel = new SettingsSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * Displays a single Settings model.
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
     * Creates a new Settings model.
     * If creation is successful, the browser will be redirected to the 'view' page.
     * @return mixed
     */
    public function actionCreate()
    {
        $model = new Settings();

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            $message_log = Yii::t('app', 'User {user} has created the item {item} #{id}', [
                'user' => Yii::$app->user->identity->username,
                'item' => Yii::$app->controller->id,
                'id' => $model->id
            ]);
            Log::save(Yii::$app->controller->id, (Yii::$app->controller->action->id), $message_log);
            Yii::$app->session->setFlash('success', $message_log);

            return $this->redirect(['view', 'id' => Crypt::encrypt($model->id)]);
        }

        return $this->render('create', [
            'model' => $model,
        ]);
    }

    /**
     * Updates an existing Settings model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionUpdate($id)
    {
        $model = $this->findModel(Crypt::decrypt($id));

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            $message_log = Yii::t('app', 'User {user} has updated the item {item} #{id}', [
                'user' => Yii::$app->user->identity->username,
                'item' => Yii::$app->controller->id,
                'id' => $model->id
            ]);
            Log::save(Yii::$app->controller->id, (Yii::$app->controller->action->id), $message_log);
            Yii::$app->session->setFlash('success', $message_log);

            return $this->redirect(['view', 'id' => Crypt::encrypt($model->id)]);
        }

        return $this->render('update', [
            'model' => $model,
        ]);
    }

    /**
     * Deletes an existing Settings model.
     * If deletion is successful, the browser will be redirected to the 'index' page.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionDelete($id)
    {
        $model = $this->findModel(Crypt::decrypt($id));

        $message_log = Yii::t('app', 'User {user} has deleted the item {item} #{id}', [
            'user' => Yii::$app->user->identity->username,
            'item' => Yii::$app->controller->id,
            'id' => $model->id
        ]);
        Log::save(Yii::$app->controller->id, (Yii::$app->controller->action->id), $message_log);
        Yii::$app->session->setFlash('success', $message_log);

        $model->delete();

        return $this->redirect(['index']);
    }

    /**
     * Finds the Settings model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return Settings the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    protected function findModel($id)
    {
        if (($model = Settings::findOne($id)) !== null && !in_array($model->code, Settings::RETIRED_CODES, true)) {
            return $model;
        }

        throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
    }
}
