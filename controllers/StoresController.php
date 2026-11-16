<?php

namespace app\controllers;

use Yii;
use app\models\Merchants;
use app\models\Storesettings;
use app\models\Stores;
use app\models\search\StoresSearch;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\BadRequestHttpException;
use yii\filters\VerbFilter;
use yii\filters\AccessControl;
use yii\helpers\ArrayHelper;
use yii\helpers\Json;
use yii\helpers\Url;

use app\components\Crypt;
use app\components\User;
use app\components\Log;
use app\components\BTCPayServer;
use app\components\btcpay\provisioning\ProvisioningOutbox;

use app\models\Settings;
use app\models\Pos;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use yii\helpers\Html;

/**
 * StoresController implements the CRUD actions for Stores model.
 */
class StoresController extends Controller
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
                            'export',
                            'create',
                            'update-general',
                            'update-rates',
                            'update-checkout',
                            'update-criteria',
                            'update-webhook',
                            'delete',
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
                            'view',
                            'create',
                            'update-general',
                            'delete',
                            'export',
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
     * Lists all Stores models.
     * @return mixed
     */
    public function actionIndex()
    {
        $searchModel = new StoresSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);
        if (User::isMerchant()) {
            $dataProvider->query->andwhere(['=', 'stores.merchant_id', User::getMerchantId()]);
        }
        $dataProvider->sort->defaultOrder = ['description' => SORT_ASC];

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
            'queryParams' => Json::encode(Yii::$app->request->queryParams),
        ]);
    }

    /**
     * Displays a single Stores model.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionView($id)
    {
        $model = $this->findModel(Crypt::decrypt($id));

        if (!$this->linked($model)) {
            return $this->render('view', ['model' => $model, 'rates' => 0, 'linked' => false, 'ttsWallet' => $this->ttsWallet($model)]);
        }
        $settings = (object) ArrayHelper::map(Settings::find()->all(), 'code', 'value');
        $BTCPayServer = new BTCPayServer($settings->btcpayHost, $settings->btcpayApiKey);

        // recupero il rate da exchange
        $storeId = $model->storesettings->bps_storeid;
        $defaultCurrency = $model->storesettings->defaultPaymentMethod . '_' . $model->storesettings->defaultCurrency;
        $rates = $BTCPayServer->getRates($storeId, $defaultCurrency);
        
        if (isset($rates['statusCode']) && $rates['statusCode'] == 200){
            $value = (empty($rates->getData()[0]['errors']) ? $rates->getData()[0]['rate'] : $rates->getData()[0]['errors']) . chr(32) . $model->storesettings->defaultCurrency;
        } else {
            $value = 0;
        }

        return $this->render('view', [
            'model' => $model,
            'rates' => $value,
            'linked' => true,
            'ttsWallet' => $this->ttsWallet($model),
        ]);
    }

    /**
     * Creates a new Stores model.
     * If creation is successful, the browser will be redirected to the 'view' page.
     * @return mixed
     */
    public function actionCreate() 
    {
        // Verifica che esista l'esercente 
        if (User::isMerchant()){
            $verifyMerchant = Merchants::find()->andWhere(['user_id' => Yii::$app->user->identity->id])->one();

            if (null === $verifyMerchant){
                Yii::$app->session->setFlash('warning', Yii::t('app', 'Prima di poter creare un negozio devi inserire le tue informazioni di esercente.'));
                return $this->redirect(['merchants/create']);
            }
        }

        $model = new Stores();

        $data = Yii::$app->request->post('Stores');
        // Solo valori scalari: un array resterebbe nel model e romperebbe il form al nuovo rendering.
        if ($data !== null && (!is_array($data) || array_filter($data, static fn($value) => !is_scalar($value)) !== [])) {
            throw new BadRequestHttpException(Yii::t('app', 'Richiesta non valida.'));
        }
        if ($model->load(Yii::$app->request->post())) {
            // Il merchant crea negozi solo per sé: il merchant_id inviato dal browser non conta.
            if (!User::isAdministrator()) $model->merchant_id = User::getMerchantId();
            $model->description = trim((string) $model->description);
            $merchant = Merchants::findOne(['id' => (int) $model->merchant_id]);
            if ($model->description === '') {
                $model->addError('description', Yii::t('app', 'Descrizione obbligatoria.'));
            } elseif ($merchant === null) {
                $model->addError('merchant_id', Yii::t('app', 'Merchant non valido.'));
            } else {
                foreach (['email', 'phone', 'mobile', 'addressStreet', 'addressNumberHouse', 'addressCity', 'addressZip', 'addressProvince', 'addressCountry'] as $field) {
                    $model->$field = $merchant->$field;
                }
                // Il negozio nasce solo in Napay; il collegamento a BTCPay (store e app POS) lo crea il provisioning
                // SIP-022 B1 in modo asincrono, quindi BTCPay assente o lento non blocca più la creazione.
                $transaction = Yii::$app->db->beginTransaction();
                try {
                    if ($model->save()) {
                        $storesettings = $this->defaultSettings($model);
                        if ($storesettings->save()) {
                            // Lavoro BTCPay accodato nella stessa transazione (se il provisioning è attivo).
                            ProvisioningOutbox::enqueueStore(Yii::$app->db, (int) $model->id, (int) $model->merchant_id, (int) Yii::$app->user->id);
                            $transaction->commit();
                            Yii::$app->session->setFlash('success', Yii::t('app', 'Negozio creato. Pagamenti BTC da configurare: il collegamento a BTCPay verrà creato automaticamente.'));
                            Log::save(Yii::$app->controller->id, Yii::$app->controller->action->id, Yii::t('app', 'User {user} has created a new {item}: {itemname}', [
                                'user' => Yii::$app->user->identity->username, 'item' => Yii::$app->controller->id, 'itemname' => $model->description,
                            ]));
                            return $this->redirect(['view', 'id' => Crypt::encrypt($model->id)]);
                        }
                        Yii::$app->session->setFlash('warning', Yii::t('app', 'Impostazioni del negozio non salvate. Riprova.'));
                    }
                    $transaction->rollBack();
                } catch (\Throwable $e) {
                    if ($transaction->isActive) $transaction->rollBack();
                    // solo la classe: messaggi e query possono contenere dati
                    Yii::error('Store creation failed: ' . get_class($e), __METHOD__);
                    Yii::$app->session->setFlash('warning', Yii::t('app', 'Negozio non creato. Riprova.'));
                }
            }
        }

        $merchants_list = ArrayHelper::map(Merchants::find()->all(), 'id', 'description');
        asort($merchants_list);

        return $this->render('create', [
            'model' => $model,
            'merchants_list' => $merchants_list,
        ]);
    }

    /**
     * Updates an existing Stores model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionUpdateGeneral($id)
    {
        $model = $this->findModel(Crypt::decrypt($id));
        if (!$this->linked($model)) return $this->notLinked($model);
        $store = $model->storesettings;

        if ($store->load(Yii::$app->request->post())){
            // Begin a database transaction
            $transaction = Yii::$app->db->beginTransaction();

            try {
                if ($store->save()){
                    $storesettings = [
                        "name" => $model->description,
                        "website" => $store->website,
                        "defaultCurrency" => $store->defaultCurrency,
                        "invoiceExpiration" => (int) $store->invoiceExpiration,
                        "displayExpirationTimer" => $store->displayExpirationTimer,
                        "monitoringExpiration" => $store->monitoringExpiration,
                        "speedPolicy" => $store->speedPolicy,
                        "lightningDescriptionTemplate" => $store->lightningDescriptionTemplate,
                        "paymentTolerance" => $store->paymentTolerance,
                        "anyoneCanCreateInvoice" => $store->anyoneCanCreateInvoice,
                        "requiresRefundEmail" => $store->requiresRefundEmail,
                        "checkoutType" => $store->checkoutType,
                        "receipt" => json_decode($store->receipt, true),
                        "lightningAmountInSatoshi" => $store->lightningAmountInSatoshi,
                        "lightningPrivateRouteHints" => $store->lightningPrivateRouteHints,
                        "onChainWithLnInvoiceFallback" => $store->onChainWithLnInvoiceFallback,
                        "redirectAutomatically" => $store->redirectAutomatically,
                        "showRecommendedFee" => $store->showRecommendedFee,
                        "recommendedFeeBlockTarget" => $store->recommendedFeeBlockTarget,
                        "defaultLang" => $store->defaultLang,
                        'customLogo' => Url::to('@web/bundles/site/images/logopos.png', true),
                        'customCSS' => Url::to('@web/bundles/site/css/checkout.css', true),
                        "htmlTitle" => $store->htmlTitle,
                        "networkFeeMode" => $store->networkFeeMode,
                        "payJoinEnabled" => $store->payJoinEnabled,
                        "lazyPaymentMethods" => $store->lazyPaymentMethods,
                        "defaultPaymentMethod" => $store->defaultPaymentMethod,
                    ];

                    $settings = (object) ArrayHelper::map(Settings::find()->all(), 'code', 'value');

                    // inizializzo la classe BTCPayServer
                    $BTCPayServer = new BTCPayServer($settings->btcpayHost, $settings->btcpayApiKey);

                    $result = $BTCPayServer->updateStore($store->bps_storeid, $storesettings);

                    if (!is_array($result)) {
                        // Commit the transaction
                        $transaction->commit();
                        Yii::$app->session->setFlash('success', Yii::t('app', 'Negozio aggiornato correttamente'));

                        $message_log = Yii::t('app', 'User {user} has updated general {item} settings: {itemname}', [
                            'user' => Yii::$app->user->identity->username,
                            'item' => Yii::$app->controller->id,
                            'itemname' => $model->description
                        ]);
                        Log::save(Yii::$app->controller->id, (Yii::$app->controller->action->id), $message_log);

                        return $this->redirect(['view', 'id' => Crypt::encrypt($model->id)]);
                    } else {
                        Yii::$app->session->setFlash('error', Yii::t('app', 'ERRORE BtcPay: Impossibile aggiornare il negozio'));
                        $transaction->rollBack();
                    }
                } else {
                    Yii::$app->session->setFlash('error', Yii::t('app', 'ERRORE: Impossibile aggiornare il negozio'));
                    $transaction->rollBack();
                }

            } catch (\Exception $e) {
                $transaction->rollBack();
                Yii::$app->session->setFlash('error', Yii::t('app', 'ERRORE: ' . "<pre>" . print_r($e->getMessage(), true) . "</pre>"));
            }

        } else {
            Yii::$app->session->setFlash('error', Yii::t('app', 'ERRORE: ' . "<pre>" . print_r($store->getErrors(), true) . "</pre>"));

        }

        return $this->render('update', [
            'model' => $model,
            'merchants_list' => ArrayHelper::map(Merchants::find()->all(), 'id', 'description'),
            '_form' => '_form-general',
            'title' => Yii::t('app', 'Update Stores: {name}', [
                'name' => $model->storesettings->bps_storeid,
            ]),
        ]);
    }

    /**
     * Updates an existing Stores model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionUpdateRates($id)
    {
        $model = $this->findModel(Crypt::decrypt($id));
        if (!$this->linked($model)) return $this->notLinked($model);
        $store = $model->storesettings;

        if ($store->load(Yii::$app->request->post())) {
            // Begin a database transaction
            $transaction = Yii::$app->db->beginTransaction();

            try {
                $store->save();

                $params = [
                    "spread" => $store->spread,
                    "preferredSource" => $store->preferredSource,
                    "isCustomScript" => false,
                    "effectiveScript" => '',
                ];

                $settings = (object) ArrayHelper::map(Settings::find()->all(), 'code', 'value');                
                // inizializzo la classe BTCPayServer
                $BTCPayServer = new BTCPayServer($settings->btcpayHost, $settings->btcpayApiKey);
                
                $result = $BTCPayServer->updateRates($store->bps_storeid, $params);

                if (!is_array($result)) {
                    // Commit the transaction
                    $transaction->commit();
                    
                    Yii::$app->session->setFlash('success', Yii::t('app', 'Negozio aggiornato correttamente'));

                    $message_log = Yii::t('app', 'User {user} has updated rates {item} settings: {itemname}', [
                        'user' => Yii::$app->user->identity->username,
                        'item' => Yii::$app->controller->id,
                        'itemname' => $model->description
                    ]);
                    Log::save(Yii::$app->controller->id, (Yii::$app->controller->action->id), $message_log);
                    return $this->redirect(['view', 'id' => Crypt::encrypt($model->id)]);
                }

            } catch (\Exception $e) {
                // Roll back the transaction if an error occurred
                $transaction->rollBack();
                Yii::$app->session->setFlash('error', Yii::t('app', 'ERRORE: ' . "<pre>" . print_r($e->getMessage(), true) . "</pre>"));
            }

        }
        return $this->render('update', [
            'model' => $model,
            'merchants_list' => ArrayHelper::map(Merchants::find()->all(), 'id', 'description'),
            '_form' => '_form-rates',
            'title' => Yii::t('app', 'Update Stores: {name}', [
                'name' => $model->storesettings->bps_storeid,
            ]),
        ]);

    }

    /**
     * Updates an existing Stores model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionUpdateCheckout($id)
    {
        $model = $this->findModel(Crypt::decrypt($id));
        if (!$this->linked($model)) return $this->notLinked($model);
        $store = $model->storesettings;

        if ($store->load(Yii::$app->request->post())) {
            $post = Yii::$app->request->post();

            $receipt = json_encode([
                'enabled' => (int) $post['Storesettings']['receipt_enabled'],
                'showPayments' => (int) $post['Storesettings']['receipt_showPayments'],
                'showQR' => (int) $post['Storesettings']['receipt_showQR'],
            ],);
            $store->receipt = $receipt;

            // Begin a database transaction
            $transaction = Yii::$app->db->beginTransaction();

            try {
                if ($model->save() && $store->save()) {
                    $storesettings = [
                        "name" => $model->description, // obbligatorio
                        "requiresRefundEmail" => (int) $store->requiresRefundEmail,
                        "receipt" => json_decode($store->receipt, true),
                    ];

                 

                    $settings = (object) ArrayHelper::map(Settings::find()->all(), 'code', 'value');

                    // inizializzo la classe BTCPayServer
                    $BTCPayServer = new BTCPayServer($settings->btcpayHost, $settings->btcpayApiKey);
                    $result = $BTCPayServer->updateStore($store->bps_storeid, $storesettings);

                    if (!is_array($result)) {
                        // Commit the transaction
                        $transaction->commit();
                        Yii::$app->session->setFlash('success', Yii::t('app', 'Negozio aggiornato correttamente'));

                        $message_log = Yii::t('app', 'User {user} has updated checkout {item} settings: {itemname}', [
                            'user' => Yii::$app->user->identity->username,
                            'item' => Yii::$app->controller->id,
                            'itemname' => $model->description
                        ]);
                        Log::save(Yii::$app->controller->id, (Yii::$app->controller->action->id), $message_log);

                        return $this->redirect(['view', 'id' => Crypt::encrypt($model->id)]);
                    } else {
                        Yii::$app->session->setFlash('error', Yii::t('app', 'ERRORE BtcPay: Impossibile aggiornare il negozio'));
                        $transaction->rollBack();
                    }
                } else {
                    Yii::$app->session->setFlash('error', Yii::t('app', 'ERRORE: Impossibile aggiornare il negozio'));
                    $transaction->rollBack();
                }
            } catch (\Exception $e) {
                $transaction->rollBack();
                Yii::$app->session->setFlash('error', Yii::t('app', 'ERRORE: ' . "<pre>" . print_r($e->getMessage(), true) . "</pre>"));
            }
        } 
       

        return $this->render('update', [
            'model' => $model,
            'merchants_list' => ArrayHelper::map(Merchants::find()->all(), 'id', 'description'),
            '_form' => '_form-checkout',
            'title' => Yii::t('app', 'Modifica Aspetto del pagamento: {name}', [
                'name' => $model->storesettings->bps_storeid,
            ]),
        ]);
    }

    /**
     * Updates an existing Stores model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionUpdateCriteria($id)
    {
        $model = $this->findModel(Crypt::decrypt($id));
        if (!$this->linked($model)) return $this->notLinked($model);
        $store = $model->storesettings;
        
        if ($store->load(Yii::$app->request->post())) {
            $post = Yii::$app->request->post();

            $paymentMethodCriteria = json_encode([
                0 => [
                    'paymentMethod' => 'BTC', 
                    'currencyCode' => $store->defaultCurrency, 
                    'above' => (bool) $post['Storesettings']['paymentMethod_BTC_above'],
                    'amount' => $post['Storesettings']['paymentMethod_BTC_amount'], 
                ],
                1 => [
                    'paymentMethod' => 'BTC-LightningNetwork', 
                    'currencyCode' => $store->defaultCurrency, 
                    'above' => (bool) $post['Storesettings']['paymentMethod_LN_above'],
                    'amount' => $post['Storesettings']['paymentMethod_LN_amount'], 
                ],
                2 => [
                    'paymentMethod' => 'BTC-LNURLPAY', 
                    'currencyCode' => $store->defaultCurrency,
                    'above' => (bool) $post['Storesettings']['paymentMethod_LN_above'],
                    'amount' => $post['Storesettings']['paymentMethod_LN_amount'], 
                ],
            ]);
          
            $store->paymentMethodCriteria = $paymentMethodCriteria;

            // Begin a database transaction
            $transaction = Yii::$app->db->beginTransaction();

            try {
                if ($store->save()) {
                    $storesettings = [
                        "name" => $model->description, // obbligatorio
                        "paymentMethodCriteria" => json_decode($store->paymentMethodCriteria)
                    ];

                    $settings = (object) ArrayHelper::map(Settings::find()->all(), 'code', 'value');

                    // inizializzo la classe BTCPayServer
                    $BTCPayServer = new BTCPayServer($settings->btcpayHost, $settings->btcpayApiKey);

                    $result = $BTCPayServer->updateStore($store->bps_storeid, $storesettings);

                    if (!is_array($result)) {
                        // Commit the transaction
                        $transaction->commit();
                        Yii::$app->session->setFlash('success', Yii::t('app', 'Negozio aggiornato correttamente'));
                        $message_log = Yii::t('app', 'User {user} has updated criteria {item} settings: {itemname}', [
                            'user' => Yii::$app->user->identity->username,
                            'item' => Yii::$app->controller->id,
                            'itemname' => $model->description
                        ]);
                        Log::save(Yii::$app->controller->id, (Yii::$app->controller->action->id), $message_log);

                        return $this->redirect(['view', 'id' => Crypt::encrypt($model->id)]);
                    } else {
                        Yii::$app->session->setFlash('error', Yii::t('app', 'ERRORE BtcPay: '. $result['message']));
                        $transaction->rollBack();
                    }
                } else {
                    Yii::$app->session->setFlash('error', Yii::t('app', 'ERRORE: Impossibile aggiornare il negozio'));
                    $transaction->rollBack();
                }
            } catch (\Exception $e) {
                $transaction->rollBack();
                Yii::$app->session->setFlash('error', Yii::t('app', 'ERRORE: ' . "<pre>" . print_r($e->getMessage(), true) . "</pre>"));
            }
        }
        

        return $this->render('update', [
            'model' => $model,
            'merchants_list' => ArrayHelper::map(Merchants::find()->all(), 'id', 'description'),
            '_form' => '_form-criteria',
            'title' => Yii::t('app', 'Modifica Criteri di pagamento: {name}', [
                'name' => $model->storesettings->bps_storeid,
            ]),
        ]);
    }

    /**
     * Updates an existing Stores model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionUpdateWebhook($id)
    {
        $store = $this->findModel(Crypt::decrypt($id));
        if (!$this->linked($store)) return $this->notLinked($store);
        $webhookModel = $store->webhook;

        if ($webhookModel->load(Yii::$app->request->post())) {
            // Begin a database transaction
            $transaction = Yii::$app->db->beginTransaction();

            try {
                if ($webhookModel->save()) {
                    $settings = (object) ArrayHelper::map(Settings::find()->all(), 'code', 'value');

                    // inizializzo la classe BTCPayServer
                    $BTCPayServer = new BTCPayServer($settings->btcpayHost, $settings->btcpayApiKey);

                    /**
                     * Adesso impostiamo i WebHooks 
                     */
                    $webHook = $BTCPayServer->setWebhook();

                    $webHookResult = $webHook->updateWebhook(
                        $store->storesettings->bps_storeid,
                        $webhookModel->url,
                        $webhookModel->webhookId,
                        null
                    );

                    if (null !== $webHookResult) {
                        // Commit the transaction
                        $transaction->commit();
                        Yii::$app->session->setFlash('success', Yii::t('app', 'Negozio aggiornato correttamente'));
                        $message_log = Yii::t('app', 'User {user} has updated webhook {item} settings: {itemname}', [
                            'user' => Yii::$app->user->identity->username,
                            'item' => Yii::$app->controller->id,
                            'itemname' => $store->description
                        ]);
                        Log::save(Yii::$app->controller->id, (Yii::$app->controller->action->id), $message_log);

                        return $this->redirect(['view', 'id' => Crypt::encrypt($store->id)]);
                    } else {
                        Yii::$app->session->setFlash('error', Yii::t('app', 'ERRORE BtcPay: ' . $result['message']));
                        $transaction->rollBack();
                    }
                } else {
                    Yii::$app->session->setFlash('error', Yii::t('app', 'ERRORE: Impossibile aggiornare il negozio'));
                    $transaction->rollBack();
                }
            } catch (\Exception $e) {
                $transaction->rollBack();
                Yii::$app->session->setFlash('error', Yii::t('app', 'ERRORE: ' . "<pre>" . print_r($e->getMessage(), true) . "</pre>"));
            }
        }

        return $this->render('update', [
            'model' => $store,
            'merchants_list' => ArrayHelper::map(Merchants::find()->all(), 'id', 'description'),
            '_form' => '_form-webhook',
            'title' => Yii::t('app', 'Modifica Webhook: {name}', [
                'name' => $store->storesettings->bps_storeid,
            ]),
        ]);
    }
    
    /**
     * Deletes an existing Stores model.
     * If deletion is successful, the browser will be redirected to the 'index' page.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionDelete($id)
    {
        $closed_at = new \DateTime('now');
        $close_date = $closed_at->format('Y-m-d');

        // storicizza il negozio
        $model = $this->findModel(Crypt::decrypt($id));
        $model->close_date = $close_date;
        $model->historical = 1;
        $model->save();

        // NON ELIMINO IL NEGOZIO SU BTCPAYWERVER
        // MA LO RICHIAMO DELETED (solo se il negozio è già collegato a BTCPay)
        if ($this->linked($model)) {
        $store = $model->storesettings;
        $settings = (object) ArrayHelper::map(Settings::find()->all(), 'code', 'value');

        $params = [
            "name" => $model->description.'-DELETED',
            "website" => $store->website,
            "defaultCurrency" => $store->defaultCurrency,
            "invoiceExpiration" => (int) $store->invoiceExpiration,
            "displayExpirationTimer" => $store->displayExpirationTimer,
            "monitoringExpiration" => $store->monitoringExpiration,
            "speedPolicy" => $store->speedPolicy,
            "lightningDescriptionTemplate" => $store->lightningDescriptionTemplate,
            "paymentTolerance" => $store->paymentTolerance,
            "anyoneCanCreateInvoice" => $store->anyoneCanCreateInvoice,
            "requiresRefundEmail" => $store->requiresRefundEmail,
            "checkoutType" => $store->checkoutType,
            "receipt" => json_decode($store->receipt, true),
            "lightningAmountInSatoshi" => $store->lightningAmountInSatoshi,
            "lightningPrivateRouteHints" => $store->lightningPrivateRouteHints,
            "onChainWithLnInvoiceFallback" => $store->onChainWithLnInvoiceFallback,
            "redirectAutomatically" => $store->redirectAutomatically,
            "showRecommendedFee" => $store->showRecommendedFee,
            "recommendedFeeBlockTarget" => $store->recommendedFeeBlockTarget,
            "defaultLang" => $store->defaultLang,
            'customLogo' => Url::to('@web/bundles/site/images/logopos.png', true),
            'customCSS' => Url::to('@web/bundles/site/css/checkout.css', true),
            "htmlTitle" => $store->htmlTitle,
            "networkFeeMode" => $store->networkFeeMode,
            "payJoinEnabled" => $store->payJoinEnabled,
            "lazyPaymentMethods" => $store->lazyPaymentMethods,
            "defaultPaymentMethod" => $store->defaultPaymentMethod,
        ];

        // inizializzo la classe BTCPayServer
        $BTCPayServer = new BTCPayServer($settings->btcpayHost, $settings->btcpayApiKey);

        $result = $BTCPayServer->updateStore($store->bps_storeid, $params);

        // storicizza tutti i pos collegati
        $allPos = Pos::find()->byStoreId($model->id)->all();
        $client = $BTCPayServer->setPos();
        foreach ($allPos as $pos) {
            $pos->close_date = $close_date;
            $pos->historical = 1;
            $pos->save();

            // IL POS DA BTCPAYSERVER VIENE RIMOSSO
            $removePos = $client->removePos($pos->possettings->sin);

        }
        }

        $message_log = Yii::t('app', 'User {user} has deleted {item}: {itemname}', [
            'user' => Yii::$app->user->identity->username,
            'item' => Yii::$app->controller->id,
            'itemname' => $model->description
        ]);
        Log::save(Yii::$app->controller->id, (Yii::$app->controller->action->id), $message_log);

        
        
        
        return $this->redirect(['index']);

        //         Yii::$app->session->setFlash('success', Yii::t('app', 'Negozio eliminato correttamente'));

    }
    /**
     * Esporta la selezione in un file excel
     */
    public function actionExport()
    {
        $queryParams = Json::decode($_POST['queryParams']);

        $searchModel = new StoresSearch();
        $dataProvider = $searchModel->search($queryParams);
        $dataProvider->pagination->pageSize = false;
        if (User::isMerchant()) {
            $dataProvider->query->andwhere(['=', 'stores.merchant_id', Yii::$app->user->identity->merchant_id]);
        }

        $allModels = $dataProvider->getModels();

        // inizializzo la classe Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Attributi da scaricare
        $attributeLabels = [
            'id' => Yii::t('app', 'ID'),
            'merchant.description' => Yii::t('app', 'Esercente'),

            'description' => Yii::t('app', 'Descrizione'),
            'swg_storeId' => Yii::t('app', 'Codice Negozio'),
            'email' => Yii::t('app', 'Email'),
            'phone' => Yii::t('app', 'Telefono'),
            'mobile' => Yii::t('app', 'Mobile'),
            'addressStreet' => Yii::t('app', 'Indirizzo'),
            'addressNumberHouse' => Yii::t('app', 'Civico'),
            'addressCity' => Yii::t('app', 'Città'),
            'addressZip' => Yii::t('app', 'Zip'),
            'addressProvince' => Yii::t('app', 'Provincia'),
            'addressCountry' => Yii::t('app', 'Nazione'),

            // attributi per settings
            'storesettings.bps_storeid' => Yii::t('app', 'ID Negozio'),
            'storesettings.defaultCurrency' => Yii::t('app', 'Valuta predefinita'),
            'storesettings.invoiceExpiration' => Yii::t('app', 'Scadenza transazione'),
            'storesettings.displayExpirationTimer' => Yii::t('app', 'Mostra Timer di scadenza'),
            'storesettings.monitoringExpiration' => Yii::t('app', 'Monitoraggio della scadenza'),
            'storesettings.speedPolicy' => Yii::t('app', 'Politica di velocità delle transazioni'),
            'storesettings.requiresRefundEmail' => Yii::t('app', 'Richiedi mail per il rimborso'),
            'storesettings.receipt' => Yii::t('app', 'Ricevuta'),
            'storesettings.paymentMethodCriteria' => Yii::t('app', 'Criteri dei metodi di pagamento'),

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
                $sheet->setCellValue($this->getCellFromColnum($col) . $row, trim($writeText ?? ''), null);
                $col++;
            }
            $row++;
        }

        // output the file
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $date = date('Y/m/d H:i:s', time());
        $filename = $date . '-stores.xlsx';
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
     * Finds the Stores model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return Stores the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    /** Impostazioni iniziali del negozio (quelle che il vecchio flusso inviava a BTCPay alla creazione). */
    private function defaultSettings(Stores $model): Storesettings
    {
        return new Storesettings([
            'store_id' => $model->id,
            'bps_storeid' => null, // al momento
            'defaultCurrency' => 'EUR',
            'invoiceExpiration' => 900,
            'displayExpirationTimer' => 300,
            'monitoringExpiration' => 3600,
            'speedPolicy' => 'MediumSpeed', // -> 1 confirmation 'HighSpeed' 0,
            'lightningDescriptionTemplate' => 'Paid to {StoreName} (Order ID: {OrderId})',
            'paymentTolerance' => 0,
            'anyoneCanCreateInvoice' => 0,
            'requiresRefundEmail' => 1,
            'checkoutType' => 'V1',
            'receipt' => json_encode([
                'enabled' => 1,
                'showQR' => 1,
                'showPayments' => 1
            ]),
            'lightningAmountInSatoshi' => 0,
            'lightningPrivateRouteHints' => 0,
            'onChainWithLnInvoiceFallback' => 0,
            'redirectAutomatically' => 0,
            'showRecommendedFee' => 1,
            'recommendedFeeBlockTarget' => 1,
            'defaultLang' => 'it-IT',
            'customLogo' => Url::to('@web/bundles/site/images/logopos.png', true),
            'customCSS' => Url::to('@web/bundles/site/css/checkout.css', true),
            'htmlTitle' => Html::encode($model->description),
            'networkFeeMode' => 'Always',
            'payJoinEnabled' => 0,
            'lazyPaymentMethods' => 0,
            'defaultPaymentMethod' => 'BTC',
            // salvo i criteria in DB, ma non invio request tramite API
            'paymentMethodCriteria' => json_encode([
                0 => ['paymentMethod' => 'BTC', 'currencyCode' => 'EUR', 'amount' => 0, 'above' => 1],
                1 => ['paymentMethod' => 'BTC-LightningNetwork', 'currencyCode' => 'EUR', 'amount' => 0, 'above' => 1],
                2 => ['paymentMethod' => 'BTC-LNURLPAY', 'currencyCode' => 'EUR', 'amount' => 0, 'above' => 1],
            ]),

        ]);
    }

    /** Indirizzo wallet TTS del negozio (SIP-030), null se non impostato. */
    private function ttsWallet(Stores $model): ?string
    {
        $address = Yii::$app->db->createCommand('SELECT address FROM {{%store_tts_wallets}} WHERE store_id=:s', [':s' => (int) $model->id])->queryScalar();
        return $address === false ? null : $address;
    }

    /** Negozio già collegato a uno store BTCPay. Prima del provisioning le pagine BTCPay non contattano il server. */
    private function linked(Stores $model): bool
    {
        return $model->storesettings !== null && !empty($model->storesettings->bps_storeid);
    }

    private function notLinked(Stores $model)
    {
        Yii::$app->session->setFlash('info', Yii::t('app', 'Negozio non ancora collegato a BTCPay: le impostazioni dei pagamenti BTC saranno disponibili dopo il collegamento automatico.'));
        return $this->redirect(['view', 'id' => Crypt::encrypt($model->id)]);
    }

    protected function findModel($id)
    {
        // Fuori dallo scope = inesistente: solo l'admin vede i negozi di tutti i merchant.
        $model = Stores::findOne($id);
        if ($model !== null && (User::isAdministrator() || (int) $model->merchant_id === User::getMerchantId())) {
            return $model;
        }

        throw new NotFoundHttpException(Yii::t('app', 'The requested model does not exist.'));
    }
}
