<?php

namespace app\controllers;

use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\filters\VerbFilter;
use yii\helpers\Html;
use app\models\forms\LoginForm;
use app\models\forms\SignupForm;
use app\models\forms\PasswordResetRequestForm;
use app\models\forms\ResetPasswordForm;
use app\models\forms\ActivationResendForm;
use app\models\Users;
use app\models\UserConsensus;
use app\models\LegalDocuments;
use app\models\ConsentEvents;
use app\models\MemberProfiles;

use app\components\Log;
use app\components\sendMail;
use app\components\Crypt;
use app\components\User;

use yii\web\BadRequestHttpException;
use yii\base\InvalidArgumentException;

use app\models\search\MembersSearch;

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
                'only' => ['logout', 'accept-terms', 'consents', 'marketing'],
                'rules' => [
                    [
                        'actions' => ['logout', 'accept-terms', 'consents', 'marketing'],
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
                    'accept-terms' => ['GET', 'POST'],
                    'consents' => ['GET'],
                    'marketing' => ['POST'],
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
                'layout' => Yii::$app->user->isGuest ? 'auth' : 'main',
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

        // riepilogo incassi TTS e saldo del wallet (gestore e admin, stesso scope di Incassi)
        $scope = \app\components\pos\management\Scope::current();
        return $this->render('index', [
            'payments' => $scope === null ? null : \app\components\pos\tts\PaymentTotals::dashboard($scope),
            // SIP-030: richieste di iscrizione da approvare (solo admin)
            'requests' => User::isAdministrator() ? \app\components\association\MembershipRequests::count() : null,
            // soci per stato della quota (solo admin): al clic la lista Soci con lo stesso filtro
            'members' => User::isAdministrator() ? self::memberCounts() : null,
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

        $this->layout = 'auth';

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
        $message_log = Yii::t('app', 'User {user} has logged out.', [
            'user' => Yii::$app->user->identity->username,
        ]);
        Log::save(Yii::$app->controller->id, (Yii::$app->controller->action->id), $message_log);

        Yii::$app->user->logout();
        return $this->redirect(['site/login']);

    }

    /** Soci iscritti, in regola, in scadenza e scaduti: stessi filtri della lista Soci (MembersSearch). */
    private static function memberCounts(): array
    {
        $counts = [];
        foreach (['' => 'all', 'active' => 'active', 'expiring' => 'expiring', 'expired' => 'expired'] as $status => $key) {
            $counts[$key] = (int) (new MembersSearch())->search(['MembersSearch' => ['status' => $status]])->getTotalCount();
        }
        return $counts;
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

        $this->layout = 'auth';

        $model = new SignupForm();
        $consensus = new UserConsensus();
        $legal = [];
        foreach (['privacy', 'terms'] as $type) {
            $current = LegalDocuments::current($type);
            $legal[$type] = $current === null ? null : (int) $current->id;
        }

        $post = Yii::$app->request->post();

        if (isset($post['SignupForm']) && isset($post['UserConsensus'])) {
            if ($model->load(Yii::$app->request->post()) && $consensus->load(Yii::$app->request->post())) {
                $invalidConsent = false;
                foreach (['consenso_statuto', 'consenso_privacy', 'consenso_condizioni', 'consenso_marketing'] as $attribute) {
                    $value = $consensus->$attribute;
                    if ($attribute === 'consenso_marketing' && $value === null) $value = 0;
                    if (!in_array($value, [0, 1, '0', '1'], true)) {
                        $invalidConsent = true;
                        $consensus->addError($attribute, Yii::t('app', 'Scelta non valida.'));
                        $value = 0;
                    }
                    $consensus->$attribute = $value;
                }

                // SIP-040: il consenso vale per le versioni mostrate nel modulo; se nel frattempo ne è stata pubblicata una
                // nuova il modulo si ripresenta. Nessuna versione pubblicata = NULL (decisione utente B, 2026-11-26).
                $posted = Yii::$app->request->post('legal');
                foreach (['privacy' => 'privacy_document_id', 'terms' => 'terms_document_id'] as $type => $column) {
                    $shown = is_array($posted) && is_string($posted[$type] ?? null) ? $posted[$type] : '';
                    if ($shown !== (string) ($legal[$type] ?? '')) {
                        $invalidConsent = true;
                        $consensus->addError('consenso_' . ($type === 'terms' ? 'condizioni' : 'privacy'), Yii::t('app', 'The document has just been updated: read it again and confirm.'));
                    }
                    $consensus->$column = $legal[$type];
                }

                $connection = \Yii::$app->db;
                $transaction = $connection->beginTransaction();
                try {
                    if ($user = $model->signup()){
                        $consensus->user_id = $user->id;
                        $consensus->consenso_condizioni_pos = 0;
                        if (!$invalidConsent && $consensus->save() && self::signupEvents($consensus)){
                            // A delivered link must refer to committed user/consent rows.
                            $transaction->commit();
                            // SIP-030: agli admin solo dopo la verifica dell'email (actionActivate), mai per registrazioni non confermate

                            // invio la mail all'utente
                            $sent = sendMail::toUser($user, ['html' => 'newAccountUser', 'text' => 'newAccountUser-text'], 'Nuovo account');

                            $message_log = Yii::t('app', 'User {user} has signup succesfully.', [
                                'user' => $user->username,
                            ]);
                            Log::save(Yii::$app->controller->id, (Yii::$app->controller->action->id), $message_log);

                            Yii::$app->session->setFlash('success', $sent ? 'Utente registrato con successo' : 'Registrazione completata. Se non ricevi la mail, richiedi un nuovo link di attivazione dal login.');
                            return $this->redirect(['site/index']);
                        } else {
                            $transaction->rollback();
                            Yii::$app->session->setFlash('warning', Yii::t('app', 'Registrazione non completata. Controlla i dati e i consensi richiesti.'));
                        }
                    } else {
                        $transaction->rollback();
                        Yii::$app->session->setFlash('warning', Yii::t('app', 'Registrazione non completata. Controlla i dati e i consensi richiesti.'));
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
                    foreach ($error as $e)
                        $p .= '<p>' . Html::encode($e) . '</p>';
                }
                $errors = $consensus->getErrors();
                foreach ($errors as $id => $error) {
                    foreach ($error as $e)
                        $p .= '<p>' . Html::encode($e) . '</p>';
                }

                Yii::$app->session->setFlash('error', $p);
            }
        }

        return $this->render('signup', [
            'model' => $model,
            'consensus' => $consensus,
            'legal' => $legal,
        ]);
    }

    /** SIP-040 A2: eventi della registrazione (stessa transazione della riga dei consensi). */
    private static function signupEvents(UserConsensus $consensus): bool
    {
        $user = (int) $consensus->user_id;
        ConsentEvents::record($user, 'statute', 'given', null, 'signup');
        ConsentEvents::record($user, 'privacy', 'given', $consensus->privacy_document_id === null ? null : (int) $consensus->privacy_document_id, 'signup');
        ConsentEvents::record($user, 'terms', 'given', $consensus->terms_document_id === null ? null : (int) $consensus->terms_document_id, 'signup');
        ConsentEvents::record($user, 'marketing', (int) $consensus->consenso_marketing === 1 ? 'given' : 'refused', null, 'signup');
        return true;
    }

    /** SIP-040 A2: i miei consensi (solo il proprio account): stato, versioni, storico; marketing modificabile. */
    public function actionConsents($user = null)
    {
        $userId = (int) Yii::$app->user->id;
        $readOnly = false;
        if ($user !== null) {
            // admin: storico di un socio in sola lettura; per gli altri l'ID altrui non esiste
            if (!User::isAdministrator()) throw new \yii\web\NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
            $target = is_string($user) ? (int) Crypt::decrypt($user) : 0;
            if ($target < 1 || Users::findOne($target) === null) throw new \yii\web\NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
            $readOnly = $target !== $userId;
            $userId = $target;
        }
        $consensus = UserConsensus::find()->where(['user_id' => $userId])->orderBy(['id' => SORT_DESC])->one();
        return $this->render('consents', ['consensus' => $consensus, 'events' => ConsentEvents::history($userId), 'readOnly' => $readOnly]);
    }

    /** SIP-040 A2: dare o revocare il consenso marketing; un evento solo se il valore cambia. Mai altri consensi. */
    public function actionMarketing()
    {
        $value = Yii::$app->request->post('marketing');
        if (!in_array($value, ['0', '1'], true)) throw new BadRequestHttpException(Yii::t('app', 'Invalid request.'));
        $userId = (int) Yii::$app->user->id;
        $changed = Yii::$app->db->transaction(static function () use ($userId, $value): bool {
            $row = UserConsensus::find()->where(['user_id' => $userId])->orderBy(['id' => SORT_DESC])->one();
            if ($row === null || (int) $row->consenso_marketing === (int) $value) return false;
            $row->consenso_marketing = (int) $value;
            $row->timestamp_marketing = time();
            if (!$row->save(false)) throw new \RuntimeException('consent_not_saved');
            ConsentEvents::record($userId, 'marketing', $value === '1' ? 'given' : 'withdrawn', null, 'profile');
            return true;
        });
        if ($changed) Log::event($value === '1' ? 'Marketing consent given' : 'Marketing consent withdrawn', Log::TYPE_DATA);
        Yii::$app->session->setFlash('success', $value === '1' ? Yii::t('app', 'Marketing consent given.') : Yii::t('app', 'Marketing consent withdrawn.'));
        return $this->redirect(['consents']);
    }

    /** SIP-040: nuova accettazione delle condizioni in vigore (TermsGate); la versione accettata è quella mostrata. */
    public function actionAcceptTerms()
    {
        $userId = (int) Yii::$app->user->id;
        $pending = \app\components\legal\TermsGate::pending($userId);
        if ($pending === null) return $this->goHome();
        if (Yii::$app->request->isPost) {
            $shown = Yii::$app->request->post('document');
            $accepted = Yii::$app->request->post('accept') === '1';
            if ($accepted && is_string($shown) && preg_match('/^[1-9][0-9]{0,9}$/D', $shown)
                && \app\components\legal\TermsGate::accept($userId, (int) $shown)) {
                Log::event(sprintf('Terms of use v%d accepted', (int) $pending->version), Log::TYPE_DATA);
                return $this->goHome();
            }
            Yii::$app->session->setFlash('warning', Yii::t('app', 'To continue, read the terms of use and confirm that you accept them.'));
            return $this->redirect(['accept-terms']);
        }
        $this->layout = 'auth';
        return $this->render('accept-terms', ['document' => $pending]);
    }

    /**
     * Activate registered user.
     *
     * @return Response|string
     */
    public function actionActivate($id = "", $sign = "")
    {
        $this->layout = "auth";
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
            if (MemberProfiles::isPending((int) $user->id)) {
                // SIP-030: email verificata, ora la richiesta arriva agli admin per l'approvazione
                sendMail::toAdmins($user, 'newAccountAdmin', Yii::t('app', 'New membership request'));
                Yii::$app->session->setFlash("success", Yii::t("app", "Email confirmed: your membership request is now awaiting approval. You will receive an email."));
                return $this->redirect(["site/login"]);
            }
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
        $this->layout = 'auth';

        $model = new PasswordResetRequestForm();
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            $model->sendEmail();

            $message_log = 'Password reset request received.';
            Log::save(Yii::$app->controller->id, (Yii::$app->controller->action->id), $message_log);
            
            Yii::$app->session->setFlash('success', Yii::t('app', 'Check your email for further instructions.'));
            return $this->redirect(['/site/login']);
        }

        return $this->render('requestPasswordReset', [
            'model' => $model,
        ]);
    }

    public function actionResendActivation()
    {
        $this->layout = 'auth';
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
        $this->layout = 'auth';

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
