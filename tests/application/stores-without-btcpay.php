<?php
// Negozi creati in Napay senza BTCPay (collegamento asincrono SIP-022 B1); HTTP reale, DB usa e getta.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
$app = new yii\web\Application(require $root . '/config/web.php');
$db = $app->db;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);
$checks = 0; $jar = null;
function check($condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException($label); ++$checks; echo 'PASS: ' . $label . "\n"; }
function web(string $path, ?array $form = null): array {
    global $jar;
    $curl = curl_init('http://127.0.0.1' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20,
        CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($form !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form)]);
    $started = microtime(true); $raw = curl_exec($curl);
    if ($raw === false) throw new RuntimeException('HTTP transport failed.');
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $r = ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size), 'seconds' => microtime(true) - $started];
    curl_close($curl);
    return $r;
}
function csrf(array $page): string {
    if (!preg_match('/<meta name="csrf-token" content="([^"]+)"/', $page['body'], $m)) throw new RuntimeException('CSRF unavailable.');
    return html_entity_decode($m[1]);
}
function login(string $email): void {
    global $jar, $app;
    $jar = tempnam(sys_get_temp_dir(), 'napay-stores-');
    $app->cache->flush();
    $page = web('/site/login');
    if (web('/site/login', ['_csrf' => csrf($page), 'LoginForm' => ['email' => $email, 'password' => 'fixture-password-only']])['status'] !== 302) throw new RuntimeException('Login failed.');
}
function location(array $r): string { return preg_match('/^Location:\s*(\S+)/mi', $r['headers'], $m) ? urldecode($m[1]) : ''; }
function scalar(string $sql, array $p = []) { global $db; return $db->createCommand($sql, $p)->queryScalar(); }

// --- fixture: gestore PG qualificato del merchant A, merchant B di un altro titolare
$managerA = (int) scalar("SELECT id FROM users WHERE username='runtime-merchant'");
$merchantA = (int) scalar("SELECT id FROM merchants WHERE description='Runtime company merchant'");
$merchantB = (int) scalar("SELECT id FROM merchants WHERE description='Runtime company other'");
$db->createCommand()->insert('member_profiles', ['user_id' => $managerA, 'is_corporate' => 1, 'denomination' => 'Stores PG A'])->execute();
// SIP-030 punto 4: server e chiave BTCPay dei negozi solo dalle variabili d'ambiente, mai dal DB
$envUrl = getenv('BTCPAY_URL'); $envKey = getenv('BTCPAY_STORES_API_KEY');
$storesClient = static function (string $url, string $key): ?app\components\BTCPayServer {
    putenv('BTCPAY_URL=' . $url); putenv('BTCPAY_STORES_API_KEY=' . $key);
    return app\components\btcpay\StoresBtcpay::client();
};
$db->createCommand()->update('settings', ['value' => 'https://db-host.example.invalid'], ['code' => 'btcpayHost'])->execute();
$db->createCommand()->update('settings', ['value' => str_repeat('d', 40)], ['code' => 'btcpayApiKey'])->execute();
check($storesClient('', '') === null, 'Store BTCPay client unavailable without env, DB values ignored');
check($storesClient('https://btcpay.example.invalid', str_repeat('a', 40)) instanceof app\components\BTCPayServer, 'Store BTCPay client from BTCPAY_URL and BTCPAY_STORES_API_KEY');
check($storesClient('http://btcpay.example.invalid', str_repeat('a', 40)) === null && $storesClient('https://btcpay.example.invalid', 'short') === null
    && $storesClient('https://user@btcpay.example.invalid', str_repeat('a', 40)) === null, 'Plain HTTP, credentials in URL or malformed key refused');
$storesClient((string) $envUrl, (string) $envKey);
$retired = array_column((new app\models\search\SettingsSearch())->search([])->query->asArray()->all(), 'code');
check(array_intersect($retired, app\models\Settings::RETIRED_CODES) === [] && !(new app\models\Settings(['description' => 'x', 'code' => 'btcpayApiKey', 'value' => 'x']))->validate(),
    'Retired BTCPay settings hidden from admin and cannot be recreated');
