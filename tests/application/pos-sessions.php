<?php
// SIP-021 POST /api/pos/v1/sessions over real HTTP: disposable runtime DB, per-run random client keys.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
$app = new yii\web\Application(require $root . '/config/web.php');
$db = $app->db;
use app\components\pos\PosApi;
$key = env_required('NAPAY_TEST_POS_CLIENT_KEY');
$rotated = env_required('NAPAY_TEST_POS_CLIENT_KEY_2');
$checks = 0;
function check($condition, $label) {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks; echo 'PASS: ' . $label . "\n";
}
function api($body, ?string $clientKey, array $headers = [], string $method = 'POST', string $type = 'application/json') {
    $curl = curl_init('http://127.0.0.1/api/pos/v1/sessions');
    $list = array_merge($type === '' ? [] : ['Content-Type: ' . $type], $clientKey === null ? [] : ['X-POS-Client-Key: ' . $clientKey], $headers);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '',
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $list]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    $raw = curl_exec($curl);
    if ($raw === false) throw new RuntimeException('POS API transport failed.');
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $result = ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    curl_close($curl);
    $result['json'] = json_decode($result['body'], true);
    return $result;
}
function code($r) { return $r['json']['error']['code'] ?? null; }
function fresh() { global $app; $app->cache->flush(); }
// operator IP per chiamata: il limite per IP (10/min) non interferisce con gli altri casi
function op() { static $n = 0; $n++; return ['X-POS-Operator-IP: 198.51.100.' . (1 + $n % 250)]; }
$good = ['email' => 'user@example.invalid', 'password' => 'fixture-password-only'];
$secrets = [$key, $rotated, 'fixture-password-only', 'wrong-password-value'];

fresh();
// login valido
$ok = api($good, $key, op());
check($ok['status'] === 201 && ($ok['json']['token_type'] ?? null) === 'Bearer', 'Valid login: 201 Bearer session');
$token = $ok['json']['access_token'];
check(preg_match('/^napos_[A-Za-z0-9_-]{43}$/D', $token) === 1, 'Opaque token: napos_ + 32 random bytes base64url');
check(array_keys($ok['json']) === ['access_token', 'token_type', 'expires_at', 'operator'] && array_keys($ok['json']['operator']) === ['display_name'], 'Exact response shape, no operator id');
$expires = strtotime($ok['json']['expires_at']);
check(preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $ok['json']['expires_at']) === 1 && abs($expires - (time() + PosApi::DEFAULT_TTL)) < 30, 'Expiry 12 hours, ISO 8601 UTC');
foreach (['cache-control: no-store', 'pragma: no-cache', 'x-content-type-options: nosniff', 'content-type: application/json'] as $h) {
    check(str_contains(strtolower($ok['headers']), $h), 'Header ' . $h);
}
check(!preg_match('/^set-cookie:/mi', $ok['headers']) && !preg_match('/^access-control-/mi', $ok['headers']), 'No cookies, no CORS headers');
check(api($good, $rotated, op())['status'] === 201, 'Second configured key (rotation) accepted');

// token solo come digest, sessione legata a client e credenziali
$row = $db->createCommand('SELECT * FROM pos_operator_sessions WHERE token_sha256=:d', [':d' => hash('sha256', $token)])->queryOne();
check($row && !str_contains(json_encode($db->createCommand('SELECT * FROM pos_operator_sessions')->queryAll()), $token), 'Token persisted only as SHA-256 digest');
check($row['client_key_prefix'] === substr(hash('sha256', $key), 0, 16) && $row['created_ip'] === '198.51.100.2', 'Session bound to client key prefix, operator IP recorded');
$prefix = $row['client_key_prefix'];
check(PosApi::findActiveSession($token, $prefix)?->id == $row['id'], 'Active session found by token');
check(PosApi::findActiveSession($token, substr(hash('sha256', $rotated), 0, 16)) === null, 'Session not usable by another client key');
check(PosApi::findActiveSession($token . 'x', $prefix) === null && PosApi::findActiveSession('napos_' . str_repeat('A', 43), $prefix) === null, 'Altered or unknown token rejected');

