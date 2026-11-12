<?php
// SIP-021 C2: real HTTP and DB; never run against a shared or imported live database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php';
require $root . '/vendor/yiisoft/yii2/Yii.php';
$app = new yii\web\Application(require $root . '/config/web.php');
$db = $app->db;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);
$key = env_required('NAPAY_TEST_POS_CLIENT_KEY');
$rotated = env_required('NAPAY_TEST_POS_CLIENT_KEY_2');
$checks = 0;
$secrets = [$key, $rotated, 'fixture-password-only'];

function tcCheck($condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks;
    echo 'PASS: ' . $label . "\n";
}
function tcFresh(): void {
    global $app;
    if (!$app->cache->flush()) throw new RuntimeException('Isolated cache reset failed.');
}
function tcHttp(string $path, ?string $client, ?string $token = null, string $method = 'GET', ?string $body = null, ?string $authorization = null): array {
    $headers = ['Accept: application/json'];
    if ($client !== null) $headers[] = 'X-POS-Client-Key: ' . $client;
    if ($authorization !== null) $headers[] = 'Authorization: ' . $authorization;
    elseif ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    $curl = curl_init('http://127.0.0.1/api/pos/v1/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HEADER=>true, CURLOPT_TIMEOUT=>20,
        CURLOPT_PROXY=>'', CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$headers]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    if ($method === 'HEAD') curl_setopt($curl, CURLOPT_NOBODY, true);
    $raw = curl_exec($curl);
    if ($raw === false) { curl_close($curl); throw new RuntimeException('Catalog HTTP transport failed.'); }
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $result = ['status'=>(int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers'=>substr($raw, 0, $size), 'body'=>substr($raw, $size)];
    curl_close($curl);
    $result['json'] = json_decode($result['body'], true);
    return $result;
}
function tcGet(string $token, string $query = '', ?string $client = null): array {
    global $key;
    return tcHttp('terminals' . ($query === '' ? '' : '?' . $query), $client ?? $key, $token);
}
function tcHeaders(array $response): bool {
    $headers = strtolower($response['headers']);
    return str_contains($headers, 'cache-control: no-store') && str_contains($headers, 'pragma: no-cache')
        && str_contains($headers, 'content-type: application/json')
        && !preg_match('/^(?:set-cookie:|access-control-)/mi', $headers);
}
function tcError(array $response, int $status, string $code, string $label): void {
    $messages = ['unauthenticated'=>'Client authentication required.', 'invalid_token'=>'Operator authentication required.',
        'validation_failed'=>'Invalid request.', 'rate_limited'=>'Too many attempts. Retry later.', 'backend_unavailable'=>'Service unavailable.'];
    tcCheck($response['status'] === $status && $response['json'] === ['error'=>['code'=>$code,'message'=>$messages[$code]]]
        && tcHeaders($response), $label);
}
function tcPage(array $response, string $label): array {
    global $secrets;
    $page = $response['json'];
    $valid = $response['status'] === 200 && tcHeaders($response) && strlen($response['body']) <= 16384
        && is_array($page) && array_keys($page) === ['items','next_cursor'] && is_array($page['items']) && array_is_list($page['items'])
        && ($page['next_cursor'] === null || (is_string($page['next_cursor']) && preg_match('/^[A-Za-z0-9_-]{1,512}$/D', $page['next_cursor'])));
    foreach ($page['items'] ?? [] as $item) {
        $valid = $valid && is_array($item) && array_keys($item) === ['id','store_name','name','availability']
            && is_string($item['id']) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $item['id'])
            && $item['availability'] === 'unknown';
        foreach (['store_name','name'] as $field) {
            $valid = $valid && is_string($item[$field]) && mb_check_encoding($item[$field], 'UTF-8')
                && mb_strlen($item[$field]) <= 256 && preg_match('/\S/u', $item[$field]);
        }
    }
    foreach ($secrets as $secret) $valid = $valid && !str_contains($response['body'], $secret);
    tcCheck($valid, $label . ': exact public projection, UTF-8, no secrets/cookies/CORS, no-store, <=16KiB');
    return $page;
}
function tcLogin(string $email, string $client): string {
    global $secrets;
    // Login budgets must not interfere with independent catalog assertions.
    tcFresh();
    $response = tcHttp('sessions', $client, null, 'POST', json_encode(['email'=>$email,'password'=>'fixture-password-only']));
    tcCheck($response['status'] === 201 && is_string($response['json']['access_token'] ?? null)
        && preg_match('/^napos_[A-Za-z0-9_-]{43}$/D', $response['json']['access_token']), 'HTTP fixture login succeeds');
    $secrets[] = $response['json']['access_token'];
    return $response['json']['access_token'];
}
function tcDeniedLogin(string $email, string $client): void {
    global $db;
    tcFresh();
    $before = $db->createCommand('SELECT COUNT(*) FROM pos_operator_sessions')->queryScalar();
    $response = tcHttp('sessions', $client, null, 'POST', json_encode(['email'=>$email,'password'=>'fixture-password-only']));
    tcCheck($response['status'] === 401 && $response['json'] === ['error'=>['code'=>'invalid_credentials','message'=>'Invalid credentials.']]
        && tcHeaders($response), 'Ineligible account cannot log into POS: ' . $email);
    tcCheck($db->createCommand('SELECT COUNT(*) FROM pos_operator_sessions')->queryScalar() === $before, 'Denied POS login persists no session');
}
function tcLegacyToken(int $userId): string {
    global $db, $key, $secrets;
    $token = 'napos_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $password = $db->createCommand('SELECT password FROM users WHERE id=:id', [':id'=>$userId])->queryScalar();
    $db->createCommand()->insert('pos_operator_sessions', ['user_id'=>$userId,'token_sha256'=>hash('sha256',$token),
        'client_key_prefix'=>substr(hash('sha256',$key),0,16),'credential_fingerprint'=>hash('sha256','pos-session|'.$password),
        'created_at'=>time(),'expires_at'=>time()+3600])->execute();
    $secrets[] = $token;
    return $token;
}
function tcRow(string $token): array {
    global $db;
    return $db->createCommand('SELECT * FROM pos_operator_sessions WHERE token_sha256=:d', [':d'=>hash('sha256', $token)])->queryOne();
}

// Own catalog fixtures: do not depend on pos-catalog-schema.php or its mutations.
$users = array_column($db->createCommand("SELECT username,id FROM users WHERE username IN ('runtime-user','runtime-other','runtime-admin','runtime-merchant')")->queryAll(), 'id', 'username');
$companies = array_column($db->createCommand("SELECT description,id FROM merchants WHERE description IN ('Runtime company merchant','Runtime company other')")->queryAll(), 'id', 'description');
tcCheck(count($users) === 4 && count($companies) === 2, 'Reviewed runtime identities and merchant fixtures available');
$u = (int)$users['runtime-user']; $otherUser = (int)$users['runtime-other'];
$a = (int)$companies['Runtime company merchant']; $b = (int)$companies['Runtime company other'];
$ownerA = (int)$users['runtime-merchant'];
$db->createCommand()->insert('member_profiles', ['user_id'=>$ownerA,'is_corporate'=>1,'denomination'=>'C2 merchant A PG'])->execute();
// B's former owner becomes a dedicated operator, never both manager and operator.
$ownerB = $db->createCommand('SELECT * FROM users WHERE id=:id', [':id'=>$ownerA])->queryOne();
unset($ownerB['id'], $ownerB['email_normalized']);
$ownerB['username'] = 'c2-owner-b';
$ownerB['email'] = 'c2-owner-b@example.invalid';
$db->createCommand()->insert('users', $ownerB)->execute();
$ownerBId = (int)$db->lastInsertID;
$db->createCommand()->insert('member_profiles', ['user_id'=>$ownerBId,'is_corporate'=>1,'denomination'=>'C2 merchant B PG'])->execute();
$db->createCommand()->update('merchants', ['user_id'=>$ownerBId], ['id'=>$b])->execute();
$now = time(); $enabled = ['is_enabled'=>1,'created_at'=>$now,'updated_at'=>$now];
foreach ([[$u,$a],[$otherUser,$b]] as [$user,$merchant]) $db->createCommand()->insert('pos_operators', ['user_id'=>$user,'merchant_id'=>$merchant]+$enabled)->execute();
$storeBase = ['email'=>'c2@example.invalid','phone'=>'','mobile'=>'','addressStreet'=>'','addressNumberHouse'=>'','addressCity'=>'','addressZip'=>'','addressProvince'=>'','addressCountry'=>''];
$stores = []; $storeNames = ['C2 <negozio & uno>','C2 negozio due','C2 senza grant','C2 altro merchant'];
foreach ([$a,$a,$a,$b] as $i=>$merchant) {
    $db->createCommand()->insert('stores', ['merchant_id'=>$merchant,'description'=>$storeNames[$i]]+$storeBase)->execute();
    $stores[] = (int)$db->lastInsertID;
}
foreach ([[$u,$a,$stores[0]],[$u,$a,$stores[1]],[$otherUser,$b,$stores[3]]] as [$user,$merchant,$store]) {
    $db->createCommand()->insert('pos_store_operators', ['user_id'=>$user,'merchant_id'=>$merchant,'store_id'=>$store]+$enabled)->execute();
}
$terminalSequence = 0;
$addTerminal = static function (int $storeIndex, string $name, int $on = 1) use ($db,$stores,$a,$b,$enabled,&$terminalSequence): array {
    // Deliberately descending UUIDs distinguish DB keyset ordering from public UUID sorting.
    $uuid = sprintf('ffffffff-ffff-4fff-8fff-%012x', 1000000 - ++$terminalSequence);
    $db->createCommand()->insert('pos_terminals', ['public_id'=>$uuid,'merchant_id'=>$storeIndex === 3 ? $b : $a,
        'store_id'=>$stores[$storeIndex],'name'=>$name,'is_enabled'=>$on]+$enabled)->execute();
    return ['internal_id'=>(int)$db->lastInsertID,'id'=>$uuid,'store_name'=>['C2 <negozio & uno>','C2 negozio due','C2 senza grant','C2 altro merchant'][$storeIndex],
        'name'=>$name,'availability'=>'unknown'];
};
$foreign = $addTerminal(3, 'C2 foreign first');
$expected = [$addTerminal(0, 'C2 cassa <uno>')];
$disabled = $addTerminal(0, 'C2 disabilitata', 0);
$ungranted = $addTerminal(2, 'C2 non autorizzata');
$expected[] = $addTerminal(1, 'C2 cassa due');
$expected[] = $addTerminal(0, 'C2 cassa tre');
$public = static function (array $rows): array { return array_map(static function ($r) { unset($r['internal_id']); return $r; }, $rows); };
$token = tcLogin('user@example.invalid', $key);
$second = tcLogin('user@example.invalid', $key);
$otherClient = tcLogin('user@example.invalid', $rotated);
$other = tcLogin('other@example.invalid', $key);
tcDeniedLogin('admin@example.invalid', $key);
tcDeniedLogin('merchant@example.invalid', $key);
$admin = tcLegacyToken((int)$users['runtime-admin']);
$merchantToken = tcLegacyToken($ownerA);
tcFresh();
$lastUsed = tcRow($token)['last_used_at'];
$page = tcPage(tcGet($token), 'Default catalog');
tcCheck($page === ['items'=>$public($expected),'next_cursor'=>null], 'Two granted stores only, internal ID order, foreign/ungranted/disabled excluded before pagination');
tcCheck(tcRow($token)['last_used_at'] === $lastUsed, 'GET does not write session last_used_at');
tcCheck(tcPage(tcGet($other), 'Other merchant catalog')['items'] === $public([$foreign]), 'Other merchant sees only own terminal');
foreach ([$admin,$merchantToken] as $roleToken) tcError(tcGet($roleToken), 401, 'invalid_token', 'Legacy privileged bearer cannot bypass R1');
$db->createCommand()->insert('pos_operators', ['user_id'=>$users['runtime-admin'],'merchant_id'=>$a]+$enabled)->execute();
tcError(tcGet($admin), 401, 'invalid_token', 'Enabled profile cannot qualify legacy admin bearer');
foreach ([['pos_operators',['user_id'=>$u]],['pos_store_operators',['user_id'=>$u,'store_id'=>$stores[0]]],['pos_terminals',['id'=>$expected[0]['internal_id']]]] as [$table,$where]) {
    try {
        $db->createCommand()->update($table, ['is_enabled'=>0], $where)->execute();
        if ($table === 'pos_operators') {
            tcError(tcGet($token), 401, 'invalid_token', 'Disabled profile invalidates existing bearer');
            tcDeniedLogin('user@example.invalid', $key);
        } else {
            $actual = tcPage(tcGet($token), 'Disabled scope flag');
            $wanted = $table === 'pos_store_operators' ? [$expected[1]] : [$expected[1],$expected[2]];
            tcCheck($actual['items'] === $public($wanted), 'Disabled ' . $table . ' removes precisely its authorized rows');
        }
    } finally { $db->createCommand()->update($table, ['is_enabled'=>1], $where)->execute(); }
}
// D2: even explicit enabled grants cannot turn privileged accounts into POS operators.
$rolePrivileges = [];
foreach ([['runtime-admin',$admin,app\components\User::ROLE_ADMIN],['runtime-merchant',$merchantToken,app\components\User::ROLE_MERCHANT]] as [$username,$roleToken,$level]) {
    $roleUser = (int)$users[$username];
    $identity = $db->createCommand('SELECT u.privilege_id,u.is_active,p.level FROM users u JOIN privileges p ON p.id=u.privilege_id WHERE u.id=:id', [':id'=>$roleUser])->queryOne();
    tcCheck($identity && (int)$identity['is_active'] === 1 && (int)$identity['level'] === $level, 'D2 fixture has expected active privileged role: ' . $username);
    $rolePrivileges[$username] = (int)$identity['privilege_id'];
    if ($username === 'runtime-merchant') $db->createCommand()->insert('pos_operators', ['user_id'=>$roleUser,'merchant_id'=>$a]+$enabled)->execute();
    $db->createCommand()->insert('pos_store_operators', ['user_id'=>$roleUser,'merchant_id'=>$a,'store_id'=>$stores[0]]+$enabled)->execute();
    tcError(tcGet($roleToken), 401, 'invalid_token', 'D2 explicit POS grant does not authorize privileged role: ' . $username);
    tcDeniedLogin($username === 'runtime-admin' ? 'admin@example.invalid' : 'merchant@example.invalid', $key);
    // Do not leave the manager with a conflicting operator identity for later checks.
    $db->createCommand()->delete('pos_store_operators', ['user_id'=>$roleUser])->execute();
    $db->createCommand()->delete('pos_operators', ['user_id'=>$roleUser])->execute();
}
$baseIdentity = $db->createCommand('SELECT u.privilege_id,u.is_active,p.level FROM users u JOIN privileges p ON p.id=u.privilege_id WHERE u.id=:id', [':id'=>$u])->queryOne();
tcCheck($baseIdentity && (int)$baseIdentity['is_active'] === 1 && (int)$baseIdentity['level'] === app\components\User::ROLE_USER, 'D2 normal operator starts as active base account');
$basePrivilege = (int)$baseIdentity['privilege_id'];
tcFresh();
foreach ($rolePrivileges as $username=>$privilege) {
    try {
        $db->createCommand()->update('users', ['privilege_id'=>$privilege], ['id'=>$u])->execute();
        tcError(tcGet($token), 401, 'invalid_token', 'D2 existing bearer invalid after role change: ' . $username);
    } finally {
        $db->createCommand()->update('users', ['privilege_id'=>$basePrivilege], ['id'=>$u])->execute();
    }
    tcCheck(tcPage(tcGet($token), 'D2 base role restored without new login') === ['items'=>$public($expected),'next_cursor'=>null], 'D2 same bearer regains exact catalog after base role restored: ' . $username);
}

try {
    $db->createCommand()->update('pos_store_operators', ['is_enabled'=>0], ['user_id'=>$u])->execute();
    $noGrants = tcLogin('user@example.invalid', $key);
    tcCheck(tcPage(tcGet($noGrants), 'Operator without enabled grants') === ['items'=>[],'next_cursor'=>null], 'Enabled operator can log in without enabled store grants');
} finally {
    $db->createCommand()->update('pos_store_operators', ['is_enabled'=>1], ['user_id'=>$u])->execute();
}
try {
    $db->createCommand()->insert('member_profiles', ['user_id'=>$u,'is_corporate'=>0])->execute();
    tcError(tcGet($token), 401, 'invalid_token', 'Membership conflict invalidates existing operator bearer');
    tcDeniedLogin('user@example.invalid', $key);
} finally {
    $db->createCommand()->delete('member_profiles', ['user_id'=>$u])->execute();
}
try {
    $db->createCommand()->update('member_profiles', ['denomination'=>null], ['user_id'=>$ownerA])->execute();
    tcError(tcGet($token), 401, 'invalid_token', 'Unqualified merchant invalidates existing operator bearer');
    tcDeniedLogin('user@example.invalid', $key);
} finally {
    $db->createCommand()->update('member_profiles', ['denomination'=>'C2 merchant A PG'], ['user_id'=>$ownerA])->execute();
}
tcFresh();

$first = tcPage(tcGet($token, 'limit=1'), 'First keyset page');
tcCheck($first['items'] === $public([$expected[0]]) && is_string($first['next_cursor']), 'Scope applied before limit; cursor emitted');
$cursor = $first['next_cursor'];
$query = 'limit=1&cursor=' . rawurlencode($cursor);
$next = tcPage(tcGet($token, $query), 'Second keyset page');
tcCheck($next['items'] === $public([$expected[1]]) && is_string($next['next_cursor']), 'Second store reached without duplicates');
$last = tcPage(tcGet($token, 'limit=1&cursor=' . rawurlencode($next['next_cursor'])), 'Final keyset page');
tcCheck($last === ['items'=>$public([$expected[2]]),'next_cursor'=>null], 'Pagination ends without skipped terminals');
tcCheck(tcPage(tcGet($token, $query), 'Cursor replay')['items'] === $next['items'], 'Cursor replay stable absent catalog changes');
tcError(tcGet($second, $query), 400, 'validation_failed', 'Cursor cannot cross sessions of same operator');
tcError(tcGet($otherClient, $query, $rotated), 400, 'validation_failed', 'Cursor cannot cross clients');
tcError(tcGet($token, 'limit=2&cursor=' . rawurlencode($cursor)), 400, 'validation_failed', 'Cursor limit binding enforced');
$tampered = ($cursor[0] === 'A' ? 'B' : 'A') . substr($cursor, 1);
tcError(tcGet($token, 'limit=1&cursor=' . rawurlencode($tampered)), 400, 'validation_failed', 'Authenticated cursor tampering rejected');
try {
    $db->createCommand()->update('pos_store_operators', ['is_enabled'=>0], ['user_id'=>$u,'store_id'=>$stores[1]])->execute();
    tcCheck(tcPage(tcGet($token, $query), 'Grant revoked between pages')['items'] === $public([$expected[2]]), 'Cursor does not preserve revoked grants or skip remaining authorized rows');
    $db->createCommand()->update('pos_operators', ['is_enabled'=>0], ['user_id'=>$u])->execute();
    tcError(tcGet($token, $query), 401, 'invalid_token', 'Cursor never bypasses disabled profile');
} finally {
    $db->createCommand()->update('pos_store_operators', ['is_enabled'=>1], ['user_id'=>$u,'store_id'=>$stores[1]])->execute();
    $db->createCommand()->update('pos_operators', ['is_enabled'=>1], ['user_id'=>$u])->execute();
}

// Authentication precedes parameter processing; all public errors have fixed bodies.
tcFresh();
foreach ([null,'invalid-client-key'] as $client) tcError(tcHttp('terminals?unknown=1', $client, $token), 401, 'unauthenticated', 'Service key required before parameter validation');
foreach ([null,'bad','napos_' . str_repeat('A',43)] as $badToken) tcError(tcHttp('terminals', $key, $badToken), 401, 'invalid_token', 'Missing, malformed or unknown bearer rejected');
foreach (['Basic invalid','bearer ' . $token,'Bearer ' . $token . 'x'] as $header) tcError(tcHttp('terminals', $key, null, 'GET', null, $header), 401, 'invalid_token', 'Strict Authorization syntax required');
tcError(tcGet($token, '', $rotated), 401, 'invalid_token', 'Valid token cannot be used by different configured client');
$invalidQueries = ['limit=0','limit=101','limit=-1','limit=01','limit=1.0','limit=1e1','limit=+1','limit=%201','limit=',
    'limit[]=1','limit[x]=1','cursor[]=x','cursor[x]=x','cursor=','cursor=abc=','cursor=***','cursor=abc',
    'cursor=' . str_repeat('A',513),'unknown=value','merchant_id=' . $b,'store_id=' . $stores[3]];
foreach ($invalidQueries as $i=>$invalid) {
    tcFresh();
    tcError(tcGet($token, $invalid), 400, 'validation_failed', 'Invalid catalog parameter case ' . ($i+1));
}
foreach (['{}',' ', '[]'] as $body) tcError(tcHttp('terminals', $key, $token, 'GET', $body), 400, 'validation_failed', 'Nonempty GET body rejected');
foreach (['POST','PUT','PATCH','DELETE','OPTIONS','HEAD'] as $method) {
    $response = tcHttp('terminals', $key, $token, $method);
    tcCheck($response['status'] === 405 && !str_contains($response['body'], $token)
        && !preg_match('/^(?:set-cookie:|access-control-)/mi', $response['headers']), 'Non-GET method rejected: ' . $method);
}

$invalidated = tcLogin('user@example.invalid', $key);
try {
    $db->createCommand()->update('pos_operator_sessions', ['expires_at'=>time()-1], ['token_sha256'=>hash('sha256',$invalidated)])->execute();
    tcError(tcGet($invalidated), 401, 'invalid_token', 'Expired session rejected over HTTP');
} finally { $db->createCommand()->update('pos_operator_sessions', ['expires_at'=>time()+3600], ['token_sha256'=>hash('sha256',$invalidated)])->execute(); }
foreach ([0,2] as $status) {
    try {
        $db->createCommand()->update('users', ['is_active'=>$status], ['id'=>$u])->execute();
        tcError(tcGet($invalidated), 401, 'invalid_token', 'Inactive or blocked account rejects existing bearer');
    } finally { $db->createCommand()->update('users', ['is_active'=>1], ['id'=>$u])->execute(); }
}
$originalHash = $db->createCommand('SELECT password FROM users WHERE id=:id', [':id'=>$u])->queryScalar();
try {
    $db->createCommand()->update('users', ['password'=>password_hash('c2-changed-fixture-password', PASSWORD_BCRYPT)], ['id'=>$u])->execute();
    tcError(tcGet($invalidated), 401, 'invalid_token', 'Password change invalidates catalog bearer');
} finally { $db->createCommand()->update('users', ['password'=>$originalHash], ['id'=>$u])->execute(); }
$logout = tcHttp('sessions', $key, $invalidated, 'DELETE');
tcCheck($logout['status'] === 204 && $logout['body'] === '', 'Real logout succeeds');
tcError(tcGet($invalidated), 401, 'invalid_token', 'Logged-out bearer rejected by catalog');

// Short names isolate row limits from the independent response byte budget.
for ($i=0; $i<105; ++$i) $expected[] = $addTerminal($i % 2, 'C2 bulk ' . $i);
tcFresh();
$defaultPage = tcPage(tcGet($token), 'Default row limit');
tcCheck($defaultPage['items'] === array_slice($public($expected), 0, 50) && is_string($defaultPage['next_cursor']), 'Default limit is exactly 50 when byte budget permits');
$maximumPage = tcPage(tcGet($token, 'limit=100'), 'Maximum row limit');
tcCheck($maximumPage['items'] === array_slice($public($expected), 0, 100) && is_string($maximumPage['next_cursor']), 'Explicit limit 100 is accepted without premature truncation');
// Force byte-budget pagination, including multi-byte 256-character names on both fields.
$longName = str_repeat("\u{754c}", 256);
foreach ([$stores[0],$stores[1]] as $storeId) {
    $db->createCommand()->update('stores', ['description'=>$longName], ['id'=>$storeId])->execute();
    $db->createCommand()->update('pos_terminals', ['name'=>$longName], ['store_id'=>$storeId])->execute();
}
foreach ($expected as &$item) { $item['store_name'] = $longName; $item['name'] = $longName; }
unset($item);
tcFresh();
$collected = []; $seenCursors = []; $nextCursor = null; $pages = 0;
do {
    if (++$pages > 120) throw new RuntimeException('Catalog pagination failed to terminate.');
    // Cache resets isolate size/pagination assertions from request budgets, tested below.
    tcFresh();
    $page = tcPage(tcGet($token, 'limit=100' . ($nextCursor === null ? '' : '&cursor=' . rawurlencode($nextCursor))), 'Long UTF-8 catalog page');
    tcCheck(count($page['items']) > 0 && count($page['items']) <= 100, 'Byte-limited page makes bounded forward progress');
    if ($pages === 1) tcCheck(count($page['items']) < 100 && is_string($page['next_cursor']), '16KiB budget splits page before requested row limit');
    $collected = array_merge($collected, $page['items']);
    $nextCursor = $page['next_cursor'];
    if ($nextCursor !== null) {
        tcCheck(!isset($seenCursors[$nextCursor]), 'Pagination cursor advances');
        $seenCursors[$nextCursor] = true;
    }
} while ($nextCursor !== null);
tcCheck($collected === $public($expected), 'All long-name terminals delivered exactly once in internal ID order');
tcCheck(count(array_unique(array_column($collected,'id'))) === count($expected), 'No duplicate UUID across byte-limited pages');
tcFresh();
tcCheck(count(tcPage(tcGet($token), 'Default limit with many terminals')['items']) <= 50, 'Default limit never exceeds 50 rows');

// Schema/data failure must never become a successful empty catalog; always restore objects.
$errorBodies = [];
foreach (['pos_terminals','pos_store_operators','pos_operators','pos_operator_sessions'] as $table) {
    tcFresh();
    $temporary = $table . '_c2_failure';
    $db->createCommand()->renameTable($table, $temporary)->execute();
    try {
        $response = tcGet($token);
        tcError($response, 503, 'backend_unavailable', 'Missing backend schema fails closed: ' . $table);
        $errorBodies[] = $response['body'];
    } finally { $db->createCommand()->renameTable($temporary, $table)->execute(); }
}
try {
    $db->createCommand()->update('stores', ['description'=>''], ['id'=>$stores[0]])->execute();
    $response = tcGet($token);
    tcError($response, 503, 'backend_unavailable', 'Inconsistent store name fails closed');
    $errorBodies[] = $response['body'];
} finally { $db->createCommand()->update('stores', ['description'=>$longName], ['id'=>$stores[0]])->execute(); }
tcCheck(count(array_unique($errorBodies)) === 1, 'Backend failures have identical sanitized public bodies');

// Assert every allowed request succeeds: do not mistake unrelated failures for throttling.
$rateTokens = [$token,$second];
for ($i=0; $i<3; ++$i) $rateTokens[] = tcLogin('user@example.invalid', $key);
tcFresh(); $started = microtime(true); $statuses = [];
for ($i=0; $i<30; ++$i) $statuses[] = tcGet($token, 'limit=1')['status'];
tcCheck(array_unique($statuses) === [200] && microtime(true)-$started < 55, 'Session allows exactly first 30 catalog requests in one window');
$limited = tcGet($token, 'limit=1');
tcError($limited, 429, 'rate_limited', 'Session request 31 throttled');
tcCheck(preg_match('/^retry-after: ([1-9][0-9]*)\s*$/mi', $limited['headers'], $m) && (int)$m[1] <= 60, 'Session throttle provides bounded Retry-After');
tcCheck(tcGet($second, 'limit=1')['status'] === 200, 'Different session of same operator has independent budget');
tcCheck(tcGet($otherClient, 'limit=1', $rotated)['status'] === 200, 'Different client budget remains available');
tcFresh(); $started = microtime(true); $statuses = [];
foreach ($rateTokens as $rateToken) for ($i=0; $i<24; ++$i) $statuses[] = tcGet($rateToken, 'limit=1')['status'];
tcCheck(count($statuses) === 120 && array_unique($statuses) === [200] && microtime(true)-$started < 55, 'Client permits 120 requests across sessions below individual limits');
$limited = tcGet($second, 'limit=1');
tcError($limited, 429, 'rate_limited', 'Client request 121 throttled');
tcCheck(preg_match('/^retry-after: ([1-9][0-9]*)\s*$/mi', $limited['headers'], $m) && (int)$m[1] <= 60, 'Client throttle provides bounded Retry-After');
tcError(tcHttp('terminals', null, $token), 401, 'unauthenticated', 'Service authentication precedes exhausted client budget');
tcError(tcHttp('terminals', $key, 'malformed'), 401, 'invalid_token', 'Bearer syntax precedes exhausted client budget');
tcCheck(tcGet($otherClient, 'limit=1', $rotated)['status'] === 200, 'Client throttle isolated from rotated key');
$login = tcHttp('sessions', $key, null, 'POST', json_encode(['email'=>'user@example.invalid','password'=>'fixture-password-only']));
tcCheck($login['status'] === 201, 'Catalog exhaustion does not consume login budget');
if (is_string($login['json']['access_token'] ?? null)) $secrets[] = $login['json']['access_token'];
tcCheck(tcHttp('sessions', $key, $second, 'DELETE')['status'] === 204, 'Catalog exhaustion does not consume logout budget');
$logs = json_encode($db->createCommand('SELECT description,controller,action FROM logs')->queryAll());
foreach (glob($root . '/runtime/logs/*.log') ?: [] as $file) $logs .= file_get_contents($file);
$secrets[] = 'c2-changed-fixture-password';
tcCheck(count(array_filter($secrets, static fn($secret)=>str_contains($logs, $secret))) === 0, 'No bearer, client key or password in application/activity logs');
$cacheContents = '';
if (is_dir($root . '/runtime/cache')) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/runtime/cache', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) if ($file->isFile()) $cacheContents .= file_get_contents($file->getPathname());
}
tcCheck(count(array_filter($secrets, static fn($secret)=>str_contains($cacheContents, $secret))) === 0, 'No plaintext bearer or client key in cache files');
echo "PASS {$checks} POS C2 catalog HTTP/DB checks\n";
