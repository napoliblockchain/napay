<?php
// C4 (SIP-021/SIP-023): backoffice terminali, profili operatore e grant; HTTP reale + servizio, DB usa e getta.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';

use app\components\pos\management\ManagementException as Failure;
use app\components\pos\management\PosManagement;
use app\components\pos\management\Scope;

$app = new yii\web\Application(require $root . '/config/web.php');
$app->user->enableSession = false;
$db = $app->db;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);

/** C4-RV1/RV2: scrittura che attende il lock del merchant tenuto da un'altra connessione. */
if (($argv[1] ?? '') === '--race-op') {
    try {
        $app->user->setIdentity(app\models\Users::findOne((int) $argv[2]));
        $scope = Scope::current();
        if ($scope === null) exit(4);
        fwrite(STDOUT, "ready\n");
        match ($argv[3]) {
            'terminal-disable' => PosManagement::setTerminalEnabled($scope, $argv[4], false),
            'operator-enable' => PosManagement::setOperatorEnabled($scope, (int) $argv[4], true),
            'operator-disable' => PosManagement::setOperatorEnabled($scope, (int) $argv[4], false),
        };
        exit(0);
    } catch (Failure $e) { exit(['not_found' => 11, 'not_allowed' => 12, 'conflict' => 10][$e->getMessage()] ?? 3); }
    catch (Throwable $e) { exit(2); }
}

/** Processo figlio della gara: stesso grant creato in parallelo. */
if (($argv[1] ?? '') === '--race-grant') {
    try {
        $app->user->setIdentity(app\models\Users::findOne((int) $argv[2]));
        $scope = Scope::current();
        fwrite(STDOUT, "ready\n"); fread(STDIN, 1);
        PosManagement::createGrant($scope, (int) $argv[3], (int) $argv[4]);
        exit(0);
    } catch (Failure $e) { exit($e->getMessage() === Failure::CONFLICT ? 10 : 3); }
    catch (Throwable $e) { exit(2); }
}

$checks = 0; $jar = null;
function check($condition, $label) {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks; echo 'PASS: ' . $label . "\n";
}
function fails(callable $fn, string $code, string $label) {
    try { $fn(); } catch (Failure $e) { check($e->getMessage() === $code, $label); return; }
    throw new RuntimeException($label . ' (nessun errore)');
}
function request($path, ?array $form = null, array $headers = []) {
    global $jar;
    $curl = curl_init('http://127.0.0.1' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => $headers]);
    if ($form !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form)]);
    $raw = curl_exec($curl);
    if ($raw === false) throw new RuntimeException('HTTP transport failed.');
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $result = ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    curl_close($curl);
    return $result;
}
function xp($page) { $d = new DOMDocument(); @$d->loadHTML($page['body'], LIBXML_NONET); return new DOMXPath($d); }
function csrf($page) {
    $n = xp($page)->query('//meta[@name="csrf-token"]/@content');
    if ($n->length !== 1) throw new RuntimeException('CSRF unavailable.');
    return $n->item(0)->nodeValue;
}
function nodes($page, $query) { return iterator_to_array(xp($page)->query($query)); }
function login($email) {
    global $jar;
    $jar = tempnam('/tmp', 'napay-c4-');
    $page = request('/site/login');
    return request('/site/login', ['_csrf' => csrf($page), 'LoginForm' => ['email' => $email, 'password' => 'fixture-password-only']])['status'];
}
/** POST dell'azione indicata da un form della pagina (action con ID cifrati della sessione). */
function submit($page, $actionContains, array $fields = []) {
    foreach (nodes($page, '//form[@method="post"]') as $form) {
        $action = $form->getAttribute('action');
        if (str_contains($action, $actionContains)) return [request($action, ['_csrf' => csrf($page)] + $fields), $action];
    }
    throw new RuntimeException('Form missing: ' . $actionContains);
}
function scalar($sql, array $p = []) { global $db; return $db->createCommand($sql, $p)->queryScalar(); }
function scopeOf($userId): ?Scope { global $app; $app->user->setIdentity(app\models\Users::findOne($userId)); return Scope::current(); }

