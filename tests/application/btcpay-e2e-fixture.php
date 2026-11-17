<?php
// SIP-022 collaudo reale: fixture e lettura stato per tests/docker/btcpay-provisioning-e2e.sh (DB usa-e-getta).
//   --seed   due merchant PG qualificati (A, B), un negozio e un terminale ciascuno, lavori accodati come fa il web
//   --state  JSON dello stato locale del provisioning (mai credenziali o ciphertext)
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
use app\components\btcpay\provisioning\ProvisioningOutbox;
try {
    require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
    $app = new yii\console\Application(require $root . '/config/console.php');
    $db = $app->db;
    $instance = ProvisioningOutbox::instance();
    if ($instance === null || !hash_equals(hash('sha256', 'https://btcpay.local'), $instance)) exit(1);
    $merchants = [];
    foreach (['A' => 'Runtime company merchant', 'B' => 'Runtime company other'] as $label => $description) {
        $row = $db->createCommand('SELECT id, user_id FROM merchants WHERE description=:d', [':d' => $description])->queryOne();
        if ($row === false) exit(1);
        $merchants[$label] = ['id' => (int) $row['id'], 'owner' => (int) $row['user_id']];
    }
    if ($merchants['A']['owner'] === $merchants['B']['owner']) exit(1);

    if (($argv[1] ?? '') === '--seed') {
        $out = [];
        foreach ($merchants as $label => $m) {
            // gestore merchant: livello merchant (la fixture comune lascia "other" a livello socio)
            $db->createCommand('UPDATE users SET privilege_id=(SELECT id FROM privileges WHERE level=30 ORDER BY id LIMIT 1) WHERE id=:u', [':u' => $m['owner']])->execute();
            $db->createCommand()->upsert('member_profiles', ['user_id' => $m['owner'], 'is_corporate' => 1, 'denomination' => 'E2E PG ' . $label],
                ['is_corporate' => 1, 'denomination' => 'E2E PG ' . $label])->execute();
            $db->createCommand()->insert('stores', ['merchant_id' => $m['id'], 'description' => 'E2E negozio ' . $label, 'email' => 'e2e@example.invalid',
                'phone' => '', 'mobile' => '', 'addressStreet' => '', 'addressNumberHouse' => '', 'addressCity' => '', 'addressZip' => '',
                'addressProvince' => '', 'addressCountry' => ''])->execute();
            $store = (int) $db->lastInsertID;
            // impostazioni fisse come StoresController::defaultSettings (SIP-030 punto 4)
            $db->createCommand()->insert('storesettings', ['store_id' => $store, 'defaultCurrency' => 'EUR', 'invoiceExpiration' => 900, 'displayExpirationTimer' => 300,
                'monitoringExpiration' => 3600, 'speedPolicy' => 'MediumSpeed', 'lightningDescriptionTemplate' => 'Paid to {StoreName} (Order ID: {OrderId})',
                'paymentTolerance' => 0, 'anyoneCanCreateInvoice' => 0, 'requiresRefundEmail' => 1, 'checkoutType' => 'V1',
                'receipt' => json_encode(['enabled' => 1, 'showQR' => 1, 'showPayments' => 1]), 'lightningAmountInSatoshi' => 0, 'lightningPrivateRouteHints' => 0,
                'onChainWithLnInvoiceFallback' => 0, 'redirectAutomatically' => 0, 'showRecommendedFee' => 1, 'recommendedFeeBlockTarget' => 1, 'defaultLang' => 'it-IT',
                'customLogo' => 'https://napay.example.invalid/bundles/site/images/logopos.png', 'customCSS' => 'https://napay.example.invalid/bundles/site/css/checkout.css',
                'htmlTitle' => 'E2E negozio ' . $label, 'networkFeeMode' => 'Always', 'payJoinEnabled' => 0, 'lazyPaymentMethods' => 0, 'defaultPaymentMethod' => 'BTC'])->execute();
            $terminal = new app\models\PosTerminals(['merchant_id' => $m['id'], 'store_id' => $store, 'name' => 'E2E cassa ' . $label]);
            if (!$terminal->save()) exit(1);
            // come il web: ogni accodamento nella transazione dell'operazione che lo genera
            $db->transaction(fn() => ProvisioningOutbox::enqueueMerchant($db, $m['id'], $m['owner'])) || exit(1);
            $db->transaction(fn() => ProvisioningOutbox::enqueueStore($db, $store, $m['id'], $m['owner'])) || exit(1);
            $db->transaction(fn() => ProvisioningOutbox::enqueueStoreSettings($db, $store, $m['id'], $m['owner'])) || exit(1);
            $db->transaction(fn() => ProvisioningOutbox::enqueueTerminal($db, (int) $terminal->id, $store, $m['id'], $m['owner'])) || exit(1);
            $out[$label] = ['merchant' => $m['id'], 'store' => $store, 'terminal' => (int) $terminal->id];
        }
        echo json_encode($out, JSON_THROW_ON_ERROR), "\n";
    } elseif (($argv[1] ?? '') === '--state') {
        $q = static fn(string $sql) => $db->createCommand($sql, [':i' => $instance])->queryAll();
        echo json_encode([
            'accounts' => $q('SELECT merchant_id, technical_email, remote_user_id, state FROM btcpay_merchant_accounts WHERE instance=:i ORDER BY merchant_id'),
            'credentials' => $q('SELECT merchant_id, generation, state FROM btcpay_merchant_credentials WHERE instance=:i ORDER BY merchant_id, generation'),
            'stores' => $q('SELECT merchant_id, store_id, remote_store_id, state FROM btcpay_store_links WHERE instance=:i ORDER BY merchant_id'),
            'pos' => $q('SELECT merchant_id, store_id, terminal_id, remote_app_id, state FROM btcpay_pos_links WHERE instance=:i ORDER BY merchant_id'),
            'jobs' => $q('SELECT kind, merchant_id, entity_id, generation, state, attempts, last_error_code FROM btcpay_provisioning_jobs WHERE instance=:i ORDER BY id'),
            'events' => $db->createCommand('SELECT store_id, event_type, result FROM btcpay_webhook_events WHERE store_id IS NOT NULL ORDER BY id')->queryAll(),
            // stato mostrato nel backoffice (SIP-022 B5)
            'status' => array_merge(
                array_map(fn($r) => app\components\btcpay\provisioning\ProvisioningStatus::merchant((int) $r['merchant_id']), $q('SELECT merchant_id FROM btcpay_merchant_accounts WHERE instance=:i')),
                array_map(fn($r) => app\components\btcpay\provisioning\ProvisioningStatus::store((int) $r['store_id']), $q('SELECT store_id FROM btcpay_store_links WHERE instance=:i')),
                array_map(fn($r) => app\components\btcpay\provisioning\ProvisioningStatus::terminal((int) $r['terminal_id']), $q('SELECT terminal_id FROM btcpay_pos_links WHERE instance=:i'))),
        ], JSON_THROW_ON_ERROR), "\n";
    } elseif (($argv[1] ?? '') === '--new-store') {
        // negozio appena creato del merchant A: solo collegamento (lo store remoto ha ancora il nome con il marker)
        $m = $merchants['A'];
        $db->createCommand()->insert('stores', ['merchant_id' => $m['id'], 'description' => 'E2E negozio C', 'email' => 'e2e@example.invalid', 'phone' => '', 'mobile' => '',
            'addressStreet' => '', 'addressNumberHouse' => '', 'addressCity' => '', 'addressZip' => '', 'addressProvince' => '', 'addressCountry' => ''])->execute();
        $store = (int) $db->lastInsertID;
        $db->createCommand()->insert('storesettings', ['store_id' => $store, 'defaultCurrency' => 'EUR'])->execute();
        $db->transaction(fn() => ProvisioningOutbox::enqueueStore($db, $store, $m['id'], $m['owner'])) || exit(1);
        echo $store, "\n";
    } elseif (($argv[1] ?? '') === '--deliver') {
        // SIP-022 passo 4: consegna firmata come BTCPay verso l'endpoint Napay (good | badsig | otherstore)
        $storeId = (int) ($argv[2] ?? 0); $remote = (string) ($argv[3] ?? ''); $mode = (string) ($argv[4] ?? 'good'); $delivery = (string) ($argv[5] ?? 'E2eDelivery01');
        $master = (string) getenv('BTCPAY_STORE_WEBHOOK_KEY');
        $body = json_encode(['deliveryId' => $delivery, 'type' => 'InvoiceSettled', 'storeId' => $remote, 'invoiceId' => 'E2eInvoice' . substr($delivery, -4), 'timestamp' => time()]);
        $secret = app\components\btcpay\StoreWebhookSecret::derive($master, $instance, $mode === 'otherstore' ? $storeId + 1 : $storeId);
        $signature = 'sha256=' . hash_hmac('sha256', $mode === 'badsig' ? $body . ' ' : $body, $secret);
        $curl = curl_init('http://web/api/btcpay/v1/store-webhook');
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'BTCPay-Sig: ' . $signature]]);
        $response = curl_exec($curl);
        echo curl_getinfo($curl, CURLINFO_RESPONSE_CODE), ' ', json_decode((string) $response, true)['result'] ?? '-', "\n";
    } elseif (($argv[1] ?? '') === '--set-wallet') {
        // SIP-022 xpub: come StoresController::saveWallet (chiave salvata, lavoro store_wallet nella stessa transazione)
        $storeId = (int) ($argv[2] ?? 0); $scheme = (string) ($argv[3] ?? '');
        $row = $db->createCommand('SELECT s.merchant_id, m.user_id FROM stores s JOIN merchants m ON m.id = s.merchant_id WHERE s.id=:s', [':s' => $storeId])->queryOne();
        if ($row === false) exit(1);
        $db->transaction(function () use ($db, $storeId, $scheme, $row) {
            $db->createCommand()->update('storesettings', ['derivationScheme' => $scheme === '' ? null : $scheme], ['store_id' => $storeId])->execute();
            ProvisioningOutbox::enqueueStoreWallet($db, $storeId, (int) $row['merchant_id'], (int) $row['user_id']) || exit(1);
        });
    } elseif (($argv[1] ?? '') === '--preview') {
        echo json_encode(app\components\btcpay\XpubAddresses::parse((string) ($argv[2] ?? ''), (string) ($argv[3] ?? ''))->receiveAddresses(5)), "\n";
    } elseif (($argv[1] ?? '') === '--archive-store') {
        // SIP-022 passo 3b: come StoresController::actionDelete (negozio archiviato, terminali disabilitati, lavoro store_archive)
        $storeId = (int) ($argv[2] ?? 0);
        $row = $db->createCommand('SELECT s.merchant_id, m.user_id FROM stores s JOIN merchants m ON m.id = s.merchant_id WHERE s.id=:s', [':s' => $storeId])->queryOne();
        if ($row === false) exit(1);
        $db->transaction(function () use ($db, $storeId, $row) {
            $db->createCommand()->update('stores', ['archived_at' => time()], ['id' => $storeId])->execute();
            $db->createCommand()->update('pos_terminals', ['is_enabled' => 0], ['store_id' => $storeId])->execute();
            ProvisioningOutbox::enqueueStoreArchive($db, $storeId, (int) $row['merchant_id'], (int) $row['user_id']) || exit(1);
        });
    } elseif (($argv[1] ?? '') === '--edit-store') {
        // SIP-022 passo 3: nome e valuta cambiati dal merchant, come StoresController::actionUpdateGeneral
        $storeId = (int) ($argv[2] ?? 0);
        $owner = (int) $db->createCommand('SELECT m.user_id FROM stores s JOIN merchants m ON m.id = s.merchant_id WHERE s.id=:s', [':s' => $storeId])->queryScalar();
        $merchantId = (int) $db->createCommand('SELECT merchant_id FROM stores WHERE id=:s', [':s' => $storeId])->queryScalar();
        $db->transaction(function () use ($db, $storeId, $merchantId, $owner) {
            $db->createCommand()->update('stores', ['description' => 'E2E negozio rinominato'], ['id' => $storeId])->execute();
            $db->createCommand()->update('storesettings', ['defaultCurrency' => 'USD'], ['store_id' => $storeId])->execute();
            ProvisioningOutbox::enqueueStoreSettings($db, $storeId, $merchantId, $owner) || exit(1);
        });
    } elseif (($argv[1] ?? '') === '--lose-user') {
        // SIP-022 R2: esito della creazione utente ignoto (ID remoto mai salvato), account in riconciliazione
        $merchantId = (int) ($argv[2] ?? 0);
        $db->createCommand()->update('btcpay_merchant_accounts', ['state' => 'reconcile_required', 'remote_user_id' => null, 'last_error_code' => 'user_outcome_unknown'],
            ['instance' => $instance, 'merchant_id' => $merchantId])->execute();
        $last = (int) $db->createCommand("SELECT MAX(generation) FROM btcpay_provisioning_jobs WHERE instance=:i AND kind='merchant_account' AND entity_id=:e", [':i' => $instance, ':e' => $merchantId])->queryScalar();
        $db->createCommand()->update('btcpay_provisioning_jobs', ['state' => 'failed', 'last_error_code' => 'user_outcome_unknown'],
            ['instance' => $instance, 'kind' => 'merchant_account', 'entity_id' => $merchantId, 'generation' => $last])->execute();
    } elseif (in_array($argv[1] ?? '', ['--break', '--stuck', '--retry'], true)) {
        // SIP-022 B5: guasti simulati sul mapping locale e riprova come lo chiede il web (admin)
        $kind = (string) ($argv[2] ?? ''); $entity = (int) ($argv[3] ?? 0);
        $table = ['merchant_account' => ['btcpay_merchant_accounts', 'merchant_id', 'remote_user_id', 'create_user'], 'store_link' => ['btcpay_store_links', 'store_id', 'remote_store_id', 'create_store'],
            'pos_link' => ['btcpay_pos_links', 'terminal_id', 'remote_app_id', 'create_pos_app']][$kind] ?? exit(1);
        [$links, $column, $remote, $op] = $table;
        $last = (int) $db->createCommand('SELECT MAX(generation) FROM btcpay_provisioning_jobs WHERE instance=:i AND kind=:k AND entity_id=:e', [':i' => $instance, ':k' => $kind, ':e' => $entity])->queryScalar();
        $where = ['instance' => $instance, 'kind' => $kind, 'entity_id' => $entity, 'generation' => $last];
        if ($argv[1] === '--break') {
            // rifiuto definitivo noto dopo la creazione remota (o risorsa remota sparita): mapping chiuso senza ID
            $db->createCommand()->update($links, ['state' => 'failed', $remote => null, 'last_error_code' => 'store_rejected'], ['instance' => $instance, $column => $entity])->execute();
            $db->createCommand()->update('btcpay_provisioning_jobs', ['state' => 'failed', 'last_error_code' => 'store_rejected'], $where)->execute();
        } elseif ($argv[1] === '--stuck') {
            // POST appena tentato con esito ignoto: riconciliazione con marker in volo
            $db->createCommand()->update($links, ['state' => 'reconcile_required', $remote => null, 'last_error_code' => 'outcome_unknown'], ['instance' => $instance, $column => $entity])->execute();
            $db->createCommand()->update('btcpay_provisioning_jobs', ['state' => 'reconcile_required', 'in_flight_op' => $op, 'in_flight_started_at' => time(),
                'in_flight_owner' => '00000000-0000-4000-8000-000000000e2e', 'next_attempt_at' => time() + 86400], $where)->execute();
        } else {
            $admin = (int) $db->createCommand("SELECT id FROM users WHERE username='runtime-admin'")->queryScalar();
            $queued = $db->transaction(fn() => ProvisioningOutbox::retry($db, $kind, $entity, $admin));
            echo $queued ? "queued\n" : "refused\n";
        }
    } else exit(1);
} catch (Throwable $error) { fwrite(STDERR, 'E2E fixture failed: ' . get_class($error) . ' at line ' . $error->getLine() . "\n"); exit(1); }