$before = (int) scalar('SELECT COUNT(*) FROM stores');

// --- creazione dal gestore: sempre per il proprio merchant, senza BTCPay
login('merchant@example.invalid');
$page = web('/stores/create');
check($page['status'] === 200, 'Store form reachable for the merchant manager');
$created = web('/stores/create', ['_csrf' => csrf($page), 'Stores' => ['description' => 'Negozio senza BTCPay', 'merchant_id' => $merchantB]]);
$store = $db->createCommand("SELECT * FROM stores WHERE description='Negozio senza BTCPay'")->queryOne();
check($created['status'] === 302 && $store !== false && (int) $store['merchant_id'] === $merchantA, 'Store created without BTCPay, forced to the manager merchant (tampered merchant_id ignored)');
check($created['seconds'] < 5, 'Creation does not wait for any BTCPay call');
$settings = $db->createCommand('SELECT bps_storeid, defaultCurrency, monitoringExpiration, showRecommendedFee, htmlTitle, customLogo, customCSS, website FROM storesettings WHERE store_id=:s',
    [':s' => $store['id']])->queryOne();
check($settings !== false && $settings['bps_storeid'] === null && $settings['defaultCurrency'] === 'EUR', 'Default store settings saved, not linked to BTCPay yet');
check((int) $settings['monitoringExpiration'] === 86400 && (int) $settings['showRecommendedFee'] === 0 && $settings['htmlTitle'] === app\components\btcpay\StoreSettingsPayload::FIXED['htmlTitle']
    && str_ends_with((string) $settings['customLogo'], app\components\btcpay\StoreSettingsPayload::DEFAULT_LOGO) && is_file(dirname(__DIR__, 2) . '/web' . app\components\btcpay\StoreSettingsPayload::DEFAULT_LOGO)
    && $settings['customCSS'] === null && $settings['website'] === null, 'SIP-033: new store with the standard values and the Napay logo (an existing file), no CSS, no website yet');
// Gli ID cifrati dipendono dalla sessione web: si usano quelli prodotti dalle pagine.
check(str_contains(location($created), 'stores/view') && preg_match('/[?&]id=([^&]+)/', location($created), $match), 'Redirect to the new store');
$id = $match[1];
$view = web('/stores/view?id=' . urlencode($id));
check($view['status'] === 200 && str_contains($view['body'], 'data-wallet-notice="both"'), 'New store: page says both the TTS and the BTC wallet are missing');
// Input malformati: rifiuto controllato, nessun negozio o impostazione creati.
$counts = static fn(): array => [(int) scalar('SELECT COUNT(*) FROM stores'), (int) scalar('SELECT COUNT(*) FROM storesettings')];
$baseline = $counts();
foreach ([
    'description array' => ['description' => ['x'], 'merchant_id' => $merchantA],
    'merchant_id array' => ['description' => 'Negozio array', 'merchant_id' => ['x']],
    'whole form scalar' => 'x',
] as $label => $payload) {
    $page = web('/stores/create');
    $r = web('/stores/create', ['_csrf' => csrf($page), 'Stores' => $payload]);
    check($r['status'] === 400 && $counts() === $baseline, 'Malformed input refused (400), nothing saved: ' . $label);
}
foreach (['' => 'missing description', '   ' => 'blank description'] as $description => $label) {
    $page = web('/stores/create');
    $fields = $description === '' ? ['merchant_id' => $merchantA] : ['description' => $description, 'merchant_id' => $merchantA];
    $r = web('/stores/create', ['_csrf' => csrf($page), 'Stores' => $fields]);
    check($r['status'] === 200 && str_contains($r['body'], 'Descrizione obbligatoria') && $counts() === $baseline, 'Form shown again with error, nothing saved: ' . $label);
}
$r = web('/stores/update-general?id=' . urlencode($id));
check($r['status'] === 302 && str_contains(location($r), 'stores/view'), 'BTCPay settings page waits for the link: update-general');
// SIP-030 punto 4: cambi, ricevute, criteri e webhook fissi, pagine rimosse per tutti
foreach (['update-rates', 'update-checkout', 'update-criteria', 'update-webhook'] as $action) {
    check(in_array(web('/stores/' . $action . '?id=' . urlencode($id))['status'], [403, 404], true), 'Removed BTCPay settings page: ' . $action);
}
$storePage = web('/stores/view?id=' . urlencode($id))['body'];
check(str_contains($storePage, 'tab-general') && !str_contains($storePage, 'tab-rates') && !str_contains($storePage, 'tab-checkout') && !str_contains($storePage, 'tab-criteria') && !str_contains($storePage, 'tab-webhook'), 'Store page without rates, receipts, criteria and webhook tabs');
$walletTab = (string) file_get_contents(dirname(__DIR__, 2) . '/views/stores/_details-wallet.php');
check(!str_contains($walletTab, 'accountKeyPath') && str_contains($walletTab, 'XpubAddresses::mask($scheme)') && !preg_match('/Html::encode\(\$scheme\)/', $walletTab),
    'BTC wallet tab: key path never shown, saved xpub only abbreviated');
