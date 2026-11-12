<?php
// LOGIN-R1-D3: real API/DB, SQL disable protocol only, NOT C4 integration.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
use app\components\pos\PosApi;
use app\models\Users;
use app\components\identity\AccountEligibility;
$app = new yii\web\Application(require $root . '/config/web.php');
$db = $app->db;
$db->enableLogging = false; $db->enableProfiling = false;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);
$key = env_required('NAPAY_TEST_POS_CLIENT_KEY');
$prefix = substr(hash('sha256', $key), 0, 16);
// Signal immediately before the real locking SQL, without mocking its execution.
class PseMerchantLockCommand extends yii\db\Command {
    public function queryScalar() {
        if (str_contains($this->getSql(), 'merchants') && str_contains($this->getSql(), 'FOR UPDATE')) fwrite(STDOUT, "merchant-lock\n");
        return parent::queryScalar();
    }
}
if (($argv[1] ?? '') === '--pse-worker') {
    try {
        $db->createCommand('SET SESSION innodb_lock_wait_timeout=10')->execute();
        $user = Users::findOne((int)($argv[2] ?? 0));
        if ($user === null) exit(2);
        $db->commandClass = PseMerchantLockCommand::class;
        stream_set_timeout(STDIN, 15);
        fwrite(STDOUT, "ready\n");
        if (fread(STDIN, 1) !== 'x') exit(2);
        $created = PosApi::createSession($user, $prefix, null);
        if ($created === null) { fwrite(STDOUT, "denied\n"); exit(0); }
        // Only DB row ID crosses this private control pipe; the bearer stays in this worker.
        fwrite(STDOUT, 'created:' . $created[1]->id . "\n");
        if (fread(STDIN, 1) !== 'v') exit(2);
        fwrite(STDOUT, PosApi::findActiveSession($created[0], $prefix) === null ? "invalid\n" : "active\n");
        exit(0);
    } catch (Throwable $error) { fwrite(STDOUT, "worker-error\n"); exit(2); }
}
require_once __DIR__ . '/pos-session-fixture.php';
$fixture = napayTestPosSessionFixture();
$uid = $fixture['user_id']; $mid = $fixture['merchant_id'];
$pseChecks = 0; $pseSecrets = [$key,'fixture-password-only'];
function pseCheck($condition, string $label): void {
    global $pseChecks;
    if (!$condition) throw new RuntimeException($label);
    ++$pseChecks; echo 'PASS: ' . $label . "\n";
}
function pseCount(string $table = 'pos_operator_sessions'): int {
    global $db;
    if (!in_array($table, ['pos_operator_sessions','pse_missing_pos_operator_sessions'], true)) throw new RuntimeException('Invalid fixture table.');
    return (int)$db->createCommand('SELECT COUNT(*) FROM ' . $table)->queryScalar();
}
function pseFresh(): void { global $app; if (!$app->cache->flush()) throw new RuntimeException('Fixture cache reset failed.'); }
function pseHttp(string $path = 'sessions', string $method = 'POST', ?string $token = null, ?string $password = 'fixture-password-only'): array {
    global $key;
    $headers = ['X-POS-Client-Key: ' . $key,'Accept: application/json'];
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    $curl = curl_init('http://127.0.0.1/api/pos/v1/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_PROXY=>'',CURLOPT_CUSTOMREQUEST=>$method]);
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(['email'=>'user@example.invalid','password'=>$password]));
    }
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($curl);
    if ($raw === false) { curl_close($curl); throw new RuntimeException('Eligibility API transport failed.'); }
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $response = ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'headers'=>substr($raw,0,$size),'body'=>substr($raw,$size)];
    curl_close($curl); $response['json'] = json_decode($response['body'],true);
    return $response;
}
function pseError(array $response, int $status, string $code, string $label): void {
    $messages = ['invalid_credentials'=>'Invalid email or password.','invalid_token'=>'Operator authentication required.',
        'backend_unavailable'=>'Service unavailable.','rate_limited'=>'Too many attempts. Retry later.'];
    // Login's established message is captured from a real credential failure below.
    global $pseCredentialMessage;
    if ($code === 'invalid_credentials') $messages[$code] = $pseCredentialMessage;
    pseCheck($response['status'] === $status && $response['json'] === ['error'=>['code'=>$code,'message'=>$messages[$code]]]
        && str_contains(strtolower($response['headers']), 'cache-control: no-store')
        && !preg_match('/^(?:set-cookie:|access-control-)/mi',$response['headers']), $label);
}
function pseDenied(string $label, string $oldToken): void {
    global $db,$uid,$prefix,$pseCredentialBody;
    $count = pseCount(); $profiles = (int)$db->createCommand('SELECT COUNT(*) FROM pos_operators')->queryScalar();
    pseCheck(PosApi::createSession(Users::findOne($uid),$prefix,null) === null && pseCount() === $count, $label . ': direct createSession null, no inserted session');
    pseFresh(); $response = pseHttp();
    pseError($response,401,'invalid_credentials',$label . ': HTTP generic credential denial');
    pseCheck($response['body'] === $pseCredentialBody && pseCount() === $count
        && (int)$db->createCommand('SELECT COUNT(*) FROM pos_operators')->queryScalar() === $profiles, $label . ': identical body, no token/session or implicit profile');
    pseCheck(PosApi::findActiveSession($oldToken,$prefix) === null, $label . ': existing bearer fails current eligibility');
    pseError(pseHttp('terminals','GET',$oldToken),401,'invalid_token',$label . ': catalog rejects bearer, not fake empty success');
}
function pseWorkerStart(int $uid): array {
    $process = proc_open([PHP_BINARY,__FILE__,'--pse-worker',(string)$uid], [0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','a']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Eligibility worker unavailable.');
    $worker = ['process'=>$process,'pipes'=>$pipes];
    stream_set_timeout($pipes[1],15);
    try {
        if (fgets($pipes[1]) !== "ready\n") throw new RuntimeException('Eligibility worker initialization failed.');
        if (fwrite($pipes[0],'x') !== 1 || fgets($pipes[1]) !== "merchant-lock\n") throw new RuntimeException('Eligibility worker did not reach merchant lock.');
        return $worker;
    } catch (Throwable $error) { pseWorkerClose($worker,true); throw $error; }
}
function pseWorkerClose(array &$worker, bool $terminate = false): int {
    foreach ($worker['pipes'] ?? [] as $pipe) if (is_resource($pipe)) fclose($pipe);
    $code = -1;
    if (isset($worker['process']) && is_resource($worker['process'])) {
        if ($terminate) proc_terminate($worker['process']);
        $code = proc_close($worker['process']);
    }
    $worker = []; return $code;
}
function pseSqlDisableBegin(int $uid,int $mid): yii\db\Transaction {
    global $db;
    $transaction = $db->beginTransaction(yii\db\Transaction::READ_COMMITTED);
    try {
        if ((int)$db->createCommand('SELECT id FROM merchants WHERE id=:m FOR UPDATE',[':m'=>$mid])->queryScalar() !== $mid) throw new RuntimeException('Missing fixture merchant.');
        if ((int)$db->createCommand('SELECT user_id FROM pos_operators WHERE user_id=:u AND merchant_id=:m FOR UPDATE',[':u'=>$uid,':m'=>$mid])->queryScalar() !== $uid) throw new RuntimeException('Missing fixture profile.');
        $db->createCommand()->update('pos_operators',['is_enabled'=>0,'updated_at'=>time()],['user_id'=>$uid])->execute();
        $db->createCommand("UPDATE pos_operator_sessions SET revoked_at=:now,revoked_reason='operator_disabled' WHERE user_id=:u AND revoked_at IS NULL AND expires_at>:cutoff",[':now'=>time(),':cutoff'=>time(),':u'=>$uid])->execute();
        return $transaction;
    } catch (Throwable $error) { $transaction->rollBack(); throw $error; }
}

pseCheck(AccountEligibility::isOperatorAccount($uid) && AccountEligibility::qualifiedMerchant($mid), 'Explicit dedicated operator and qualified PG merchant fixtures');
pseCheck((int)$db->createCommand('SELECT COUNT(*) FROM pos_store_operators WHERE user_id=:u',[':u'=>$uid])->queryScalar() === 0, 'No store grant required for login fixture');
$globalIsolation = $db->createCommand('SELECT @@session.transaction_isolation')->queryScalar();
$before = pseCount(); $created = PosApi::createSession(Users::findOne($uid),$prefix,null);
pseCheck(is_array($created) && pseCount() === $before+1, 'Eligible direct creation inserts exactly one session without grant');
$oldToken = $created[0]; $pseSecrets[] = $oldToken;
pseCheck(PosApi::findActiveSession($oldToken,$prefix)?->id == $created[1]->id, 'Eligible direct bearer authenticates');
pseCheck($db->getTransaction() === null && $db->createCommand('SELECT @@session.transaction_isolation')->queryScalar() === $globalIsolation, 'createSession owns and closes transaction without changing session isolation');
pseFresh(); $http = pseHttp();
pseCheck($http['status'] === 201 && preg_match('/^napos_[A-Za-z0-9_-]{43}$/D',$http['json']['access_token'] ?? ''), 'Eligible HTTP login succeeds without grants');
$pseSecrets[] = $http['json']['access_token'];
pseFresh(); $bad = pseHttp('sessions','POST',null,'pse-wrong-password');
pseCheck($bad['status'] === 401 && ($bad['json']['error']['code'] ?? null) === 'invalid_credentials'
    && array_keys($bad['json']) === ['error'] && array_keys($bad['json']['error']) === ['code','message'], 'Real credential failure establishes generic denial shape');
$pseCredentialBody = $bad['body']; $pseCredentialMessage = $bad['json']['error']['message'];
$profile = $db->createCommand('SELECT * FROM pos_operators WHERE user_id=:u',[':u'=>$uid])->queryOne();
try {
    $db->createCommand()->delete('pos_operators',['user_id'=>$uid])->execute();
    pseDenied('Absent operator profile',$oldToken);
} finally { $db->createCommand()->insert('pos_operators',$profile)->execute(); }
$logoutCandidate = PosApi::createSession(Users::findOne($uid),$prefix,null); $pseSecrets[] = $logoutCandidate[0];
try {
    $db->createCommand()->update('pos_operators',['is_enabled'=>0],['user_id'=>$uid])->execute();
    pseDenied('Disabled operator profile',$oldToken);
    $logout = pseHttp('sessions','DELETE',$logoutCandidate[0]);
    pseCheck($logout['status'] === 204 && $logout['body'] === '', 'Logout remains allowed with disabled operator profile');
    pseCheck($db->createCommand('SELECT revoked_at FROM pos_operator_sessions WHERE id=:id',[':id'=>$logoutCandidate[1]->id])->queryScalar() !== null, 'Disabled-profile logout persists revocation');
} finally { $db->createCommand()->update('pos_operators',['is_enabled'=>1],['user_id'=>$uid])->execute(); }
foreach ([0,1] as $corporate) {
    try {
        $db->createCommand()->delete('pos_operators',['user_id'=>$uid])->execute();
        $db->createCommand()->insert('member_profiles',['user_id'=>$uid,'is_corporate'=>$corporate,'denomination'=>$corporate ? 'PSE fixture PG' : null])->execute();
        pseCheck(AccountEligibility::kind($uid) === ($corporate ? AccountEligibility::MEMBER_PG : AccountEligibility::MEMBER_PF), 'Positive member classification before POS denial');
        pseDenied($corporate ? 'Base PG member' : 'Base PF member',$oldToken);
        $db->createCommand()->insert('pos_operators',$profile)->execute();
        pseDenied($corporate ? 'Conflicting PG member plus operator' : 'Conflicting PF member plus operator',$oldToken);
    } finally {
        $db->createCommand()->delete('member_profiles',['user_id'=>$uid])->execute();
        if (!$db->createCommand('SELECT COUNT(*) FROM pos_operators WHERE user_id=:u',[':u'=>$uid])->queryScalar()) $db->createCommand()->insert('pos_operators',$profile)->execute();
    }
}
$basePrivilege = (int)Users::findOne($uid)->privilege_id;
foreach (['runtime-admin','runtime-merchant'] as $username) {
    $privilege = $db->createCommand('SELECT privilege_id FROM users WHERE username=:n',[':n'=>$username])->queryScalar();
    try {
        $db->createCommand()->update('users',['privilege_id'=>$privilege],['id'=>$uid])->execute();
        pseDenied('Privileged role ' . $username,$oldToken);
    } finally { $db->createCommand()->update('users',['privilege_id'=>$basePrivilege],['id'=>$uid])->execute(); }
}
foreach ([0,2] as $state) {
    try {
        $db->createCommand()->update('users',['is_active'=>$state],['id'=>$uid])->execute();
        pseDenied('Inactive or blocked operator state ' . $state,$oldToken);
    } finally { $db->createCommand()->update('users',['is_active'=>1],['id'=>$uid])->execute(); }
}
$owner = (int)$db->createCommand('SELECT user_id FROM merchants WHERE id=:m',[':m'=>$mid])->queryScalar();
$ownerMember = $db->createCommand('SELECT * FROM member_profiles WHERE user_id=:u',[':u'=>$owner])->queryOne();
try {
    $db->createCommand()->delete('member_profiles',['user_id'=>$owner])->execute();
    pseCheck(!AccountEligibility::qualifiedMerchant($mid), 'Merchant without positive PG evidence is unqualified');
    pseDenied('Unqualified merchant',$oldToken);
} finally { $db->createCommand()->insert('member_profiles',$ownerMember)->execute(); }
$verified = Users::findOne($uid);
pseCheck($verified->validatePassword('fixture-password-only'), 'Stale-password probe starts from verified password snapshot');
try {
    $db->createCommand()->update('users',['password'=>password_hash('pse-new-password',PASSWORD_BCRYPT)],['id'=>$uid])->execute();
    $before = pseCount();
    pseCheck(PosApi::createSession($verified,$prefix,null) === null && pseCount() === $before, 'Password changed after verification: stale Users object cannot insert session');
    pseCheck(PosApi::findActiveSession($oldToken,$prefix) === null, 'Password change also invalidates existing bearer');
    pseFresh(); pseError(pseHttp(),401,'invalid_credentials','Old verified password rejected over HTTP');
    pseCheck(pseCount() === $before, 'Password race creates no token row');
} finally { $db->createCommand()->update('users',['password'=>$verified->password],['id'=>$uid])->execute(); }

// Missing schema is an infrastructure error, never a successful policy denial.
foreach (['pos_operators','member_profiles','membership_payments','merchants','users','privileges','pos_operator_sessions'] as $table) {
    pseFresh(); $before = pseCount(); $snapshot = Users::findOne($uid); $temporary = 'pse_missing_' . $table;
    $db->createCommand()->renameTable($table,$temporary)->execute();
    try {
        $thrown = false;
        try { PosApi::createSession($snapshot,$prefix,null); } catch (yii\db\Exception | yii\base\InvalidConfigException $error) { $thrown = true; }
        pseCheck($thrown, 'Direct schema failure throws instead of returning null: ' . $table);
        pseError(pseHttp(),503,'backend_unavailable','HTTP schema failure is sanitized 503: ' . $table);
        pseError(pseHttp('terminals','GET',$oldToken),503,'backend_unavailable','Catalog schema failure is 503: ' . $table);
        pseCheck(pseCount($table === 'pos_operator_sessions' ? $temporary : 'pos_operator_sessions') === $before, 'Schema failure inserts no session: ' . $table);
    } finally { $db->createCommand()->renameTable($temporary,$table)->execute(); $db->schema->refresh(); }
}

// Failed eligibility consumes login failure budget; success is the only reset.
pseFresh(); $before = pseCount();
try {
    $db->createCommand()->update('pos_operators',['is_enabled'=>0],['user_id'=>$uid])->execute();
    for ($i=0;$i<5;++$i) pseError(pseHttp(),401,'invalid_credentials','Ineligible login consumes one account failure');
    $db->createCommand()->update('pos_operators',['is_enabled'=>1],['user_id'=>$uid])->execute();
    $limited = pseHttp(); pseError($limited,429,'rate_limited','Eligibility restored does not erase failed-login budget');
    pseCheck(preg_match('/^retry-after: [1-9][0-9]*/mi',$limited['headers']) && pseCount() === $before, 'Eligibility throttle has Retry-After and no token row');
} finally { $db->createCommand()->update('pos_operators',['is_enabled'=>1],['user_id'=>$uid])->execute(); pseFresh(); }

// D3 order A: worker API commits first; parent SQL merchant->profile->bulk revoke wins next.
$worker = []; $transaction = null;
try {
    $worker = pseWorkerStart($uid); $line = fgets($worker['pipes'][1]);
    pseCheck(is_string($line) && preg_match('/^created:([1-9][0-9]*)\n$/D',$line,$match), 'D3 SQL protocol: first-process API login commits before disable');
    $childId = (int)$match[1];
    $transaction = pseSqlDisableBegin($uid,$mid); $transaction->commit();
    $row = $db->createCommand('SELECT user_id,revoked_at,revoked_reason FROM pos_operator_sessions WHERE id=:id',[':id'=>$childId])->queryOne();
    pseCheck($row && (int)$row['user_id'] === $uid && $row['revoked_at'] !== null && $row['revoked_reason'] === 'operator_disabled', 'D3 SQL protocol: committed worker token included in bulk revocation');
    $db->createCommand()->update('pos_operators',['is_enabled'=>1],['user_id'=>$uid])->execute();
    fwrite($worker['pipes'][0],'v');
    pseCheck(fgets($worker['pipes'][1]) === "invalid\n", 'D3 SQL protocol: reenabling does not revive worker bearer');
    pseCheck(pseWorkerClose($worker) === 0, 'D3 login-first worker exits cleanly');
    pseCheck(PosApi::findActiveSession($oldToken,$prefix) === null, 'D3 bulk revoke remains permanent for earlier bearer after reenable');
    pseError(pseHttp('terminals','GET',$oldToken),401,'invalid_token','D3 earlier bearer remains rejected over HTTP after reenable');
} finally {
    if ($transaction !== null && $transaction->isActive) $transaction->rollBack();
    if ($worker !== []) pseWorkerClose($worker,true);
    $db->createCommand()->update('pos_operators',['is_enabled'=>1],['user_id'=>$uid])->execute();
}
// D3 order B: parent holds disable locks and flag=0; independent API waits, then sees commit.
$worker = []; $transaction = null; $before = pseCount();
try {
    $transaction = pseSqlDisableBegin($uid,$mid);
    $worker = pseWorkerStart($uid);
    $read = [$worker['pipes'][1]]; $write = null; $except = null;
    pseCheck(stream_select($read,$write,$except,0,300000) === 0, 'D3 SQL protocol: independent API cannot complete while disable merchant lock is held');
    $transaction->commit();
    pseCheck(fgets($worker['pipes'][1]) === "denied\n", 'D3 SQL protocol: waiting API rereads disabled profile after commit and returns null');
    pseCheck(pseWorkerClose($worker) === 0 && pseCount() === $before, 'D3 disable-first worker exits cleanly without inserting session');
    $db->createCommand()->update('pos_operators',['is_enabled'=>1],['user_id'=>$uid])->execute();
    pseCheck(pseCount() === $before && PosApi::findActiveSession($oldToken,$prefix) === null, 'D3 reenable neither inserts token nor revives revoked bearer');
} finally {
    if ($transaction !== null && $transaction->isActive) $transaction->rollBack();
    if ($worker !== []) pseWorkerClose($worker,true);
    $db->createCommand()->update('pos_operators',['is_enabled'=>1],['user_id'=>$uid])->execute();
}
pseFresh(); $after = pseHttp();
pseCheck($after['status'] === 201, 'Fresh eligible login works after SQL D3 disable and explicit reenable');
$pseSecrets[] = $after['json']['access_token']; $pseSecrets[] = 'pse-new-password';
$logs = json_encode($db->createCommand('SELECT description,controller,action FROM logs')->queryAll());
foreach (glob($root . '/runtime/logs/*.log') ?: [] as $file) $logs .= file_get_contents($file);
pseCheck(!preg_match('/napos_[A-Za-z0-9_-]{43}/',$logs) && count(array_filter($pseSecrets,static fn($secret)=>str_contains($logs,$secret))) === 0, 'No bearer, key or password in eligibility/protocol application logs');
echo "PASS {$pseChecks} POS session eligibility/API and D3 SQL protocol checks (not C4 integration)\n";