// --- fixture C4: PG socie con gestore, merchant non qualificato, operatori coerenti e incoerenti
$level = fn($l) => (int) scalar('SELECT id FROM privileges WHERE level=:l ORDER BY id LIMIT 1', [':l' => $l]);
$hash = password_hash('fixture-password-only', PASSWORD_BCRYPT);
$user = function (string $name, int $privilegeLevel, int $active = 1) use ($db, $level, $hash) {
    $db->createCommand()->insert('users', ['username' => $name . '@example.invalid', 'email' => $name . '@example.invalid', 'password' => $hash,
        'first_name' => 'C4', 'last_name' => $name, 'oauth_provider' => 'fixture', 'oauth_uid' => $name, 'authKey' => 'fixture-' . $name,
        'accessToken' => 'fixture-' . $name, 'picture' => '/favicon.ico', 'privilege_id' => $level($privilegeLevel), 'is_merchant' => 0, 'is_active' => $active])->execute();
    return (int) $db->lastInsertID;
};
$merchant = function (int $owner, string $description) use ($db) {
    $db->createCommand()->insert('merchants', ['user_id' => $owner, 'description' => $description, 'email' => 'fixture@example.invalid', 'vatNumber' => '12345678901',
        'phone' => '', 'mobile' => '', 'addressStreet' => '', 'addressNumberHouse' => '', 'addressCity' => '', 'addressZip' => '', 'addressProvince' => '', 'addressCountry' => ''])->execute();
    return (int) $db->lastInsertID;
};
$store = function (int $merchantId, string $description) use ($db) {
    $db->createCommand()->insert('stores', ['merchant_id' => $merchantId, 'description' => $description, 'email' => 'fixture@example.invalid', 'phone' => '', 'mobile' => '',
        'addressStreet' => '', 'addressNumberHouse' => '', 'addressCity' => '', 'addressZip' => '', 'addressProvince' => '', 'addressCountry' => ''])->execute();
    return (int) $db->lastInsertID;
};
$pg = fn(int $owner, string $name) => $db->createCommand()->insert('member_profiles', ['user_id' => $owner, 'is_corporate' => 1, 'denomination' => $name])->execute();
$profile = fn(int $u, int $m) => $db->createCommand()->insert('pos_operators', ['user_id' => $u, 'merchant_id' => $m, 'is_enabled' => 0, 'created_at' => time(), 'updated_at' => time()])->execute();
$session = function (int $u) use ($db) {
    $db->createCommand()->insert('pos_operator_sessions', ['user_id' => $u, 'token_sha256' => bin2hex(random_bytes(32)), 'client_key_prefix' => bin2hex(random_bytes(8)),
        'credential_fingerprint' => bin2hex(random_bytes(32)), 'created_at' => time(), 'expires_at' => time() + 3600])->execute();
};

$managerA = (int) scalar("SELECT id FROM users WHERE username='runtime-merchant'");
$merchantA = (int) scalar("SELECT id FROM merchants WHERE description='Runtime company merchant'");
$pg($managerA, 'Fixture PG A');
$managerB = $user('c4-manager-b', 30); $pg($managerB, 'Fixture PG B'); $merchantB = $merchant($managerB, 'C4 merchant B');
$managerX = $user('c4-manager-x', 30); $merchantX = $merchant($managerX, 'C4 merchant non qualificato');
$admin = (int) scalar("SELECT id FROM users WHERE username='runtime-admin'");
$storeA1 = $store($merchantA, 'Negozio A1'); $storeA2 = $store($merchantA, 'Negozio A2');
$storeB1 = $store($merchantB, 'Negozio B1'); $storeX1 = $store($merchantX, 'Negozio X1');
$opA1 = $user('c4-op-a1', 0); $profile($opA1, $merchantA);
$opB1 = $user('c4-op-b1', 0); $profile($opB1, $merchantB);
$opConflict = $user('c4-op-conflict', 0); $profile($opConflict, $merchantA);
$db->createCommand()->insert('member_profiles', ['user_id' => $opConflict, 'is_corporate' => 0])->execute();
$opX = $user('c4-op-x', 0); $profile($opX, $merchantX);
$session($opA1); $session($opA1); $session($opB1);
$terminalB = new app\models\PosTerminals(['merchant_id' => $merchantB, 'store_id' => $storeB1, 'name' => 'Cassa B']);
$terminalX = new app\models\PosTerminals(['merchant_id' => $merchantX, 'store_id' => $storeX1, 'name' => 'Cassa X', 'is_enabled' => 1]);
check($terminalB->save() && $terminalX->save(), 'Fixture C4 created in disposable DB');
$logStart = (int) scalar('SELECT COALESCE(MAX(id),0) FROM logs');

