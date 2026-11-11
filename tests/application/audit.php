<?php
// Activity register (components/Log + components/audit, as in Aura) over real HTTP: disposable runtime DB only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
$app = new yii\web\Application(require $root . '/config/web.php');
$db = $app->db;
$checks = 0; $jar = null;
function check($condition, $label) {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks; echo 'PASS: ' . $label . "\n";
}
function request($path, ?array $form = null, array $headers = []) {
    global $jar;
    $curl = curl_init('http://127.0.0.1' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => $headers]);
    if ($form !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form)]);
    $raw = curl_exec($curl);
    if ($raw === false) throw new RuntimeException('Audit HTTP transport failed.');
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $result = ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    curl_close($curl); return $result;
}
function csrf($page) {
    $doc = new DOMDocument(); @$doc->loadHTML($page['body'], LIBXML_NONET);
    $nodes = (new DOMXPath($doc))->query('//meta[@name="csrf-token"]/@content');
    if ($nodes->length !== 1) throw new RuntimeException('Audit CSRF unavailable.');
    return $nodes->item(0)->nodeValue;
}
function login($role) {
    global $jar;
    $jar = tempnam('/tmp', 'napay-audit-');
    $page = request('/site/login');
    return request('/site/login', ['_csrf' => csrf($page), 'LoginForm' => ['email' => $role . '@example.invalid', 'password' => 'fixture-password-only']])['status'];
}
function lastLog($sinceId, $like) {
    global $db;
    return $db->createCommand('SELECT * FROM logs WHERE id>:id AND description LIKE :d ORDER BY id DESC', [':id' => $sinceId, ':d' => $like])->queryOne() ?: null;
}
function maxId() { global $db; return (int) $db->createCommand('SELECT COALESCE(MAX(id),0) FROM logs')->queryScalar(); }
function userId($role) { global $db; return (int) $db->createCommand('SELECT id FROM users WHERE username=:u', [':u' => 'runtime-' . $role])->queryScalar(); }

$start = maxId();
$jar = tempnam('/tmp', 'napay-audit-');
request('/association/members', null, ['X-Forwarded-For: 203.0.113.66', 'Client-IP: 203.0.113.67']);
$guest = lastLog($start, 'Accesso negato a association/members (utente non autenticato)');
check($guest && $guest['event_type'] === 'security' && $guest['user_id'] === null, 'Guest denial logged as security event without a fake user');
check($guest['remote_address'] === '127.0.0.1', 'Spoofed X-Forwarded-For/Client-IP ignored without trusted proxy');

$mark = maxId();
check(login('user') === 302, 'User login');
request('/association/payments');
$denied = lastLog($mark, 'Accesso negato a association/payments (utente senza permesso)');
check($denied && (int) $denied['user_id'] === userId('user'), 'Authenticated denial attributed to the user');
check(lastLog($mark, 'Accesso vietato (403)%') === null, 'Same denial not logged twice by the error handler');

$mark = maxId();
check(login('admin') === 302, 'Admin login');
check(request('/association/export', ['queryParams' => '[]'])['status'] === 400, 'Export without CSRF rejected');
check(lastLog($mark, 'Richiesta rifiutata: token CSRF non valido%') !== null, 'Invalid CSRF logged as security event');
$tokenMark = maxId();
check(request('/site/reset-password?token=audit-secret-token-123', ['x' => '1'])['status'] === 400, 'CSRF failure on a tokenized URL');
$tokenLog = lastLog($tokenMark, 'Richiesta rifiutata: token CSRF%');
check($tokenLog && str_ends_with($tokenLog['description'], ' /site/reset-password') && !str_contains(json_encode($db->createCommand('SELECT * FROM logs WHERE id>:id', [':id' => $tokenMark])->queryAll()), 'audit-secret-token'), 'Query-string tokens never stored in the register');
$page = request('/association/payments');
request('/association/export', ['_csrf' => csrf($page), 'queryParams' => '[]']);
$export = lastLog($mark, 'Richiesta esportazione association/export%');
check($export && $export['event_type'] === 'export' && (int) $export['user_id'] === userId('admin'), 'Export logged with its user');

// data changes: field names only, never values (CLI identity: anonymous, IPv6 kept whole)
$_SERVER['REMOTE_ADDR'] = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';
$mark = maxId();
$fee = new app\models\MembershipFees(['description' => 'Audit fixture', 'duration' => 1, 'amount' => '12.34']);
check($fee->save(), 'Fixture fee saved');
$created = lastLog($mark, 'Creato Quota #' . $fee->id);
check($created && $created['event_type'] === 'data' && $created['user_id'] === null, 'Creation logged as data event, anonymous CLI without fake user');
check($created['remote_address'] === '2001:0db8:85a3:0000:0000:8a2e:0370:7334', 'Full IPv6 address stored');
$fee->amount = '99.99'; $fee->description = 'Audit fixture'; $fee->save();
$updated = lastLog($mark, 'Modificato Quota #' . $fee->id . '%');
check($updated && $updated['description'] === 'Modificato Quota #' . $fee->id . ': amount', 'Update lists changed field names only');
check(!str_contains($updated['description'], '99.99') && !str_contains($updated['description'], '12.34'), 'Update never records values');
$fee->delete();
check(lastLog($mark, 'Eliminato Quota #' . $fee->id) !== null, 'Deletion logged');
check(app\components\Log::save('fixture', 'fixture', 'legacy signature still works') === true, 'Legacy Log::save signature compatible');

check(login('admin') === 302, 'Admin login for log viewer');
$viewer = request('/logs/index?' . http_build_query(['LogsSearch' => ['event_type' => 'security']]));
check($viewer['status'] === 200 && str_contains($viewer['body'], 'Sicurezza') && str_contains($viewer['body'], 'Accesso negato a association/payments'), 'Log viewer filters by event type');
echo "PASS {$checks} activity register checks\n";