foreach (['_details-general.php', 'update.php', 'view.php'] as $file) check(!str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/views/stores/' . $file), 'bps_storeid'), 'BTCPay store ID never shown: ' . $file);
check(str_contains(web('/pos-management/index')['body'], 'Negozio senza BTCPay'), 'New store available for POS terminals');
// SIP-022 B5: il riprova del collegamento BTCPay è solo dell'admin (POST con CSRF), mai del gestore
$page = web('/stores/index');
$before = (int) scalar('SELECT COUNT(*) FROM btcpay_provisioning_jobs');
$r = web('/btcpay-provisioning/retry', ['_csrf' => csrf($page), 'kind' => 'store_link', 'id' => $id]);
check($r['status'] === 403 && (int) scalar('SELECT COUNT(*) FROM btcpay_provisioning_jobs') === $before, 'BTCPay link retry refused to a merchant manager (403), nothing queued');
check(!str_contains(web('/stores/view?id=' . urlencode($id))['body'], 'btcpay-provisioning/retry'), 'Retry button never shown to a merchant manager');

// --- scope: il gestore non vede negozi di altri merchant (prima: qualunque ID cifrato valido)
$db->createCommand()->insert('stores', ['merchant_id' => $merchantB, 'description' => 'Negozio di B', 'email' => 'b@example.invalid', 'phone' => '', 'mobile' => '',
    'addressStreet' => '', 'addressNumberHouse' => '', 'addressCity' => '', 'addressZip' => '', 'addressProvince' => '', 'addressCountry' => ''])->execute();
$foreignId = (int) $db->lastInsertID;
$controller = new app\controllers\StoresController('stores', $app);
$find = (new ReflectionClass($controller))->getMethod('findModel');
$scoped = static function (int $user) use ($app, $controller, $find, $foreignId) {
    $app->user->enableSession = false;
    $app->user->setIdentity(app\models\Users::findOne($user));
    try { return $find->invoke($controller, $foreignId); } catch (yii\web\NotFoundHttpException $e) { return null; }
};
check($scoped($managerA) === null, 'Manager cannot load another merchant store (404), whatever ID is supplied');
check($scoped((int) scalar("SELECT id FROM users WHERE username='runtime-admin'"))?->id == $foreignId, 'Administrator still loads every store');
check($scoped($managerA) === null && (int) $find->invoke($controller, (int) $store['id'])->merchant_id === $merchantA, 'Manager loads own store');

check((int) scalar('SELECT COUNT(*) FROM stores') === $before + 2, 'Exactly the expected stores exist');
// --- SIP-022 xpub: il merchant incolla la chiave pubblica, vede i primi indirizzi, conferma; mai chiavi private
$zpub = 'zpub6rFR7y4Q2AijBEqTUquhVz398htDFrtymD9xYYfG1m4wAcvPhXNfE3EfH1r1ADqtfSdVCToUG868RvUUkgDKf31mGDtKsAYz2oz2AGutZYs';
check(web('/stores/wallet-preview?id=' . urlencode($id))['status'] === 405, 'Wallet preview only by POST');
$page = web('/stores/view?id=' . urlencode($id));
check(str_contains($page['body'], 'store-xpub') && str_contains($page['body'], 'wallet-preview'), 'Merchant sees the xpub form on the BTC wallet tab');
$preview = web('/stores/wallet-preview?id=' . urlencode($id), ['_csrf' => csrf($page), 'xpub' => $zpub]);
check($preview['status'] === 200 && str_contains($preview['body'], 'bc1qcr8te4kr609gcawutmrza0j4xv80jy8z306fyu') && str_contains($preview['body'], 'wallet-set')
    && scalar('SELECT derivationScheme FROM storesettings WHERE store_id=' . (int) $store['id']) === null, 'Preview shows the first receive addresses, nothing saved yet');
$bad = web('/stores/wallet-preview?id=' . urlencode($id), ['_csrf' => csrf($page), 'xpub' => 'xprv9s21ZrQH143K3QTDL4LXw2F7HEK3wJUD2nW2nRk4stbPy6cq3jPPqjiChkVvvNKmPGJxWUtg6LnF5kejMRNNU3TGtRBeJgk33yuGBxrMPHi']);
check($bad['status'] === 302 && !str_contains($bad['body'], 'xprv9s21') && scalar('SELECT derivationScheme FROM storesettings WHERE store_id=' . (int) $store['id']) === null, 'Private key refused, never echoed back');
$set = web('/stores/wallet-set?id=' . urlencode($id), ['_csrf' => csrf($preview), 'xpub' => $zpub]);
check($set['status'] === 302 && scalar('SELECT derivationScheme FROM storesettings WHERE store_id=' . (int) $store['id']) === $zpub, 'Confirmed key saved');
$page = web('/stores/view?id=' . urlencode($id));
check(str_contains($page['body'], 'data-wallet-notice="tts"') && !str_contains($page['body'], 'data-wallet-notice="both"'), 'With the BTC wallet set, only the missing TTS wallet is reported');
$db->createCommand()->insert('store_tts_wallets', ['store_id' => (int) $store['id'], 'merchant_id' => (int) $store['merchant_id'], 'address' => '0x' . str_repeat('ab', 20),
    'updated_by' => $managerA, 'updated_at' => time()])->execute();
check(!str_contains(web('/stores/view?id=' . urlencode($id))['body'], 'data-wallet-notice'), 'Both wallets set: no notice');
$db->createCommand()->delete('store_tts_wallets', ['store_id' => (int) $store['id']])->execute();
check(str_contains($page['body'], htmlspecialchars(app\components\btcpay\XpubAddresses::mask($zpub))) && !str_contains($page['body'], $zpub) && str_contains($page['body'], 'wallet-remove'),
    'Saved key shown only abbreviated, with change and remove');
check(web('/stores/wallet-remove?id=' . urlencode($id), ['_csrf' => csrf($page)])['status'] === 302 && scalar('SELECT derivationScheme FROM storesettings WHERE store_id=' . (int) $store['id']) === null,
    'Wallet removed');

// --- SIP-022 passo 3b: eliminare un negozio = archiviarlo (mai cancellarlo), terminali disabilitati, nascosto
$sid = (int) $store['id'];
$terminal = new app\models\PosTerminals(['merchant_id' => (int) $store['merchant_id'], 'store_id' => $sid, 'name' => 'Cassa da archiviare']);
check($terminal->save(), 'Fixture: enabled terminal in the store to delete');
$jobsBefore = (int) scalar('SELECT COUNT(*) FROM btcpay_provisioning_jobs');
$page = web('/stores/view?id=' . urlencode($id));
check(web('/stores/delete?id=' . urlencode($id))['status'] === 405 && scalar('SELECT archived_at FROM stores WHERE id=' . $sid) === null, 'Delete is never a GET (405, nothing archived)');
$r = web('/stores/delete?id=' . urlencode($id), ['_csrf' => csrf($page)]);
check($r['status'] === 302 && scalar('SELECT archived_at FROM stores WHERE id=' . $sid) !== null && (int) scalar('SELECT COUNT(*) FROM stores WHERE id=' . $sid) === 1
    && (int) scalar('SELECT is_enabled FROM pos_terminals WHERE id=' . (int) $terminal->id) === 0 && (int) scalar('SELECT COUNT(*) FROM btcpay_provisioning_jobs') === $jobsBefore,
    'Delete archives the store (row kept), disables its terminals, no BTCPay job without provisioning');
check(web('/stores/view?id=' . urlencode($id))['status'] === 404 && !str_contains(web('/stores/index')['body'], 'Negozio senza BTCPay')
    && preg_match('/<option[^>]*>[^<]*Negozio senza BTCPay/', web('/pos-management/index')['body']) !== 1, 'Archived store hidden from list, detail and terminal store choices');
$page = web('/pos-management/index');
web('/pos-management/terminal-enable?id=' . urlencode((string) $terminal->public_id), ['_csrf' => csrf($page)]);
check((int) scalar('SELECT is_enabled FROM pos_terminals WHERE id=' . (int) $terminal->id) === 0, 'A terminal of an archived store cannot be enabled again');
// negozi v1 (chiave ponte): il client BTCPay passa dal proxy di uscita come le quote; in produzione il web non ha DNS
$proxyDir = sys_get_temp_dir() . '/napay-proxy-' . bin2hex(random_bytes(6));
mkdir($proxyDir, 0700);
file_put_contents("$proxyDir/router.php", '<?php file_put_contents(__DIR__ . "/seen", $_SERVER["REQUEST_URI"] . "\n" . ($_SERVER["HTTP_HOST"] ?? "") . "\n", FILE_APPEND); header("Content-Type: application/json"); echo "[]";');
$proxy = proc_open(['php', '-S', '127.0.0.1:8097', "$proxyDir/router.php"], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
try {
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', 8097); $i++) usleep(100000);
    $previous = getenv('PAYMENT_PROXY');
    putenv('PAYMENT_PROXY=http://127.0.0.1:8097');
    (new app\components\BTCPayServer('http://btcpay-v1.example.invalid', str_repeat('a', 40)))->getRates('StoreV1', 'BTC-CHAIN_EUR');
    $seen = (string) @file_get_contents("$proxyDir/seen");
    check(str_contains($seen, 'btcpay-v1.example.invalid') && str_contains($seen, '/api/v1/stores/StoreV1/rates'), 'Store BTCPay client (v1 bridge) goes through the egress proxy, no direct DNS');
    putenv('PAYMENT_PROXY=not a proxy');
    $refused = false;
    try { (new app\components\BTCPayServer('http://btcpay-v1.example.invalid', str_repeat('a', 40)))->getRates('StoreV1', 'BTC-CHAIN_EUR'); } catch (app\components\payments\PaymentGatewayException $e) { $refused = true; }
    check($refused, 'Invalid proxy: store BTCPay client makes no connection');
    putenv($previous === false ? 'PAYMENT_PROXY' : "PAYMENT_PROXY=$previous");
} finally {
    proc_terminate($proxy); proc_close($proxy);
    array_map('unlink', glob("$proxyDir/*") ?: []); rmdir($proxyDir);
}
echo "PASS $checks store without BTCPay checks\n";