// --- accesso
$jar = tempnam('/tmp', 'napay-c4-');
$r = request('/pos-management/index');
check($r['status'] === 302 && str_contains($r['headers'], '/site/login'), 'Anonymous redirected to login');
foreach (['user@example.invalid' => 'Unclassified base account denied', 'c4-manager-x@example.invalid' => 'Merchant level without PG qualification denied',
    'c4-op-a1@example.invalid' => 'Operator account cannot manage POS'] as $email => $label) {
    check(login($email) === 302 && request('/pos-management/index')['status'] === 403, $label);
}

// --- gestore A: scope, creazione, XSS, CSRF, metodi
check(login('merchant@example.invalid') === 302, 'Manager A logged in');
$index = request('/pos-management/index');
check($index['status'] === 200 && str_contains($index['body'], 'c4-op-a1@example.invalid') && !str_contains($index['body'], 'c4-op-b1@example.invalid')
    && !str_contains($index['body'], 'Cassa B') && !str_contains($index['body'], 'Cassa X'), 'Manager sees only own operators and terminals');
check(str_contains($index['body'], 'href="/pos-management/index"') && !str_contains($index['body'], 'href="/pos/index"'), 'Qualified manager sidebar links to integrated POS backoffice');
$form = request('/pos-management/terminal-create');
$options = array_map(fn($n) => trim($n->textContent), nodes($form, '//select[@id="terminalform-store"]/option[@value!=""]'));
sort($options);
check($options === ['Negozio A1', 'Negozio A2'], 'Store selector limited to own stores');
$value = nodes($form, '//select[@id="terminalform-store"]/option[normalize-space(.)="Negozio A1"]/@value')[0]->nodeValue;
$evil = '<script>window.pwned=1</script> Cassa';
$r = request('/pos-management/terminal-create', ['_csrf' => csrf($form), 'TerminalForm' => ['store' => $value, 'name' => '  ' . $evil . '  ', 'is_enabled' => 1, 'merchant_id' => $merchantB, 'public_id' => '00000000-0000-4000-8000-000000000000']]);
$created = $db->createCommand('SELECT * FROM pos_terminals WHERE store_id=:s', [':s' => $storeA1])->queryOne();
check($r['status'] === 302 && $created && (int) $created['is_enabled'] === 0 && (int) $created['merchant_id'] === $merchantA
    && $created['name'] === $evil && $created['public_id'] !== '00000000-0000-4000-8000-000000000000', 'Terminal created disabled, extra POST fields ignored');
$index = request('/pos-management/index');
check(!str_contains($index['body'], $evil) && str_contains($index['body'], htmlspecialchars('Negozio A1 - ' . $evil, ENT_QUOTES)), 'Malicious terminal name encoded');
$r = request('/pos-management/terminal-create', ['_csrf' => csrf($form), 'TerminalForm' => ['store' => 'non-valido', 'name' => 'X']]);
check($r['status'] === 200 && (int) scalar('SELECT COUNT(*) FROM pos_terminals WHERE merchant_id=:m', [':m' => $merchantA]) === 1, 'Tampered store id rejected without write');
$uuid = $created['public_id'];
check(request('/pos-management/terminal-enable?id=' . $uuid, [])['status'] === 400, 'Missing CSRF rejected');
check(request('/pos-management/terminal-enable?id=' . $uuid)['status'] === 405, 'GET on write action rejected');
[$r] = submit($index, 'terminal-enable');
check($r['status'] === 302 && (int) scalar('SELECT is_enabled FROM pos_terminals WHERE public_id=:p', [':p' => $uuid]) === 1, 'Terminal enabled on qualified merchant');
check(request('/pos-management/terminal-disable?id=' . $terminalB->public_id, ['_csrf' => csrf($index)])['status'] === 404
    && request('/pos-management/terminal-update?id=' . $terminalB->public_id)['status'] === 404, 'Other merchant terminal UUID is 404');
check(request('/pos-management/terminal-update?id=not-a-uuid')['status'] === 404 && request('/pos-management/operator?id=abc')['status'] === 404
    && request('/pos-management/operator?id[]=1')['status'] === 400, 'Malformed identifiers rejected');
$edit = request('/pos-management/terminal-update?id=' . $uuid);
$r = request('/pos-management/terminal-update?id=' . $uuid, ['_csrf' => csrf($edit), 'TerminalForm' => ['name' => 'Cassa 1', 'store' => 'ignored']]);
check($r['status'] === 302 && scalar('SELECT name FROM pos_terminals WHERE public_id=:p', [':p' => $uuid]) === 'Cassa 1'
    && (int) scalar('SELECT store_id FROM pos_terminals WHERE public_id=:p', [':p' => $uuid]) === $storeA1, 'Rename changes only the name');

