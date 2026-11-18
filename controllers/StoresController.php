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
use app\components\btcpay\provisioning\ProvisioningOutbox;
use app\components\btcpay\StoresBtcpay;
use app\components\btcpay\provisioning\ProvisioningStatus;
use app\components\btcpay\StoreSettingsPayload;
use app\components\btcpay\XpubAddresses;


use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * StoresController implements the CRUD actions for Stores model.
 */
class StoresController extends Controller
{
    /** Valute selezionabili per il negozio; il resto delle impostazioni BTCPay è fisso. */
    public const CURRENCIES = ['EUR' => 'EUR', 'USD' => 'USD'];

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
                    'wallet-preview' => ['POST'],
                    'wallet-set' => ['POST'],
                    'wallet-remove' => ['POST'],
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
                            'delete',
                            // SIP-022 xpub: dove arrivano i BTC lo decide solo il merchant (l'admin vede la chiave abbreviata)
                            'wallet-preview',
                            'wallet-set',
                            'wallet-remove',
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
            return $this->render('view', ['model' => $model, 'rates' => 0, 'linked' => false, 'ttsWallet' => $this->ttsWallet($model), 'btcWallet' => $this->btcWallet($model)]);
        }
        // cambio solo per i negozi v1 (chiave ponte); i negozi del provisioning non chiamano BTCPay dal web
        $BTCPayServer = $this->legacy($model) ? StoresBtcpay::client() : null;

        // recupero il rate da exchange
        $storeId = $model->storesettings->bps_storeid;
        $defaultCurrency = $model->storesettings->defaultPaymentMethod . '_' . $model->storesettings->defaultCurrency;
        $rates = $BTCPayServer === null ? null : $BTCPayServer->getRates($storeId, $defaultCurrency);
        
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
            'btcWallet' => $this->btcWallet($model),
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
                            ProvisioningOutbox::enqueueStoreSettings(Yii::$app->db, (int) $model->id, (int) $model->merchant_id, (int) Yii::$app->user->id);
                            $transaction->commit();
                            Yii::$app->session->setFlash('success', Yii::t('app', 'Store created.'));
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
     * Nome, valuta e sito del negozio (SIP-030 punto 4, SIP-033): le altre impostazioni BTCPay sono fisse (StoreSettingsPayload::FIXED).
     * @param string $id ID cifrato
     */
    public function actionUpdateGeneral($id)
    {
        $model = $this->findModel(Crypt::decrypt($id));
        if (!$this->linked($model)) return $this->notLinked($model);
        $store = $model->storesettings;

        if (Yii::$app->request->isPost) {
            $postStore = Yii::$app->request->post('Stores');
            $postSettings = Yii::$app->request->post('Storesettings');
            $description = is_array($postStore) && is_string($postStore['description'] ?? null) ? trim($postStore['description']) : '';
            $currency = is_array($postSettings) && is_string($postSettings['defaultCurrency'] ?? null) ? $postSettings['defaultCurrency'] : '';
            $website = is_array($postSettings) && is_string($postSettings['website'] ?? null) ? trim($postSettings['website']) : '';
            $model->description = $description;
            $store->defaultCurrency = $currency;
            $store->website = $website === '' ? null : $website;
            if ($description === '') $model->addError('description', Yii::t('app', 'Description is required.'));
            if (!array_key_exists($currency, self::CURRENCIES)) $store->addError('defaultCurrency', Yii::t('app', 'Select a currency.'));
            // SIP-033: sito del negozio mostrato sulla pagina di pagamento BTCPay (facoltativo, http/https)
            if ($website !== '' && StoreSettingsPayload::url($website, true) === null) $store->addError('website', Yii::t('app', 'Enter a valid web address.'));
            else if ($website !== '') $store->website = StoreSettingsPayload::url($website, true);

            if (!$model->hasErrors() && !$store->hasErrors()) {
                $transaction = Yii::$app->db->beginTransaction();
                try {
                    $logUpdate = fn() => Log::save(Yii::$app->controller->id, Yii::$app->controller->action->id, Yii::t('app', 'User {user} has updated general {item} settings: {itemname}', [
                        'user' => Yii::$app->user->identity->username, 'item' => Yii::$app->controller->id, 'itemname' => $model->description,
                    ]));
                    if (!$model->save(true, ['description']) || !$store->save(true, ['defaultCurrency', 'website'])) {
                        Yii::$app->session->setFlash('error', Yii::t('app', 'Store not saved. Try again.'));
                    } elseif (!$this->legacy($model)) {
                        // SIP-022 passo 3: nessuna chiamata dal web, il worker allinea lo store con la chiave del merchant
                        ProvisioningOutbox::enqueueStoreSettings(Yii::$app->db, (int) $model->id, (int) $model->merchant_id, (int) Yii::$app->user->id);
                        $transaction->commit();
                        Yii::$app->session->setFlash('success', Yii::t('app', 'Store updated. BTCPay will be updated shortly.'));
                        $logUpdate();
                        return $this->redirect(['view', 'id' => Crypt::encrypt($model->id)]);
                    } else {
                        // negozio v1: chiave ponte (SIP-030 punto 4)
                        $BTCPayServer = StoresBtcpay::client();
                        $result = $BTCPayServer === null ? ['error' => 'not-configured'] : $BTCPayServer->updateStore($store->bps_storeid, $this->btcpayStoreSettings($model, $store));
                        if (!is_array($result)) {
                            $transaction->commit();
                            Yii::$app->session->setFlash('success', Yii::t('app', 'Store updated.'));
                            $logUpdate();
                            return $this->redirect(['view', 'id' => Crypt::encrypt($model->id)]);
                        }
                        Yii::$app->session->setFlash('error', Yii::t('app', 'BTCPay could not update the store. Try again later.'));
                    }
                    if ($transaction->isActive) $transaction->rollBack();
                } catch (\Throwable $e) {
                    if ($transaction->isActive) $transaction->rollBack();
                    // solo la classe: messaggi e query possono contenere dati
                    Yii::error('Store update failed: ' . get_class($e), __METHOD__);
                    Yii::$app->session->setFlash('error', Yii::t('app', 'Store not saved. Try again.'));
                }
            }
        }

        return $this->render('update', [
            'model' => $model,
            'merchants_list' => [],
            '_form' => '_form-general',
            'title' => Yii::t('app', 'Edit store: {name}', ['name' => $model->description]),
        ]);
    }

    /**
     * SIP-022 xpub, passo 1 di 2: il merchant incolla la chiave pubblica estesa del suo wallet e vede i primi indirizzi di
     * ricezione che BTCPay genererà, prima di confermare. Nessun salvataggio e nessuna chiamata a BTCPay qui.
     */
    public function actionWalletPreview($id)
    {
        $model = $this->findModel(Crypt::decrypt($id));
        if ($this->legacy($model) || $model->storesettings === null) return $this->walletUnavailable($model);
        $scheme = Yii::$app->request->post('xpub');
        try {
            $parsed = XpubAddresses::parse(is_string($scheme) ? $scheme : '', self::btcNetwork());
            $addresses = $parsed->receiveAddresses(5);
        } catch (\InvalidArgumentException $e) {
            Yii::$app->session->setFlash('error', self::xpubError($e->getMessage()));
            return $this->redirect(['view', 'id' => Crypt::encrypt($model->id), '#' => 'tab-wallet']);
        }
        return $this->render('wallet-confirm', ['model' => $model, 'scheme' => trim($scheme), 'addresses' => $addresses]);
    }

    /** SIP-022 xpub, passo 2 di 2: chiave confermata, salvata e inviata a BTCPay dal worker (stessa transazione). */
    public function actionWalletSet($id)
    {
        $model = $this->findModel(Crypt::decrypt($id));
        if ($this->legacy($model) || $model->storesettings === null) return $this->walletUnavailable($model);
        $scheme = Yii::$app->request->post('xpub');
        try {
            XpubAddresses::parse(is_string($scheme) ? $scheme : '', self::btcNetwork());
        } catch (\InvalidArgumentException $e) {
            Yii::$app->session->setFlash('error', self::xpubError($e->getMessage()));
            return $this->redirect(['view', 'id' => Crypt::encrypt($model->id), '#' => 'tab-wallet']);
        }
        return $this->saveWallet($model, trim($scheme), Yii::t('app', 'BTC wallet saved: BTCPay will use it shortly.'));
    }

    /** Wallet BTC rimosso: il negozio non riceve più pagamenti BTC on-chain finché non ne imposta un altro. */
    public function actionWalletRemove($id)
    {
        $model = $this->findModel(Crypt::decrypt($id));
        if ($this->legacy($model) || $model->storesettings === null) return $this->walletUnavailable($model);
        return $this->saveWallet($model, null, Yii::t('app', 'BTC wallet removed: BTC payments are disabled for this store.'));
    }

    private function saveWallet(Stores $model, ?string $scheme, string $done)
    {
        $transaction = Yii::$app->db->beginTransaction();
        try {
            Yii::$app->db->createCommand()->update('{{%storesettings}}', ['derivationScheme' => $scheme], ['store_id' => (int) $model->id])->execute();
            $queued = ProvisioningOutbox::enqueueStoreWallet(Yii::$app->db, (int) $model->id, (int) $model->merchant_id, (int) Yii::$app->user->id);
            $transaction->commit();
        } catch (\Throwable $e) {
            if ($transaction->isActive) $transaction->rollBack();
            Yii::error('Store wallet update failed: ' . get_class($e), __METHOD__);
            Yii::$app->session->setFlash('error', Yii::t('app', 'Store not saved. Try again.'));
            return $this->redirect(['view', 'id' => Crypt::encrypt($model->id), '#' => 'tab-wallet']);
        }
        Log::event(sprintf('Wallet BTC del negozio %d %s', (int) $model->id, $scheme === null ? 'rimosso' : 'impostato'), Log::TYPE_DATA);
        Yii::$app->session->setFlash($queued ? 'success' : 'warning', $queued ? $done : Yii::t('app', 'BTC wallet saved, but the BTCPay link is not active.'));
        return $this->redirect(['view', 'id' => Crypt::encrypt($model->id), '#' => 'tab-wallet']);
    }

    private function walletUnavailable(Stores $model)
    {
        Yii::$app->session->setFlash('warning', Yii::t('app', 'The BTC wallet of this store is managed directly on BTCPay.'));
        return $this->redirect(['view', 'id' => Crypt::encrypt($model->id)]);
    }

    /** Rete Bitcoin del server BTCPay (BTCPAY_BTC_NETWORK: mainnet, testnet o regtest; predefinita mainnet). */
    public static function btcNetwork(): string
    {
        $network = (string) getenv('BTCPAY_BTC_NETWORK');
        return in_array($network, XpubAddresses::NETWORKS, true) ? $network : 'mainnet';
    }

    /** Messaggio per il merchant dal codice di validazione (mai il valore inserito). */
    private static function xpubError(string $code): string
    {
        return match ($code) {
            'xpub_checksum' => Yii::t('app', 'The key contains a typo: check it and paste it again.'),
            'xpub_not_public' => Yii::t('app', 'This is not an extended public key. Never paste a private key: use only the xpub of your wallet.'),
            'xpub_network' => Yii::t('app', 'The key belongs to a different Bitcoin network than this server.'),
            default => Yii::t('app', 'Not a valid extended public key (xpub, ypub, zpub, tpub, upub or vpub).'),
        };
    }

    /** Impostazioni inviate a BTCPay (negozi v1): valori fissi salvati alla creazione, più nome e valuta scelti dal merchant. */
    private function btcpayStoreSettings(Stores $model, Storesettings $store): array
    {
        return StoreSettingsPayload::build((string) $model->description, $store->getAttributes(StoreSettingsPayload::COLUMNS));
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
        // archivia il negozio e disabilita i suoi terminali (un terminale disabilitato chiude anche l'apertura attiva);
        // negozi del provisioning: archiviazione dello store BTCPay tramite worker (SIP-022 passo 3b), stessa transazione
        $model = $this->findModel(Crypt::decrypt($id));
        $transaction = Yii::$app->db->beginTransaction();
        try {
            if (Yii::$app->db->createCommand()->update('{{%stores}}', ['archived_at' => time()], ['id' => (int) $model->id, 'archived_at' => null])->execute() !== 1) {
                throw new \UnexpectedValueException('store_not_archived');
            }
            $disabled = Yii::$app->db->createCommand()->update('{{%pos_terminals}}', ['is_enabled' => 0, 'updated_at' => time()],
                ['store_id' => (int) $model->id, 'is_enabled' => 1])->execute();
            if (!$this->legacy($model)) {
                ProvisioningOutbox::enqueueStoreArchive(Yii::$app->db, (int) $model->id, (int) $model->merchant_id, (int) Yii::$app->user->id);
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            if ($transaction->isActive) $transaction->rollBack();
            Yii::error('Store archive failed: ' . get_class($e), __METHOD__);
            Yii::$app->session->setFlash('error', Yii::t('app', 'Store not deleted. Try again.'));
            return $this->redirect(['view', 'id' => Crypt::encrypt($model->id)]);
        }
        if ($disabled > 0) Log::event(sprintf('Negozio %d eliminato: %d terminali POS disabilitati', (int) $model->id, $disabled), Log::TYPE_DATA);

        // negozi v1: su BTCPay lo store non si elimina, viene rinominato -DELETED (chiave ponte); i terminali v2 sono già disabilitati
        if ($this->legacy($model)) {
            $store = $model->storesettings;
            $BTCPayServer = StoresBtcpay::client();
            if ($BTCPayServer !== null) {
                $params = $this->btcpayStoreSettings($model, $store);
                $params['name'] = $model->description . '-DELETED';
                $BTCPayServer->updateStore($store->bps_storeid, $params);
            }
        }

        $message_log = Yii::t('app', 'User {user} has deleted {item}: {itemname}', [
            'user' => Yii::$app->user->identity->username,
            'item' => Yii::$app->controller->id,
            'itemname' => $model->description
        ]);
        Log::save(Yii::$app->controller->id, (Yii::$app->controller->action->id), $message_log);
        return $this->redirect(['index']);
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
    /** Impostazioni iniziali del negozio: copia locale degli standard (a BTCPay vanno sempre StoreSettingsPayload::FIXED). */
    private function defaultSettings(Stores $model): Storesettings
    {
        return new Storesettings([
            'store_id' => $model->id,
            'bps_storeid' => null, // al momento
            'defaultCurrency' => 'EUR',
            'invoiceExpiration' => 900,
            'displayExpirationTimer' => 300,
            'monitoringExpiration' => 86400,
            'speedPolicy' => 'MediumSpeed', // -> 1 confirmation 'HighSpeed' 0,
            'lightningDescriptionTemplate' => 'Paid to {StoreName} (Order ID: {OrderId})',
            'paymentTolerance' => 0,
            'anyoneCanCreateInvoice' => 0,
            'requiresRefundEmail' => 0,
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
            'showRecommendedFee' => 0,
            'recommendedFeeBlockTarget' => 0,
            'defaultLang' => 'it-IT',
            'customLogo' => Url::to('@web' . StoreSettingsPayload::DEFAULT_LOGO, true),
            'customCSS' => null,
            'htmlTitle' => StoreSettingsPayload::FIXED['htmlTitle'],
            'networkFeeMode' => 'Always',
            'payJoinEnabled' => 0,
            'lazyPaymentMethods' => 0,
            'defaultPaymentMethod' => StoreSettingsPayload::FIXED['defaultPaymentMethod'],
            // salvo i criteria in DB, ma non invio request tramite API
            'paymentMethodCriteria' => json_encode([
                0 => ['paymentMethod' => 'BTC', 'currencyCode' => 'EUR', 'amount' => 0, 'above' => 1],
                1 => ['paymentMethod' => 'BTC-LightningNetwork', 'currencyCode' => 'EUR', 'amount' => 0, 'above' => 1],
                2 => ['paymentMethod' => 'BTC-LNURLPAY', 'currencyCode' => 'EUR', 'amount' => 0, 'above' => 1],
            ]),

        ]);
    }

    /** Wallet BTC presente: xpub del merchant (SIP-022) oppure negozio v1 con il wallet gestito su BTCPay. */
    private function btcWallet(Stores $model): bool
    {
        $settings = $model->storesettings;
        return $settings !== null && ($this->legacy($model) || trim((string) $settings->derivationScheme) !== '');
    }

    /** Indirizzo wallet TTS del negozio (SIP-030), null se non impostato. */
    private function ttsWallet(Stores $model): ?string
    {
        $address = Yii::$app->db->createCommand('SELECT address FROM {{%store_tts_wallets}} WHERE store_id=:s', [':s' => (int) $model->id])->queryScalar();
        return $address === false ? null : $address;
    }

    /** Negozio collegato a uno store BTCPay: negozio v1 (ID store salvato) oppure creato dal provisioning (SIP-022). */
    private function linked(Stores $model): bool
    {
        return $this->legacy($model) || $this->provisioned($model);
    }

    /** Negozio v1: store BTCPay gestito dal web con la chiave ponte (SIP-030 punto 4). */
    private function legacy(Stores $model): bool
    {
        return $model->storesettings !== null && !empty($model->storesettings->bps_storeid);
    }

    /** Negozio collegato dal provisioning: lo store si aggiorna solo tramite il worker, con la chiave del merchant. */
    private function provisioned(Stores $model): bool
    {
        return $model->storesettings !== null && ProvisioningStatus::store((int) $model->id) === ProvisioningStatus::LINKED;
    }

    private function notLinked(Stores $model)
    {
        Yii::$app->session->setFlash('info', Yii::t('app', 'Store not linked to BTCPay yet: BTC payment settings will be available after the automatic link.'));
        return $this->redirect(['view', 'id' => Crypt::encrypt($model->id)]);
    }

    protected function findModel($id)
    {
        // Fuori dallo scope = inesistente: solo l'admin vede i negozi di tutti i merchant.
        $model = Stores::find()->where(['id' => $id, 'archived_at' => null])->one();
        if ($model !== null && (User::isAdministrator() || (int) $model->merchant_id === User::getMerchantId())) {
            return $model;
        }

        throw new NotFoundHttpException(Yii::t('app', 'The requested model does not exist.'));
    }
}
