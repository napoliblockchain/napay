<?php
// SIP-033: logo del merchant (pagina di pagamento BTCPay); HTTP reale, DB usa e getta.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
$app = new yii\web\Application(require $root . '/config/web.php');
$db = $app->db;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);
$checks = 0; $jar = null; $work = sys_get_temp_dir() . '/napay-logo-' . bin2hex(random_bytes(4));
mkdir($work, 0700);
register_shutdown_function(static function () use ($work) { foreach (glob($work . '/*') ?: [] as $f) unlink($f); @rmdir($work); });
function check($condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException($label); ++$checks; echo 'PASS: ' . $label . "\n"; }
function web(string $path, ?array $form = null, ?string $upload = null, bool $anonymous = false): array {
    global $jar;
    $curl = curl_init('http://127.0.0.1' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '']);
    if (!$anonymous) curl_setopt_array($curl, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($upload !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $form + ['logo' => new CURLFile($upload, 'image/png', 'logo.png')]]);
    elseif ($form !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form)]);
    $raw = curl_exec($curl);
    if ($raw === false) throw new RuntimeException('HTTP transport failed.');
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $r = ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    curl_close($curl);
    return $r;
}
function csrf(array $page): string {
    if (!preg_match('/<meta name="csrf-token" content="([^"]+)"/', $page['body'], $m)) throw new RuntimeException('CSRF unavailable.');
    return html_entity_decode($m[1]);
}
function login(string $email): void {
    global $jar, $app;
    $jar = tempnam(sys_get_temp_dir(), 'napay-logo-');
    $app->cache->flush();
    $page = web('/site/login');
    if (web('/site/login', ['_csrf' => csrf($page), 'LoginForm' => ['email' => $email, 'password' => 'fixture-password-only']])['status'] !== 302) throw new RuntimeException('Login failed.');
}
function header_of(array $r, string $name): string { return preg_match('/^' . preg_quote($name, '/') . ':\s*(.+?)\s*$/mi', $r['headers'], $m) ? $m[1] : ''; }
function scalar(string $sql, array $p = []) { global $db; return $db->createCommand($sql, $p)->queryScalar(); }
function png(string $path, int $w, int $h, string $trailer = ''): string {
    $img = imagecreatetruecolor($w, $h); imagesavealpha($img, true);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 200, 30, 30, 40));
    imagepng($img, $path); imagedestroy($img);
    if ($trailer !== '') file_put_contents($path, $trailer, FILE_APPEND);
    return $path;
}
// indirizzi presi dalle pagine come fa il browser: gli ID cifrati dipendono dalla sessione
function form_action(array $page, string $action): string {
    if (!preg_match('#action="(/merchants/' . preg_quote($action, '#') . '\?id=[^"]+)"#', $page['body'], $m)) throw new RuntimeException('Form unavailable: ' . $action);
    return html_entity_decode($m[1]);
}
$upload = static function (string $file) use (&$page): array {
    $page = web($GLOBALS['companyView']);
    return web(form_action($page, 'logo'), ['_csrf' => csrf($page)], $file);
};

// --- fixture: gestore del merchant A con due negozi attivi e uno archiviato, merchant B di un altro titolare
$merchantA = (int) scalar("SELECT id FROM merchants WHERE description='Runtime company merchant'");
$merchantB = (int) scalar("SELECT id FROM merchants WHERE description='Runtime company other'");
$managerA = (int) scalar("SELECT id FROM users WHERE username='runtime-merchant'");
$db->createCommand()->insert('member_profiles', ['user_id' => $managerA, 'is_corporate' => 1, 'denomination' => 'Logo PG A'])->execute();
$newStore = static function (int $merchant, string $name, ?int $archived = null) use ($db): int {
    $db->createCommand()->insert('stores', ['merchant_id' => $merchant, 'description' => $name, 'email' => 'logo@example.invalid', 'phone' => '', 'mobile' => '',
        'addressStreet' => '', 'addressNumberHouse' => '', 'addressCity' => '', 'addressZip' => '', 'addressProvince' => '', 'addressCountry' => '', 'archived_at' => $archived])->execute();
    $id = (int) $db->lastInsertID;
    $db->createCommand()->insert('storesettings', ['store_id' => $id, 'defaultCurrency' => 'EUR', 'customLogo' => 'https://napay.example.invalid/bundles/site/images/logobrand-294.png'])->execute();
    return $id;
};
$active1 = $newStore($merchantA, 'Logo negozio 1');
$active2 = $newStore($merchantA, 'Logo negozio 2');
$archived = $newStore($merchantA, 'Logo negozio archiviato', time());
$foreign = $newStore($merchantB, 'Logo negozio di B');
$logoOf = static fn(int $store) => (string) scalar('SELECT customLogo FROM storesettings WHERE store_id=:s', [':s' => $store]);