// --- profilo e grant (gestore A)
$links = array_map(fn($n) => $n->nodeValue, nodes($index, '//a[contains(@href,"/pos-management/operator?")]/@href'));
check(count($links) === 2, 'Operator list limited to own merchant');
$pages = [];
foreach ($links as $href) { $p = request($href); $pages[str_contains($p['body'], 'c4-op-a1@') ? 'a1' : 'conflict'] = [$href, $p]; }
[$hrefA1, $pageA1] = $pages['a1'];
[$r] = submit($pageA1, 'operator-enable');
check($r['status'] === 302 && (int) scalar('SELECT is_enabled FROM pos_operators WHERE user_id=:u', [':u' => $opA1]) === 1, 'Coherent operator profile enabled');
$pageA1 = request($hrefA1);
$grantOptions = array_map(fn($n) => trim($n->textContent), nodes($pageA1, '//select[@id="grantform-store"]/option[@value!=""]'));
sort($grantOptions);
check($grantOptions === ['Negozio A1', 'Negozio A2'], 'Grant selector limited to operator merchant stores');
$a1Value = nodes($pageA1, '//select[@id="grantform-store"]/option[normalize-space(.)="Negozio A1"]/@value')[0]->nodeValue;
[$r] = submit($pageA1, 'grant-create', ['GrantForm' => ['store' => $a1Value]]);
check($r['status'] === 302 && (int) scalar('SELECT is_enabled FROM pos_store_operators WHERE user_id=:u AND store_id=:s', [':u' => $opA1, ':s' => $storeA1]) === 0, 'Grant created disabled');
$pageA1 = request($hrefA1);
[$r] = submit($pageA1, 'grant-enable');
check((int) scalar('SELECT is_enabled FROM pos_store_operators WHERE user_id=:u AND store_id=:s', [':u' => $opA1, ':s' => $storeA1]) === 1, 'Grant enabled');
$pageA1 = request($hrefA1);
[$r] = submit($pageA1, 'operator-disable');
$active = fn($u) => (int) scalar('SELECT COUNT(*) FROM pos_operator_sessions WHERE user_id=:u AND revoked_at IS NULL', [':u' => $u]);
check($r['status'] === 302 && (int) scalar('SELECT is_enabled FROM pos_operators WHERE user_id=:u', [':u' => $opA1]) === 0 && $active($opA1) === 0
    && (int) scalar("SELECT COUNT(*) FROM pos_operator_sessions WHERE user_id=:u AND revoked_reason='operator_disabled'", [':u' => $opA1]) === 2, 'D3: disable revokes operator sessions');
check($active($opB1) === 1, 'D3: other operators sessions untouched');
[$r] = submit(request($hrefA1), 'operator-enable');
check((int) scalar('SELECT is_enabled FROM pos_operators WHERE user_id=:u', [':u' => $opA1]) === 1 && $active($opA1) === 0, 'Re-enable does not revive sessions');
[$hrefConflict, $pageConflict] = $pages['conflict'];
check(str_contains($pageConflict['body'], 'non idonei') && !nodes($pageConflict, '//form[contains(@action,"operator-enable")]'), 'Incoherent operator shows no grant actions');
// POST forzato senza pulsante in pagina: il server ricontrolla il gate
$r = request(str_replace('/pos-management/operator?', '/pos-management/operator-enable?', $hrefConflict), ['_csrf' => csrf($pageConflict)]);
check($r['status'] === 302 && (int) scalar('SELECT is_enabled FROM pos_operators WHERE user_id=:u', [':u' => $opConflict]) === 0, 'Incoherent operator not enabled via forged POST');
check((int) scalar("SELECT COUNT(*) FROM logs WHERE id>:i AND event_type='security' AND description='Concessione POS rifiutata: operator-enable'", [':i' => $logStart]) === 1, 'Refused grant logged as security event');

