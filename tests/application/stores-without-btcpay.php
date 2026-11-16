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
// BTCPay non configurato e irraggiungibile: nessun valore nelle impostazioni.
$db->createCommand()->update('settings', ['value' => ''], ['code' => ['btcpayHost', 'btcpayApiKey']])->execute();
$before = (int) scalar('SELECT COUNT(*) FROM stores');

// --- creazione dal gestore: sempre per il proprio merchant, senza BTCPay
login('merchant@example.invalid');
$page = web('/stores/create');
check($page['status'] === 200, 'Store form reachable for the merchant manager');
$created = web('/stores/create', ['_csrf' => csrf($page), 'Stores' => ['description' => 'Negozio senza BTCPay', 'merchant_id' => $merchantB]]);
$store = $db->createCommand("SELECT * FROM stores WHERE description='Negozio senza BTCPay'")->queryOne();
check($created['status'] === 302 && $store !== false && (int) $store['merchant_id'] === $merchantA, 'Store created without BTCPay, forced to the manager merchant (tampered merchant_id ignored)');
check($created['seconds'] < 5, 'Creation does not wait for any BTCPay call');
$settings = $db->createCommand('SELECT bps_storeid, defaultCurrency FROM storesettings WHERE store_id=:s', [':s' => $store['id']])->queryOne();
check($settings !== false && $settings['bps_storeid'] === null && $settings['defaultCurrency'] === 'EUR', 'Default store settings saved, not linked to BTCPay yet');
// Gli ID cifrati dipendono dalla sessione web: si usano quelli prodotti dalle pagine.
check(str_contains(location($created), 'stores/view') && preg_match('/[?&]id=([^&]+)/', location($created), $match), 'Redirect to the new store');
$id = $match[1];
$view = web('/stores/view?id=' . urlencode($id));
check($view['status'] === 200 && str_contains($view['body'], 'Pagamenti BTC da configurare'), 'Store page explains that BTC payments are pending');
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
check(!str_contains($walletTab, 'derivationScheme') && !str_contains($walletTab, 'accountKeyPath'), 'BTC wallet tab hides derivation scheme and account key path');
foreach (['_details-general.php', 'update.php', 'view.php'] as $file) check(!str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/views/stores/' . $file), 'bps_storeid'), 'BTCPay store ID never shown: ' . $file);
check(str_contains(web('/pos-management/index')['body'], 'Negozio senza BTCPay'), 'New store available for POS terminals');

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
echo "PASS $checks store without BTCPay checks\n";