login('merchant@example.invalid');
check(preg_match('#data-napay-company-link[^>]*href="(/merchants/view\?id=[^"]+)"|href="(/merchants/view\?id=[^"]+)"[^>]*data-napay-company-link#', web('/')['body'], $m) === 1, 'Azienda link to the standard company view');
$companyView = html_entity_decode($m[1] !== '' ? $m[1] : $m[2]);
$company = web($companyView);
check($company['status'] === 200 && str_contains($company['body'], 'tab-logo') && str_contains($company['body'], 'Carica logo')
    && str_contains($company['body'], 'bundles/site/images/logobrand-294.png'), 'Company page has the Logo tab with the Napay logo until one is uploaded');
check(web(form_action($company, 'logo'))['status'] === 405, 'Logo upload only by POST');
check(web(form_action($company, 'logo'), ['x' => '1'], png("$work/nocsrf.png", 100, 50))['status'] === 400, 'Logo upload requires CSRF');

// --- file rifiutati: nulla salvato, negozi invariati
foreach ([
    'text renamed .png' => [static fn() => file_put_contents("$work/fake.png", "not an image\n") ? "$work/fake.png" : '', 'deve essere un\'immagine PNG o JPEG'],
    'SVG' => [static fn() => file_put_contents("$work/logo.svg", '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>') ? "$work/logo.svg" : '', 'deve essere un\'immagine PNG o JPEG'],
    'too small' => [static fn() => png("$work/small.png", 8, 8), 'troppo piccolo'],
    'too wide' => [static fn() => png("$work/wide.png", 3001, 16), 'troppo grande'],
    'too heavy' => [static fn() => png("$work/heavy.png", 64, 64, random_bytes(2200000)), 'troppo pesante'],
] as $label => [$make, $message]) {
    $r = $upload($make());
    $next = web($companyView);
    check($r['status'] === 302 && str_contains(html_entity_decode($next['body'], ENT_QUOTES | ENT_HTML5), $message) && scalar('SELECT COUNT(*) FROM merchant_logos') == 0
        && str_ends_with($logoOf($active1), 'logobrand-294.png'), 'Refused, nothing saved: ' . $label);
}

// --- PNG valido con dati in coda: ricodificato, ridimensionato, propagato ai soli negozi attivi del merchant
$r = $upload(png("$work/good.png", 900, 300, '<?php echo "payload"; ?>'));
$row = $db->createCommand('SELECT sha256, content, width, height FROM merchant_logos WHERE merchant_id=:m', [':m' => $merchantA])->queryOne();
check($r['status'] === 302 && str_contains(header_of($r, 'Location'), '/merchants/view?id=') && $row !== false && (int) $row['width'] === 600 && (int) $row['height'] === 200
    && str_starts_with($row['content'], "\x89PNG\r\n\x1a\n") && !str_contains($row['content'], 'payload') && hash('sha256', $row['content']) === $row['sha256'],
    'PNG re-encoded by Napay (trailing data dropped), scaled to fit 600x200, addressed by its SHA-256');
$url = $logoOf($active1);
check(str_ends_with($url, '/merchant-logo/view?id=' . $row['sha256']) && $logoOf($active2) === $url && str_ends_with($logoOf($archived), 'logobrand-294.png')
    && str_ends_with($logoOf($foreign), 'logobrand-294.png'), 'Active stores of the merchant use the new logo; archived store and other merchant untouched');