// --- servizio: ID manipolati, gate di concessione, riduzioni
$scopeA = scopeOf($managerA);
check($scopeA !== null && $scopeA->merchantId === $merchantA, 'Manager scope is its single merchant');
fails(fn() => PosManagement::createTerminal($scopeA, $storeB1, 'X'), Failure::NOT_FOUND, 'Manager cannot create terminal in other merchant store');
fails(fn() => PosManagement::setOperatorEnabled($scopeA, $opB1, false), Failure::NOT_FOUND, 'Manager cannot touch other merchant operator');
fails(fn() => PosManagement::createGrant($scopeA, $opA1, $storeB1), Failure::NOT_FOUND, 'Cross-merchant grant rejected');
fails(fn() => PosManagement::createGrant($scopeA, $opA1, $storeA1), Failure::CONFLICT, 'Duplicate grant rejected');
fails(fn() => PosManagement::setOperatorEnabled($scopeA, $opConflict, true), Failure::NOT_ALLOWED, 'Member account never enabled as operator');
fails(fn() => PosManagement::createGrant($scopeA, $opConflict, $storeA2), Failure::NOT_ALLOWED, 'Member account never granted a store');
$scopeAdmin = scopeOf($admin);
check($scopeAdmin !== null && $scopeAdmin->isAdmin(), 'Admin scope covers all merchants');
$db->createCommand()->update('users', ['is_active'=>0], ['id'=>$admin])->execute();
try {
    check(Scope::current() === null, 'Admin scope rechecks active status even with cached session identity');
} finally { $db->createCommand()->update('users', ['is_active'=>1], ['id'=>$admin])->execute(); }
fails(fn() => PosManagement::createTerminal($scopeAdmin, $storeX1, 'X'), Failure::NOT_ALLOWED, 'No terminal on unqualified merchant');
fails(fn() => PosManagement::setOperatorEnabled($scopeAdmin, $opX, true), Failure::NOT_ALLOWED, 'No operator enabled on unqualified merchant');
PosManagement::setTerminalEnabled($scopeAdmin, $terminalX->public_id, false);
check((int) scalar('SELECT is_enabled FROM pos_terminals WHERE id=:i', [':i' => $terminalX->id]) === 0, 'Reduction allowed on unqualified merchant');
check(scopeOf($opA1) === null && scopeOf($managerX) === null, 'Operator and unqualified manager have no scope');
$scopeA = scopeOf($managerA);
$db->createCommand()->update('users', ['is_active' => 0], ['id' => $opA1])->execute();
fails(fn() => PosManagement::setGrantEnabled($scopeA, $opA1, $storeA1, true), Failure::NOT_ALLOWED, 'Inactive account never receives grants');
PosManagement::setGrantEnabled($scopeA, $opA1, $storeA1, false);
check((int) scalar('SELECT is_enabled FROM pos_store_operators WHERE user_id=:u AND store_id=:s', [':u' => $opA1, ':s' => $storeA1]) === 0, 'Grant disable allowed for inactive account');
$db->createCommand()->update('users', ['is_active' => 1], ['id' => $opA1])->execute();
// la titolarita cambia dopo la lettura: lo scope ricalcolato sotto lock la nega
$db->createCommand()->delete('member_profiles', ['user_id' => $managerA])->execute();
fails(fn() => PosManagement::setTerminalEnabled($scopeA, $uuid, false), Failure::NOT_FOUND, 'Stale manager scope rechecked under lock');
$pg($managerA, 'Fixture PG A');