// credenziali errate: stesso 401 e stesso corpo in ogni caso
$invalid = [
    'wrong password' => ['email' => 'user@example.invalid', 'password' => 'wrong-password-value'],
    'unknown account' => ['email' => 'nobody-here@example.invalid', 'password' => 'fixture-password-only'],
    'disabled account' => ['email' => 'disabled@example.invalid', 'password' => 'fixture-password-only'],
    'blocked account' => ['email' => 'blocked@example.invalid', 'password' => 'fixture-password-only'],
];
$twin = $db->createCommand("SELECT * FROM users WHERE username='runtime-other'")->queryOne();
unset($twin['id']); $twin['username'] = 'pos-ambiguous-twin';
$db->createCommand()->insert('users', $twin)->execute();
$invalid['ambiguous email'] = ['email' => 'other@example.invalid', 'password' => 'fixture-password-only'];
$bodies = [];
foreach ($invalid as $case => $payload) {
    fresh();
    $r = api($payload, $key, op());
    check($r['status'] === 401 && code($r) === 'invalid_credentials', "Generic 401 invalid_credentials: $case");
    $bodies[] = $r['body'];
}
$db->createCommand()->delete('users', ['username' => 'pos-ambiguous-twin'])->execute();
check(count(array_unique($bodies)) === 1, 'Identical error body for all credential failures');

// servizio assente o non valido
fresh();
foreach (['missing key' => null, 'wrong key' => 'not-the-configured-key', 'oversized key' => str_repeat('k', 300)] as $case => $clientKey) {
    $r = api($good, $clientKey, op());
    check($r['status'] === 401 && code($r) === 'unauthenticated', "Service authentication: $case");
}

// payload malformati
fresh();
$malformed = [
    'not json' => '{email:', 'json list' => '["a","b"]', 'scalar' => '"x"', 'empty' => '',
    'array email' => ['email' => ['a@example.invalid'], 'password' => 'x'], 'array password' => ['email' => 'user@example.invalid', 'password' => ['x']],
    'numeric password' => ['email' => 'user@example.invalid', 'password' => 123], 'null email' => ['email' => null, 'password' => 'x'],
    'missing password' => ['email' => 'user@example.invalid'], 'extra field' => $good + ['sin' => 'x'],
    'empty password' => ['email' => 'user@example.invalid', 'password' => ''],
    'long email' => ['email' => str_repeat('a', 250) . '@x.it', 'password' => 'x'],
    'long password' => ['email' => 'user@example.invalid', 'password' => str_repeat('p', 1025)],
    'oversized body' => '{"email":"user@example.invalid","password":"' . str_repeat('p', 5000) . '"}',
    'deep json' => ['email' => 'user@example.invalid', 'password' => 'x', 'n' => ['a' => ['b' => ['c' => 1]]]],
];
foreach ($malformed as $case => $body) {
    $r = api($body, $key, op());
    check($r['status'] === 400 && code($r) === 'validation_failed', "Malformed payload rejected: $case");
}
check(code(api($good, $key, op(), 'POST', 'text/plain')) === 'validation_failed' && code(api($good, $key, op(), 'POST', '')) === 'validation_failed', 'Content-Type application/json required');
check(api($good, $key, ['X-POS-Operator-IP: not-an-ip'])['status'] === 400, 'Invalid operator IP header rejected');
foreach (['GET', 'PUT', 'DELETE'] as $method) {
    $r = api(null, $key, op(), $method);
    check($r['status'] === 405 && !str_contains($r['body'], 'napos_') && stripos($r['headers'], 'set-cookie') === false, "Method $method not allowed");
}
check(api($good, $key, op(), 'POST')['status'] === 201, 'Login still works after malformed attempts');
check(file_get_contents('http://127.0.0.1/posapi/sessions/create', false, stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true]])) !== false
    && str_contains($http_response_header[0] ?? '', '404'), 'Module default route not reachable (only /api/pos/v1)');

