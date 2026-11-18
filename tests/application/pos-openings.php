<?php
// SIP-021 fase 2 (APERTURE-CONTRATTO): aperture esclusive POS, HTTP reale + DB usa e getta. Mai DB condivisi.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
$app = new yii\web\Application(require $root . '/config/web.php');
$db = $app->db;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);
$key = env_required('NAPAY_TEST_POS_CLIENT_KEY');
$checks = 0; $jar = null; $secrets = [$key, 'fixture-password-only'];

function check($condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks; echo 'PASS: ' . $label . "\n";
}
function fresh(): void { global $app; if (!$app->cache->flush()) throw new RuntimeException('Cache reset failed.'); }
function uuid(): string {
    $b = random_bytes(16); $b[6] = chr(ord($b[6]) & 0x0f | 0x40); $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
/** Handle cURL per l'API POS (usato singolo o in parallelo). */
function apiHandle(string $method, string $path, ?string $token, ?string $body = null, ?string $idempotency = null) {
    global $key;
    $headers = ['Accept: application/json', 'X-POS-Client-Key: ' . $key];
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    if ($idempotency !== null) $headers[] = 'Idempotency-Key: ' . $idempotency;
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    $curl = curl_init('http://127.0.0.1/api/pos/v1/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '',
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    return $curl;
}
function result($curl, ?string $raw): array {
    if (!is_string($raw)) throw new RuntimeException('HTTP transport failed.');
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $r = ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    $r['json'] = json_decode($r['body'], true);
    return $r;
}
function api(string $method, string $path, ?string $token, ?array $body = null, ?string $idempotency = null, ?string $rawBody = null): array {
    $curl = apiHandle($method, $path, $token, $rawBody ?? ($body === null ? null : json_encode($body)), $idempotency);
    $r = result($curl, curl_exec($curl) ?: null);
    curl_close($curl);
    return $r;
}
function code(array $r): ?string { return $r['json']['error']['code'] ?? null; }
function apiLogin(string $email): string {
    global $secrets;
    fresh();
    $r = api('POST', 'sessions', null, ['email' => $email, 'password' => 'fixture-password-only']);
    if ($r['status'] !== 201) throw new RuntimeException('Fixture login failed: ' . $email);
    $secrets[] = $r['json']['access_token'];
    return $r['json']['access_token'];
}
function open(string $token, string $terminal, ?string $instance = null, ?string $idempotency = null): array {
    return api('POST', 'terminals/' . $terminal . '/openings', $token, ['instance_id' => $instance ?? uuid()], $idempotency ?? uuid());
}
function proof(array $opening): array { return ['instance_id' => $opening['instance_id'], 'generation' => $opening['generation']]; }
function noLeak(array $r): bool {
    global $secrets;
    foreach ($secrets as $secret) if (str_contains($r['body'], $secret)) return false;
    return !str_contains($r['body'], 'PO') && str_contains(strtolower($r['headers']), 'cache-control: no-store');
}
function scalar(string $sql, array $p = []) { global $db; return $db->createCommand($sql, $p)->queryScalar(); }
function opening(string $publicId): array { global $db; return $db->createCommand('SELECT * FROM pos_terminal_openings WHERE public_id=:p', [':p' => $publicId])->queryOne(); }
// Backoffice web: login con cookie jar e CSRF.
function web(string $path, ?array $form = null): array {
    global $jar;
    $curl = curl_init('http://127.0.0.1' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20,
        CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($form !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form)]);
    $r = result($curl, curl_exec($curl) ?: null);
    curl_close($curl);
    return $r;
}
function csrf(array $page): string {
    if (!preg_match('/<meta name="csrf-token" content="([^"]+)"/', $page['body'], $m)) throw new RuntimeException('CSRF unavailable.');
    return html_entity_decode($m[1]);
}
function webLogin(string $email): void {
    global $jar;
    $jar = tempnam(sys_get_temp_dir(), 'napay-openings-');
    fresh();
    $page = web('/site/login');
    if (web('/site/login', ['_csrf' => csrf($page), 'LoginForm' => ['email' => $email, 'password' => 'fixture-password-only']])['status'] !== 302) throw new RuntimeException('Web login failed.');
}
function unlock(string $terminal): array { $page = web('/pos-management/index'); return web('/pos-management/terminal-unlock?id=' . $terminal, ['_csrf' => csrf($page)]); }

// --- Fixture: merchant A (gestore runtime-merchant) e B, operatori dedicati, negozi e terminali propri
$hash = password_hash('fixture-password-only', PASSWORD_BCRYPT);
$level = fn(int $l) => (int) scalar('SELECT id FROM privileges WHERE level=:l ORDER BY id LIMIT 1', [':l' => $l]);
$user = function (string $name, int $privilegeLevel) use ($db, $level, $hash): int {
    $db->createCommand()->insert('users', ['username' => $name, 'email' => $name . '@example.invalid', 'password' => $hash, 'first_name' => 'PO', 'last_name' => $name,
        'oauth_provider' => 'fixture', 'oauth_uid' => $name, 'authKey' => 'fixture-' . $name, 'accessToken' => 'fixture-' . $name, 'picture' => '/favicon.ico',
        'privilege_id' => $level($privilegeLevel), 'is_merchant' => 0, 'is_active' => 1])->execute();
    return (int) $db->lastInsertID;
};
$base = ['email' => 'fixture@example.invalid', 'phone' => '', 'mobile' => '', 'addressStreet' => '', 'addressNumberHouse' => '', 'addressCity' => '', 'addressZip' => '', 'addressProvince' => '', 'addressCountry' => ''];
$merchant = function (int $owner, string $name) use ($db, $base): int {
    $db->createCommand()->insert('merchants', ['user_id' => $owner, 'description' => $name, 'vatNumber' => '12345678901'] + $base)->execute();
    return (int) $db->lastInsertID;
};
$store = function (int $merchantId, string $name) use ($db, $base): int {
    $db->createCommand()->insert('stores', ['merchant_id' => $merchantId, 'description' => $name] + $base)->execute();
    return (int) $db->lastInsertID;
};
$now = time(); $on = ['is_enabled' => 1, 'created_at' => $now, 'updated_at' => $now];
$managerA = (int) scalar("SELECT id FROM users WHERE username='runtime-merchant'");
$merchantA = (int) scalar("SELECT id FROM merchants WHERE description='Runtime company merchant'");
$db->createCommand()->insert('member_profiles', ['user_id' => $managerA, 'is_corporate' => 1, 'denomination' => 'Openings PG A'])->execute();
$managerB = $user('po-manager-b', 30);
$db->createCommand()->insert('member_profiles', ['user_id' => $managerB, 'is_corporate' => 1, 'denomination' => 'Openings PG B'])->execute();
$merchantB = $merchant($managerB, 'Openings merchant B');
$storeA1 = $store($merchantA, 'Openings A1'); $storeA2 = $store($merchantA, 'Openings A2'); $storeB1 = $store($merchantB, 'Openings B1');
$opU = $user('po-op-u', 0); $opV = $user('po-op-v', 0); $opB = $user('po-op-b', 0);
foreach ([[$opU, $merchantA], [$opV, $merchantA], [$opB, $merchantB]] as [$u, $m]) $db->createCommand()->insert('pos_operators', ['user_id' => $u, 'merchant_id' => $m] + $on)->execute();
foreach ([[$opU, $merchantA, $storeA1], [$opU, $merchantA, $storeA2], [$opV, $merchantA, $storeA1], [$opB, $merchantB, $storeB1]] as [$u, $m, $s]) {
    $db->createCommand()->insert('pos_store_operators', ['user_id' => $u, 'merchant_id' => $m, 'store_id' => $s] + $on)->execute();
}
$terminal = function (int $merchantId, int $storeId, string $name, int $enabled = 1) use ($db, $now): array {
    $id = uuid();
    $db->createCommand()->insert('pos_terminals', ['public_id' => $id, 'merchant_id' => $merchantId, 'store_id' => $storeId, 'name' => $name,
        'is_enabled' => $enabled, 'created_at' => $now, 'updated_at' => $now])->execute();
    return ['id' => (int) $db->lastInsertID, 'public' => $id];
};
$tA1 = $terminal($merchantA, $storeA1, 'Cassa A1'); $tA2 = $terminal($merchantA, $storeA2, 'Cassa A2');
$tB = $terminal($merchantB, $storeB1, 'Cassa B'); $tOff = $terminal($merchantA, $storeA1, 'Cassa spenta', 0);
check((int) scalar('SELECT COUNT(*) FROM pos_terminal_openings') === 0, 'Schema present, no openings provisioned');
$u1 = apiLogin('po-op-u@example.invalid'); $u2 = apiLogin('po-op-u@example.invalid'); $v = apiLogin('po-op-v@example.invalid'); $b = apiLogin('po-op-b@example.invalid');
fresh();

// --- Validazione e scope: nulla viene creato
$instance = uuid();
check(api('POST', 'terminals/' . $tA1['public'] . '/openings', $u1, ['instance_id' => $instance])['status'] === 400, 'Missing Idempotency-Key refused');
foreach ([['instance_id' => 'not-a-uuid'], ['instance_id' => $instance, 'force' => true], ['instance_id' => strtoupper($instance)], ['instance_id' => 5]] as $bad) {
    check(code(api('POST', 'terminals/' . $tA1['public'] . '/openings', $u1, $bad, uuid())) === 'validation_failed', 'Malformed opening body refused: ' . json_encode($bad));
}
check(code(api('POST', 'terminals/' . $tA1['public'] . '/openings', $u1, null, uuid(), '{"instance_id":"' . $instance . '"')) === 'validation_failed', 'Truncated JSON refused');
foreach (['not-a-uuid' => 'malformed id', uuid() => 'unknown terminal', $tB['public'] => 'other merchant terminal', $tOff['public'] => 'disabled terminal'] as $id => $label) {
    $r = open($u1, $id);
    check($r['status'] === 404 && code($r) === 'not_found' && noLeak($r), 'Out of scope: ' . $label . ' is 404');
}
check(code(open($v, $tA2['public'])) === 'not_found', 'Ungranted store terminal is 404');
check(code(api('POST', 'terminals/' . $tA1['public'] . '/openings', null, ['instance_id' => $instance], uuid())) === 'invalid_token', 'Bearer required');
check(api('DELETE', 'terminals/' . $tA1['public'] . '/openings', $u1)['status'] === 405, 'Other methods are 405');
check((int) scalar('SELECT COUNT(*) FROM pos_terminal_openings') === 0, 'Refused requests created nothing');

// --- Acquisizione, replay, conflitti
$key1 = uuid(); $inst1 = uuid();
$r = open($u1, $tA1['public'], $inst1, $key1);
$o1 = $r['json'];
check($r['status'] === 201 && array_keys($o1) === ['id', 'terminal_id', 'instance_id', 'generation', 'state'] && $o1['terminal_id'] === $tA1['public']
    && $o1['instance_id'] === $inst1 && $o1['generation'] === '1' && $o1['state'] === 'active' && noLeak($r), 'First opening: 201, exact projection, generation 1');
$replay = open($u1, $tA1['public'], $inst1, $key1);
check($replay['status'] === 200 && $replay['json'] === $o1, 'Idempotent replay returns the same opening');
check(code(open($u1, $tA1['public'], uuid(), $key1)) === 'idempotency_conflict', 'Same key with different body: 409 idempotency_conflict');
foreach ([[$u1, 'same session, new tab'], [$u2, 'same user, other session'], [$v, 'other operator']] as [$token, $label]) {
    $r = open($token, $tA1['public']);
    check($r['status'] === 409 && code($r) === 'terminal_in_use' && noLeak($r), 'Busy terminal: ' . $label . ' gets 409 without holder details');
}
check((int) scalar("SELECT COUNT(*) FROM pos_terminal_openings WHERE terminal_id=:t", [':t' => $tA1['id']]) === 1, 'Conflicts and replays never add or replace openings');
try {
    $row = opening($o1['id']); unset($row['id'], $row['active_marker']);
    $db->createCommand()->insert('pos_terminal_openings', array_replace($row, ['public_id' => uuid(), 'generation' => 99, 'idempotency_sha256' => hash('sha256', 'x')]))->execute();
    check(false, 'DB refuses second active opening');
} catch (yii\db\IntegrityException $e) { check((int) ($e->errorInfo[1] ?? 0) === 1062, 'DB UNIQUE refuses a second active opening even with a direct INSERT'); }

// --- Gara reale: richieste parallele (due worker PHP, due connessioni DB)
for ($round = 1; $round <= 3; ++$round) {
    $t = $terminal($merchantA, $storeA2, 'Cassa gara ' . $round);
    fresh();
    $multi = curl_multi_init(); $handles = [];
    foreach ([$u1, $u2] as $token) {
        $h = apiHandle('POST', 'terminals/' . $t['public'] . '/openings', $token, json_encode(['instance_id' => uuid()]), uuid());
        curl_multi_add_handle($multi, $h); $handles[] = $h;
    }
    do { $status = curl_multi_exec($multi, $running); if ($running) curl_multi_select($multi); } while ($running && $status === CURLM_OK);
    $codes = [];
    foreach ($handles as $h) { $codes[] = result($h, curl_multi_getcontent($h))['status']; curl_multi_remove_handle($multi, $h); curl_close($h); }
    curl_multi_close($multi);
    sort($codes);
    check($codes === [201, 409] && (int) scalar("SELECT COUNT(*) FROM pos_terminal_openings WHERE terminal_id=:t", [':t' => $t['id']]) === 1, "Concurrent acquisition round $round: exactly one 201, one 409");
}

// --- Verifica e heartbeat con fencing
fresh();
check(api('GET', 'openings/' . $o1['id'], $u1)['json'] === $o1, 'Owner GET returns the opening');
foreach ([[$u2, 'other session of same user'], [$v, 'other operator'], [$b, 'other merchant']] as [$token, $label]) {
    check(code(api('GET', 'openings/' . $o1['id'], $token)) === 'not_found', 'Opening of another session is 404: ' . $label);
}
$db->createCommand()->update('pos_terminal_openings', ['opened_at' => $now - 7200, 'last_seen_at' => $now - 100], ['public_id' => $o1['id']])->execute();
check(api('POST', 'openings/' . $o1['id'] . '/heartbeat', $u1, proof($o1))['status'] === 204 && (int) opening($o1['id'])['last_seen_at'] >= $now, 'Heartbeat with current proof records contact');
foreach ([['instance_id' => uuid(), 'generation' => '1'], ['instance_id' => $inst1, 'generation' => '2']] as $bad) {
    check(code(api('POST', 'openings/' . $o1['id'] . '/heartbeat', $u1, $bad)) === 'opening_invalid', 'Heartbeat with wrong fencing proof: 409');
}
check(code(api('POST', 'openings/' . $o1['id'] . '/heartbeat', $u2, proof($o1))) === 'not_found', 'Heartbeat from another session: 404');
check(code(api('POST', 'openings/' . $o1['id'] . '/heartbeat', $u1, ['instance_id' => $inst1])) === 'validation_failed', 'Heartbeat without generation refused');
$db->createCommand()->update('pos_terminal_openings', ['last_seen_at' => $now - 7200], ['public_id' => $o1['id']])->execute();
check(code(open($v, $tA1['public'])) === 'terminal_in_use', 'Stale heartbeat never unlocks the terminal');

// --- Sblocco manager dal backoffice
webLogin('po-op-u@example.invalid');
check(web('/pos-management/index')['status'] === 403, 'Operator cannot reach the backoffice');
webLogin('po-manager-b@example.invalid');
check(unlock($tA1['public'])['status'] === 404 && opening($o1['id'])['state'] === 'active', 'Manager of another merchant cannot unlock (404)');
webLogin('merchant@example.invalid');
$page = web('/pos-management/index');
check(str_contains($page['body'], 'In uso') && str_contains($page['body'], 'po-op-u') && str_contains($page['body'], 'terminal-unlock'), 'Backoffice shows holder, contact and unlock button');
check(web('/pos-management/terminal-unlock?id=' . $tA1['public'], ['_csrf' => 'forged'])['status'] === 400 && opening($o1['id'])['state'] === 'active', 'Unlock without valid CSRF refused');
check(web('/pos-management/terminal-unlock?id=' . $tA1['public'])['status'] === 405, 'Unlock via GET refused');
$r = unlock($tA1['public']);
$row = opening($o1['id']);
check($r['status'] === 302 && $row['state'] === 'revoked' && $row['closed_reason'] === 'manager_unlock' && (int) $row['closed_by'] === $managerA, 'Manager unlock revokes the opening and records who');
check((int) scalar("SELECT COUNT(*) FROM logs WHERE description LIKE :d", [':d' => '%' . $tA1['public'] . ' sbloccato dal manager%']) === 1, 'Unlock written to the activity register');
fresh();
foreach ([['GET', 'openings/' . $o1['id'], null], ['POST', 'openings/' . $o1['id'] . '/heartbeat', proof($o1)], ['POST', 'openings/' . $o1['id'] . '/release', proof($o1)]] as [$method, $path, $body]) {
    check(code(api($method, $path, $u1, $body)) === 'opening_invalid', 'Unlocked opening is dead: ' . $method . ' ' . basename($path));
}
check(code(open($u1, $tA1['public'], $inst1, $key1)) === 'opening_invalid', 'Replay of an unlocked acquisition never revives it');
$rv = open($v, $tA1['public']);
$ov = $rv['json'];
check($rv['status'] === 201 && $ov['generation'] === '2', 'After unlock another operator acquires with a higher generation');
check(unlock($tB['public'])['status'] === 404, 'Manager A cannot unlock merchant B terminals');

// --- Chiusura normale e logout
fresh();
check(api('POST', 'openings/' . $ov['id'] . '/release', $v, ['instance_id' => uuid(), 'generation' => $ov['generation']])['status'] === 409, 'Release with wrong instance refused');
check(api('POST', 'openings/' . $ov['id'] . '/release', $v, proof($ov))['status'] === 204 && opening($ov['id'])['closed_reason'] === 'released', 'Owner releases normally');
check(api('POST', 'openings/' . $ov['id'] . '/release', $v, proof($ov))['status'] === 204, 'Release replay is idempotent');
check(code(api('GET', 'openings/' . $ov['id'], $v)) === 'opening_invalid', 'Released opening no longer valid');
$o3 = open($u1, $tA1['public'])['json'];
check($o3['generation'] === '3', 'Released terminal acquired again (generation 3)');
check(api('DELETE', 'sessions', $u1)['status'] === 204 && opening($o3['id'])['closed_reason'] === 'logout', 'Logout closes the session openings');
fresh();
$o4 = open($v, $tA1['public'])['json'];
check(($o4['generation'] ?? null) === '4', 'Terminal free after logout');

// --- Titolare che non può più agire: chiusura automatica approvata (owner_ineligible)
$db->createCommand()->update('pos_store_operators', ['is_enabled' => 0], ['user_id' => $opV, 'store_id' => $storeA1])->execute();
check(code(api('GET', 'openings/' . $o4['id'], $v)) === 'opening_invalid', 'Grant removal makes the holder opening invalid');
$o5 = open($u2, $tA1['public']);
check($o5['status'] === 201 && opening($o4['id'])['closed_reason'] === 'owner_ineligible', 'Acquisition closes an opening whose holder lost the grant');
$db->createCommand()->update('pos_store_operators', ['is_enabled' => 1], ['user_id' => $opV, 'store_id' => $storeA1])->execute();
$db->createCommand()->update('pos_operator_sessions', ['expires_at' => time() - 1], ['token_sha256' => hash('sha256', $u2)])->execute();
fresh();
$o6 = open($v, $tA1['public']);
check($o6['status'] === 201 && opening($o5['json']['id'])['closed_reason'] === 'owner_ineligible', 'Acquisition closes an opening whose session expired');
$v2 = apiLogin('po-op-v@example.invalid');
check(code(open($v2, $tA1['public'])) === 'terminal_in_use', 'Live holder still blocks even the same operator from another session');

// --- Nessun dato sensibile nei log applicativi
$logs = implode("\n", $db->createCommand('SELECT description FROM logs')->queryColumn());
$leak = false; foreach ($secrets as $secret) $leak = $leak || str_contains($logs, $secret);
check(!$leak, 'Activity register holds no token, client key or password');

echo "PASS $checks POS exclusive opening checks\n";