// --- gara: stesso grant da due processi
$children = [];
foreach ([1, 2] as $n) {
    $process = proc_open([PHP_BINARY, __FILE__, '--race-grant', (string) $managerA, (string) $opA1, (string) $storeA2], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Race fixture unavailable.');
    stream_set_timeout($pipes[1], 20);
    if (fgets($pipes[1]) !== "ready\n") throw new RuntimeException('Race fixture did not initialize.');
    $children[] = [$process, $pipes];
}
foreach ($children as [$process, $pipes]) { fwrite($pipes[0], 'x'); fclose($pipes[0]); }
$codes = [];
foreach ($children as [$process, $pipes]) { fclose($pipes[1]); $codes[] = proc_close($process); }
sort($codes);
check($codes === [0, 10] && (int) scalar('SELECT COUNT(*) FROM pos_store_operators WHERE user_id=:u AND store_id=:s', [':u' => $opA1, ':s' => $storeA2]) === 1, 'Concurrent identical grants create one row');

// --- C4-RV1/RV2: cambi committati mentre la scrittura attende il lock del merchant (dopo il suo primo snapshot)
function whileMerchantLocked(int $merchantId, array $args, callable $change): int {
    global $db;
    $transaction = $db->beginTransaction();
    $db->createCommand('SELECT id FROM merchants WHERE id=:m FOR UPDATE', [':m' => $merchantId])->queryScalar();
    $process = proc_open(array_merge([PHP_BINARY, __FILE__, '--race-op'], array_map('strval', $args)), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes);
    if (!is_resource($process)) { $transaction->rollBack(); throw new RuntimeException('Race fixture unavailable.'); }
    stream_set_timeout($pipes[1], 20);
    if (fgets($pipes[1]) !== "ready\n") { $transaction->rollBack(); throw new RuntimeException('Race fixture did not initialize.'); }
    // il figlio apre la transazione, legge l'indizio e resta in attesa del lock
    usleep(1500000);
    $change();
    $transaction->commit();
    fclose($pipes[0]); fclose($pipes[1]);
    return proc_close($process);
}
$scopeA = scopeOf($managerA);
$pgC = $user('c4-pg-c', 30); $pg($pgC, 'Fixture PG C');
$code = whileMerchantLocked($merchantA, [$managerA, 'terminal-disable', $uuid],
    fn() => $db->createCommand()->update('merchants', ['user_id' => $pgC], ['id' => $merchantA])->execute());
check($code === 11 && (int) scalar('SELECT is_enabled FROM pos_terminals WHERE public_id=:p', [':p' => $uuid]) === 1, 'RV1: owner change committed during lock wait denies old manager');
$db->createCommand()->update('merchants', ['user_id' => $managerA], ['id' => $merchantA])->execute();
PosManagement::setOperatorEnabled($scopeA, $opA1, false);
$code = whileMerchantLocked($merchantA, [$managerA, 'operator-enable', $opA1],
    fn() => $db->createCommand()->update('users', ['is_active' => 0], ['id' => $opA1])->execute());
check($code === 12 && (int) scalar('SELECT is_enabled FROM pos_operators WHERE user_id=:u', [':u' => $opA1]) === 0, 'RV1: requirement revoked during lock wait blocks the grant');
$db->createCommand()->update('users', ['is_active' => 1], ['id' => $opA1])->execute();
$scopeA = scopeOf($managerA);
PosManagement::setOperatorEnabled($scopeA, $opA1, true);
$late = bin2hex(random_bytes(32));
$code = whileMerchantLocked($merchantA, [$managerA, 'operator-disable', $opA1], function () use ($db, $opA1, $late) {
    $db->createCommand()->insert('pos_operator_sessions', ['user_id' => $opA1, 'token_sha256' => $late, 'client_key_prefix' => bin2hex(random_bytes(8)),
        'credential_fingerprint' => bin2hex(random_bytes(32)), 'created_at' => time(), 'expires_at' => time() + 3600])->execute();
});
check($code === 0 && scalar('SELECT revoked_reason FROM pos_operator_sessions WHERE token_sha256=:t', [':t' => $late]) === 'operator_disabled'
    && $active($opA1) === 0, 'RV2: session committed after snapshot is revoked by disable');
PosManagement::setOperatorEnabled($scopeA, $opA1, true);
check($active($opA1) === 0, 'RV2: re-enable never revives revoked sessions');

// --- admin e registro
check(login('admin@example.invalid') === 302, 'Admin logged in');
$index = request('/pos-management/index');
check($index['status'] === 200 && str_contains($index['body'], 'Cassa B') && str_contains($index['body'], 'C4 merchant B'), 'Admin sees all merchants');
check(str_contains($index['body'], 'href="/pos-management/index"') && !str_contains($index['body'], 'href="/pos/index"'), 'Admin sidebar links to integrated POS backoffice');
$logs = $db->createCommand('SELECT event_type, description FROM logs WHERE id>:i AND controller=:c', [':i' => $logStart, ':c' => 'pos-management'])->queryAll();
$data = array_filter($logs, fn($l) => $l['event_type'] === 'data');
check(count($data) >= 6, 'Writes audited as data events');
check(!array_filter($logs, fn($l) => str_contains($l['description'], '@example.invalid') || str_contains($l['description'], 'password')), 'Audit has no emails or credentials');
check((int) scalar('SELECT COUNT(*) FROM pos_operators') === 4 && (int) scalar('SELECT COUNT(*) FROM member_profiles WHERE user_id IN (' . implode(',', [$opA1, $opB1, $opX]) . ')') === 0,
    'No profiles or memberships created implicitly');
echo "PASS $checks C4 backoffice checks\n";