// rate limit
fresh();
for ($i = 0; $i < 5; $i++) api(['email' => 'user@example.invalid', 'password' => 'wrong-password-value'], $key, op());
$locked = api($good, $key, op());
check($locked['status'] === 429 && code($locked) === 'rate_limited' && preg_match('/^retry-after: ([1-9]\d*)/mi', $locked['headers'], $m) && (int) $m[1] <= 900, 'Account: 5 failures in 15 minutes lock even the right password, Retry-After');
check(api(['email' => 'admin@example.invalid', 'password' => 'fixture-password-only'], $key, op())['status'] === 201, 'Lock limited to that account');
fresh();
$sameIp = ['X-POS-Operator-IP: 203.0.113.77'];
for ($i = 0; $i < 10; $i++) api(['email' => "ip$i@example.invalid", 'password' => 'x'], $key, $sameIp);
$ipLocked = api($good, $key, $sameIp);
check($ipLocked['status'] === 429 && stripos($ipLocked['headers'], 'retry-after:') !== false, 'Operator IP: 10 attempts per minute');
check(api($good, $key, op())['status'] === 201, 'Other operator IPs unaffected');
fresh();
for ($i = 0; $i < 60; $i++) api('{', $key, op());
check(api($good, $key, op())['status'] === 429, 'Client key: 60 attempts per minute');
check(api($good, $rotated, op())['status'] === 201, 'Other client key unaffected');
fresh();

// scadenza e revoca
$db->createCommand()->update('pos_operator_sessions', ['expires_at' => time() - 1], ['id' => $row['id']])->execute();
check(PosApi::findActiveSession($token, $prefix) === null, 'Expired session rejected');
$db->createCommand()->update('pos_operator_sessions', ['expires_at' => time() + 3600], ['id' => $row['id']])->execute();
$session = PosApi::findActiveSession($token, $prefix);
PosApi::revoke($session, 'test');
check(PosApi::findActiveSession($token, $prefix) === null, 'Revoked session rejected');
$fresh = api($good, $key, op())['json']['access_token'];
$userId = (int) $db->createCommand("SELECT id FROM users WHERE username='runtime-user'")->queryScalar();
$db->createCommand()->update('users', ['is_active' => 0], ['id' => $userId])->execute();
check(PosApi::findActiveSession($fresh, $prefix) === null, 'Disabling the user revokes the session');
$db->createCommand()->update('users', ['is_active' => 1], ['id' => $userId])->execute();
check(PosApi::findActiveSession($fresh, $prefix) !== null, 'Re-enabled user: session valid again until expiry');
$hash = $db->createCommand('SELECT password FROM users WHERE id=:id', [':id' => $userId])->queryScalar();
$db->createCommand()->update('users', ['password' => password_hash('changed-password', PASSWORD_BCRYPT)], ['id' => $userId])->execute();
check(PosApi::findActiveSession($fresh, $prefix) === null, 'Password change revokes the session');
$db->createCommand()->update('users', ['password' => $hash], ['id' => $userId])->execute();

// configurazione: fail secure
putenv('POS_API_CLIENT_KEYS_SHA256='); $failed = false;
try { PosApi::authenticateClient($key); } catch (yii\base\InvalidConfigException $e) { $failed = true; }
check($failed, 'Unconfigured client keys disable the API');
putenv('POS_API_CLIENT_KEYS_SHA256=not-a-digest'); $failed = false;
try { PosApi::authenticateClient($key); } catch (yii\base\InvalidConfigException $e) { $failed = true; }
check($failed, 'Malformed client key configuration disables the API');
putenv('POS_SESSION_TTL_SECONDS=999999'); $failed = false;
try { PosApi::ttl(); } catch (yii\base\InvalidConfigException $e) { $failed = true; }
check($failed, 'TTL above 24 hours rejected');

// nessun segreto nei registri
$register = json_encode($db->createCommand('SELECT description, controller, action FROM logs')->queryAll());
$files = '';
foreach (glob($root . '/runtime/logs/*.log') ?: [] as $file) $files .= file_get_contents($file);
$leaks = array_filter(array_merge($secrets, [$token, $fresh]), fn($s) => str_contains($register, $s) || str_contains($files, $s));
check($leaks === [], 'No password, token or client key in activity register or log files');
check(str_contains($register, 'Login POS fallito') && str_contains($register, 'Sessione POS #'), 'Failures and sessions audited');
echo "PASS {$checks} POS session API checks\n";
