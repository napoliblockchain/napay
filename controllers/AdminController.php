<?php

namespace app\controllers;

use Yii;
use app\models\search\SettingsSearch;
use app\models\search\UsersSearch;
use app\models\search\PrivilegesSearch;
use app\models\search\LogsSearch;
use app\models\Privileges;

use yii\web\Controller;
use yii\filters\AccessControl;

use app\components\User;


/**
 * AdminController implements the CRUD actions for Logs model.
 */
class AdminController extends Controller
{
    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'actions' => [
                            'index',
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
     * Lists all Logs models.
     * @return mixed
     */
    public function actionIndex()
    {
        $dataUsers = null;
        $dataPrivilegi = null;
        $dataSettings = null;
        $dataLogs = null;
                
        if (User::isAdministrator()){
            // Users
            $searchUsers = new UsersSearch();
            $dataUsers = $searchUsers->search(Yii::$app->request->queryParams);
            $dataUsers->query->andWhere(['privilege_id' => Privileges::find()
                ->select('id')->where(['<=', 'level', User::privilegeLevel()])]);
            $dataUsers->pagination->pageSize = 5;
            $dataUsers->sort->defaultOrder = ['id' => SORT_DESC];

            // Privilegi
            $searchPrivilegi = new PrivilegesSearch();
            $dataPrivilegi = $searchPrivilegi->search(Yii::$app->request->queryParams);
            $dataPrivilegi->pagination->pageSize = 5;
            $dataPrivilegi->sort->defaultOrder = ['id' => SORT_DESC];
    
            // Settings
            $searchSettings = new SettingsSearch();
            $dataSettings = $searchSettings->search(Yii::$app->request->queryParams);
            $dataSettings->pagination->pageSize = 5;
            $dataSettings->sort->defaultOrder = ['id' => SORT_DESC];

            // Logs
            $searchLogs = new LogsSearch();
            $dataLogs = $searchLogs->search(Yii::$app->request->queryParams);
            $dataLogs->pagination->pageSize = 5;
            $dataLogs->sort->defaultOrder = ['id' => SORT_DESC];
        }

        
        return $this->render('index', [
            'dataUsers' => $dataUsers,
            'dataPrivilegi' => $dataPrivilegi,
            'dataSettings' => $dataSettings,
            'dataLogs' => $dataLogs,
        ]);
    }

    
}
