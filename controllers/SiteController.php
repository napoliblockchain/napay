<?php

namespace app\controllers;

use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\Response;
use yii\filters\VerbFilter;
use app\models\forms\LoginForm;
use app\models\forms\SignupForm;
use app\models\forms\PasswordResetRequestForm;
use app\models\forms\ResetPasswordForm;
use app\models\Users;
use app\models\UserConsensus;

use app\components\Log;
use app\components\sendMail;
use app\components\Crypt;

use yii\web\BadRequestHttpException;
use yii\base\InvalidArgumentException;

use app\models\search\MerchantsSearch;
use app\models\search\StoresSearch;
use app\models\search\PosSearch;
use app\models\search\InvoicesSearch;

class SiteController extends Controller
{
    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'only' => ['logout','dashboard'],
                'rules' => [
                    [
                        'actions' => ['logout','dashboard'],
                        'allow' => true,
                        'roles' => ['@'],
                        'denyCallback' => function ($rule, $action) {
                            throw new \Exception('You are not allowed to access this page');
                        }
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'logout' => ['get','post'],
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function actions()
    {
        return [
            'error' => [
                'class' => 'yii\web\ErrorAction',
                'layout' => Yii::$app->user->isGuest ? 'login' : 'main',
            ],
            'captcha' => [
                'class' => 'yii\captcha\CaptchaAction',
                'fixedVerifyCode' => YII_ENV_TEST ? 'testme' : null,
            ],
        ];
    }

    

    /**
     * Displays homepage.
     *
     * @return string
     */
    public function actionIndex()
    {
        if (Yii::$app->user->isGuest) {
            return $this->redirect(['site/login']);
        }

        // // esercenti
        // $searchMerchants = new MerchantsSearch();
        // $dataMerchants = $searchMerchants->search(Yii::$app->request->queryParams);
        // $dataMerchants->query->andwhere(['=', 'historical', 0]);
        // if (User::isMerchant() || User::isUser()) {
        //     $dataMerchants->query->andwhere(['=', 'id', Yii::$app->user->identity->merchant_id]);
        // }
        // $dataMerchants->pagination->pageSize = 5;
        // $dataMerchants->sort->defaultOrder = ['description' => SORT_ASC];

        // // negozi
        // $searchStores = new StoresSearch();
        // $dataStores = $searchStores->search(Yii::$app->request->queryParams);
        // $dataStores->query->andwhere(['=', 'stores.historical', 0]);
        // if (User::isUser()) {
        //     $dataStores->query->andwhere(['=', 'stores.id', Yii::$app->user->identity->store_id]);
        // }
        // $dataStores->pagination->pageSize = 5;
        // $dataStores->sort->defaultOrder = ['description' => SORT_ASC];

        // // pos
        // $searchPos = new PosSearch();
        // $dataPos = $searchPos->search(Yii::$app->request->queryParams);
        // $dataPos->query->andwhere(['=', 'pos.historical', 0]);
        // if (User::isMerchant()) {
        //     $dataPos->query->andwhere(['=', 'pos.merchant_id', Yii::$app->user->identity->merchant_id]);
        // }
        // if (User::isUser()) {
        //     $dataPos->query->andwhere(['=', 'pos.store_id', Yii::$app->user->identity->store_id]);
        // }
        // $dataPos->pagination->pageSize = 5;
        // $dataPos->sort->defaultOrder = ['appName' => SORT_ASC];


        // // invoices
        // $searchInvoices = new InvoicesSearch();
        // $dataInvoices = $searchInvoices->search(Yii::$app->request->queryParams);
        // $dataInvoices->query->andwhere(['=', 'invoices.archived', 0]);
        // $dataInvoices->pagination->pageSize = 5;
        // if (User::isMerchant()) {
        //     $dataInvoices->query->andwhere(['=', 'invoices.merchant_id', Yii::$app->user->identity->merchant_id]);
        // }
        // if (User::isUser()) {
        //     $dataInvoices->query->andwhere(['=', 'invoices.store_id', Yii::$app->user->identity->store_id]);
        // }
        // $dataInvoices->sort->defaultOrder = ['id' => SORT_DESC];

        return $this->render('index', [
            // 'dataMerchants' => $dataMerchants,
            // 'dataStores' => $dataStores,
            // 'dataPos' => $dataPos,
            // 'dataInvoices' => $dataInvoices,
        ]);
        
    }

    
    /**
     * Login action.
     *
     * @return Response|string
     */
    public function actionLogin()
    {
        if (!Yii::$app->user->isGuest) {
            return $this->redirect(['site/index']);
        }

        /** 
         * verifico se esiste almeno 1 utente altrimenti vai a registrazione
         */
        $test_user = Users::findOne(1);
        if (null === $test_user) {
            return $this->redirect(['site/signup']);
        }

        $this->layout = 'login';

        $model = new LoginForm();
        if ($model->load(Yii::$app->request->post()) && $model->login()) {
            return $this->redirect(['site/index']);
        }

        $model->password = '';
        return $this->render('login', [
            'model' => $model,
        ]);
    }

    /**
     * Logout action.
     *
     * @return Response
     */
    public function actionLogout()
    {
        // Log message
        $message_log = Yii::t('app', 'User {user} has logged out.', [
            'user' => Yii::$app->user->identity->username,
        ]);
        Log::save(Yii::$app->controller->id, (Yii::$app->controller->action->id), $message_log);
        // end log message

        Yii::$app->user->logout();
        return $this->redirect(['site/login']);

        // return $this->goHome();
    }

    /**
     * Signup action.
     *
     * @return Response|string
     */
    public function actionSignup()
    {
        if (!Yii::$app->user->isGuest) {
            return $this->redirect(['site/index']);
        }

        $this->layout = 'login';

        $model = new SignupForm();
        $consensus = new UserConsensus();

        $post = Yii::$app->request->post();
        // echo "<pre>".print_r($post,true)."</pre>";exit;

        if (isset($post['SignupForm']) && isset($post['UserConsensus'])) {
            if ($model->load(Yii::$app->request->post()) && $consensus->load(Yii::$app->request->post())) {

                $connection = \Yii::$app->db;
                $transaction = $connection->beginTransaction();
                try {
                    if ($user = $model->signup()){
                        // echo "<pre>" . print_r($user->attributes, true) . "</pre>";exit;
                        $consensus->user_id = $user->id;
                        if ($consensus->save()){
                            // invio la mail agli admins
                            sendMail::toAdmins($user, 'newAccountAdmin', Yii::t('app', 'Nuovo account'));

                            // invio la mail all'utente
                            sendMail::toUser($user, 'newAccountUser', 'Nuovo account');

                            $transaction->commit();

                            // Log message
                            $message_log = Yii::t('app', 'User {user} has signup succesfully.', [
                                'user' => $user->username,
                            ]);
                            Log::save(Yii::$app->controller->id, (Yii::$app->controller->action->id), $message_log);
                            // end log message

                            Yii::$app->session->setFlash('success','Utente registrato con successo');
                            return $this->redirect(['site/index']);
                        } else {
                            $transaction->rollback();
                            Yii::$app->session->setFlash('warning', $consensus->getErrors());
                        }
                    } else {
                        $transaction->rollback();
                        Yii::$app->session->setFlash('warning', $consensus->getErrors());
                    }
                } catch (\Exception $e) {
                    $transaction->rollback();
                    Yii::$app->session->setFlash('warning', $e->getMessage());
                }
            } else {
                $p = '<p>C\'è stato un errore.</p>';
                $errors = $model->getErrors();
                foreach ($errors as $id => $error) {
                    // echo "<pre>".print_r($error,true)."</pre>";exit;
                    foreach ($error as $e)
                        $p .= '<p>' . $e . '</p>';
                }
                $errors = $consensus->getErrors();
                foreach ($errors as $id => $error) {
                    // echo "<pre>".print_r($error,true)."</pre>";exit;
                    foreach ($error as $e)
                        $p .= '<p>' . $e . '</p>';
                }

                Yii::$app->session->setFlash('error', $p);
            }
        }

        // $model->password = '';
        return $this->render('signup', [
            'model' => $model,
            'consensus' => $consensus,
        ]);
    }


    /**
     * Activate registered user.
     *
     * @return Response|string
     */
    public function actionActivate($id, $sign)
    {
        $this->layout = 'login';

        $id_decrypted = Crypt::sqlDecrypt($id);

        // check if the message is outdated
        $microtime = explode(' ', microtime());
        $nonce = $microtime[1] . str_pad(substr($microtime[0], 2, 6), 6, '0');
        $a = substr($nonce, 1, 9) * 1;

        $user = Users::findOne(['id' => $id_decrypted]);
        
        if (null !== $user) {
            $b = (int) substr($user->authKey, 1, 9) * 1;

            $diff = $a - $b;

            // echo "<pre>".print_r('a: ' .$a ,true)."</pre>";;
            // echo "<pre>".print_r('b: ' .$b,true)."</pre>";;
            // echo "<pre>" . print_r('diff: ' . $diff, true) . "</pre>";
            // echo "<pre>" . print_r('timeout: ' . Yii::$app->params['nonce.timeout'], true) . "</pre>";
            // exit;
            if ($diff > Yii::$app->params['nonce.timeout']) {
                // verifica che non sia attivo e lo cancella
                if ($user->is_active == 0) {
                    $user->delete();
                    Yii::$app->session->setFlash('warning', Yii::t('app', '<strong>Error!</strong> The registration time has expired. You have to register again!'));
                }
            }
            // Now do the sign
            $signature = base64_encode(hash_hmac('sha512', hash('sha256', $user->authKey . $user->accessToken, true), base64_decode($user->authKey), true));

            // compare the two signatures
            if (strcmp($signature, $sign) == 0) {
                // echo "<pre>".print_r('sono uguali',true)."</pre>";exit;
                $user->authKey = \Yii::$app->security->generateRandomString();
                $user->accessToken = \Yii::$app->security->generateRandomString();
                $user->is_active = Users::STATUS_ACTIVE;
                $user->save();

                // Log message
                $message_log = Yii::t('app', 'User {user} has activated the account succesfully.', [
                    'user' => $user->username,
                ]);
                Log::save(Yii::$app->controller->id, (Yii::$app->controller->action->id), $message_log);
                // end log message

                Yii::$app->session->setFlash('success', Yii::t('app', 'You have activated your account successfully.'));
                // exit;
            } else {
                // echo "<pre>" . print_r('sono diversi', true) . "</pre>";
                // echo "<pre>" . print_r('signature: ' . $signature, true) . "</pre>";
                // echo "<pre>" . print_r('sign: ' . $sign, true) . "</pre>";
                // exit;
                $user->delete();
                Yii::$app->session->setFlash('warning', Yii::t('app', '<strong>Error!</strong> The registration signature is wrong. You have to register again!'));
            }
        } else {
            Yii::$app->session->setFlash('warning', Yii::t('app', '<strong>Error!</strong> Your account doesn\'t exist. You have to register again!'));
        }

        return $this->redirect(['site/index']);
    }

    /**
     * Requests password reset.
     *
     * @return mixed
     */
    public function actionForgotPassword()
    {
        $this->layout = 'login';

        $model = new PasswordResetRequestForm();
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            
            $model->sendEmail();

            // Log message
            $message_log = Yii::t('app', 'User {user} has requested a password reset.', [
                'user' => $model->email,
            ]);
            Log::save(Yii::$app->controller->id, (Yii::$app->controller->action->id), $message_log);
            // end log message
            
            Yii::$app->session->setFlash('success', 'Check your email for further instructions.');
            return $this->redirect(['/site/login']);
        }

        return $this->render('requestPasswordReset', [
            'model' => $model,
        ]);
    }

    /**
     * Resets password.
     *
     * @param string $token
     * @return mixed
     * @throws BadRequestHttpException
     */
    public function actionResetPassword($token)
    {
        $this->layout = 'login';

        try {
            $model = new ResetPasswordForm($token);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        if ($model->load(Yii::$app->request->post()) && $model->validate() && $model->resetPassword()) {

            $model->sendEmail();

            Yii::$app->session->setFlash('success', Yii::t('app', 'New password saved.'));
            return $this->redirect(['/site/login']);
        }

        return $this->render('resetPassword', [
            'model' => $model,
        ]);
    }

    
}
