<?php

namespace app\controllers;

use Yii;

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
        return $this->redirect(['/users/index']);
    }

    
}
