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
use app\models\forms\ActivationResendForm;
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
    public function beforeAction($action)
    {
        if (in_array($action->id, ['activate', 'reset-password'], true)) {
            // Set before filters/CSRF so even rejected bearer requests are private.
            Yii::$app->response->headers->set('Referrer-Policy', 'no-referrer');
            Yii::$app->response->headers->set('Cache-Control', 'no-store');
        }
        return parent::beforeAction($action);
    }

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
                    'forgot-password' => ['GET', 'POST'],
                    'reset-password' => ['GET', 'POST'],
                    'activate' => ['GET', 'POST'],
                    'resend-activation' => ['GET', 'POST'],
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
            // torna alla pagina che ha chiesto il login (es. link esterno /quota): solo percorso locale, mai un altro host
            $return = (string) Yii::$app->user->getReturnUrl(['site/index']);
            $path = (string) parse_url($return, PHP_URL_PATH);
            $query = parse_url($return, PHP_URL_QUERY);
            if (str_starts_with($path, '/') && !str_starts_with($path, '//') && !str_contains($path, '\\')) {
                return $this->redirect($path . ($query ? '?' . $query : ''));
            }
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
                            // A delivered link must refer to committed user/consent rows.
                            $transaction->commit();
                            // invio la mail agli admins
                            sendMail::toAdmins($user, 'newAccountAdmin', Yii::t('app', 'Nuovo account'));

                            // invio la mail all'utente
                            $sent = sendMail::toUser($user, ['html' => 'newAccountUser', 'text' => 'newAccountUser-text'], 'Nuovo account');

                            // Log message
                            $message_log = Yii::t('app', 'User {user} has signup succesfully.', [
                                'user' => $user->username,
                            ]);
                            Log::save(Yii::$app->controller->id, (Yii::$app->controller->action->id), $message_log);
                            // end log message

                            Yii::$app->session->setFlash('success', $sent ? 'Utente registrato con successo' : 'Registrazione completata. Se non ricevi la mail, richiedi un nuovo link di attivazione dal login.');
                            return $this->redirect(['site/index']);
                        } else {
                            $transaction->rollback();
                            Yii::$app->session->setFlash('warning', $consensus->getErrors());
                        }
                    } else {
                        $transaction->rollback();
                        Yii::$app->session->setFlash('warning', $consensus->getErrors());
                    }
                } catch (\Throwable $e) {
                    if ($transaction->isActive) $transaction->rollback();
                    Yii::warning('Registration could not be completed.', __METHOD__);
                    Yii::$app->session->setFlash('warning', 'Registrazione non completata. Riprova oppure richiedi il recupero dal login.');
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
    public function actionActivate($id = "", $sign = "")
    {
        $this->layout = "login";
        $plain = "";
        if (is_string($id) && strlen($id) <= 2048 && is_string($sign) && strlen($sign) === 88) {
            try { $plain = @Crypt::sqlDecrypt($id); } catch (\Throwable $error) { }
        }
        if (!preg_match("/^[1-9][0-9]*$/D", $plain)
            || filter_var($plain, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 2147483647]]) === false
            || ($user = Users::findOne((int) $plain)) === null || !$user->canActivate($sign)) {
            throw new BadRequestHttpException("Activation link is invalid or expired.");
        }
        if (Yii::$app->request->isPost) {
            if (!$user->activate($sign)) throw new BadRequestHttpException("Activation link is invalid or expired.");
            Log::save($this->id, $this->action->id, "Account activated: user ID " . $user->id);
            Yii::$app->session->setFlash("success", Yii::t("app", "You have activated your account successfully."));
            return $this->redirect(["site/login"]);
        }
        return $this->render("activate");
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

            $message_log = 'Password reset request received.';
            Log::save(Yii::$app->controller->id, (Yii::$app->controller->action->id), $message_log);
            // end log message
            
            Yii::$app->session->setFlash('success', 'Check your email for further instructions.');
            return $this->redirect(['/site/login']);
        }

        return $this->render('requestPasswordReset', [
            'model' => $model,
        ]);
    }

    public function actionResendActivation()
    {
        $this->layout = 'login';
        $model = new ActivationResendForm();
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            $model->sendEmail();
            Yii::$app->session->setFlash('success', Yii::t('app', 'If an eligible account exists, activation instructions have been sent.'));
            return $this->redirect(['site/login']);
        }
        return $this->render('resendActivation', ['model' => $model]);
    }

    /**
     * Resets password.
     *
     * @param string $token
     * @return mixed
     * @throws BadRequestHttpException
     */
    public function actionResetPassword($token = '')
    {
        $this->layout = 'login';

        try {
            $model = new ResetPasswordForm($token);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            if (!$model->resetPassword()) throw new BadRequestHttpException('Password reset link is no longer valid.');

            $model->sendEmail();

            Yii::$app->session->setFlash('success', Yii::t('app', 'New password saved.'));
            return $this->redirect(['/site/login']);
        }

        return $this->render('resetPassword', [
            'model' => $model,
        ]);
    }

    
}