$public = web(parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY), null, null, true);
check($public['status'] === 200 && $public['body'] === $row['content'] && header_of($public, 'Content-Type') === 'image/png' && header_of($public, 'X-Content-Type-Options') === 'nosniff'
    && header_of($public, 'Cross-Origin-Resource-Policy') === 'cross-origin' && str_contains(header_of($public, 'Cache-Control'), 'immutable')
    && str_contains(header_of($public, 'Content-Security-Policy'), "default-src 'none'"), 'Public logo URL without login: exact PNG, nosniff, cross-origin image, immutable');
check(web('/merchant-logo/view?id=' . str_repeat('0', 64), null, null, true)['status'] === 404 && web('/merchant-logo/view?id=' . strtoupper($row['sha256']), null, null, true)['status'] === 404
    && web('/merchant-logo/view?id=..%2F..%2Fetc', null, null, true)['status'] === 404 && web('/merchant-logo/view?id[]=x', null, null, true)['status'] >= 400, 'Unknown or malformed logo address refused');
check(str_contains(web($companyView)['body'], $row['sha256']), 'Company view shows the uploaded logo');

// --- JPEG accettato e convertito in PNG; il precedente sostituito
$jpeg = imagecreatetruecolor(120, 120); imagejpeg($jpeg, "$work/logo.jpg"); imagedestroy($jpeg);
$r = $upload("$work/logo.jpg");
$sha = (string) scalar('SELECT sha256 FROM merchant_logos WHERE merchant_id=:m', [':m' => $merchantA]);
check($r['status'] === 302 && $sha !== $row['sha256'] && (int) scalar('SELECT COUNT(*) FROM merchant_logos') === 1
    && str_starts_with((string) scalar('SELECT content FROM merchant_logos WHERE merchant_id=:m', [':m' => $merchantA]), "\x89PNG") && str_ends_with($logoOf($active1), $sha),
    'JPEG accepted as PNG, replaces the previous logo on every active store');

// --- negozio creato dopo: nasce col logo del merchant
$page = web('/stores/create');
web('/stores/create', ['_csrf' => csrf($page), 'Stores' => ['description' => 'Logo negozio nuovo', 'merchant_id' => $merchantA]]);
$created = (int) scalar("SELECT id FROM stores WHERE description='Logo negozio nuovo'");
check($created > 0 && str_ends_with($logoOf($created), $sha), 'New store starts with the merchant logo');

// --- scope: view, update, logo e logo-delete caricano l'azienda solo da findModel, che rifiuta quella di un altro titolare
$controller = new app\controllers\MerchantsController('merchants', $app);
$find = (new ReflectionClass($controller))->getMethod('findModel');
$app->user->enableSession = false;
$app->user->setIdentity(app\models\Users::findOne($managerA));
$denied = static function (int $merchant) use ($find, $controller): bool {
    try { $find->invoke($controller, app\components\Crypt::encrypt((string) $merchant)); return false; } catch (yii\web\ForbiddenHttpException $e) { return true; }
};
check($denied($merchantB) && !$denied($merchantA), 'Manager loads only its own company (another merchant: 403)');
$source = (string) file_get_contents(dirname(__DIR__, 2) . '/controllers/MerchantsController.php');
check(preg_match_all('/public function action(Logo|LogoDelete)\(\$id\)\s*\{\s*\$model = \$this->findModel\(\$id\);/', $source) === 2, 'Logo actions load the company only through findModel');

// --- rimozione: negozi di nuovo col logo di Napay, indirizzo pubblico non più servito
$page = web($companyView);
$r = web(form_action($page, 'logo-delete'), ['_csrf' => csrf($page)]);
check($r['status'] === 302 && (int) scalar('SELECT COUNT(*) FROM merchant_logos') === 0 && str_ends_with($logoOf($active1), 'logobrand-294.png')
    && str_ends_with($logoOf($created), 'logobrand-294.png') && web('/merchant-logo/view?id=' . $sha, null, null, true)['status'] === 404,
    'Logo removed: stores back to the Napay logo, public address gone');
echo "PASS $checks merchant logo checks\n";
