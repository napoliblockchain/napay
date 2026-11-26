<?php
// Integrated D3: real C4 PosManagement and real PosApi/HTTP, disposable DB only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__,2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER',false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
use app\components\pos\PosApi;
use app\components\pos\management\PosManagement;
use app\components\pos\management\Scope;
use app\components\pos\management\ManagementException;
use app\models\Users;
$app = new yii\web\Application(require $root . '/config/web.php');
$app->user->enableSession = false; $app->user->enableAutoLogin = false;
$db = $app->db; $db->enableLogging = false; $db->enableProfiling = false;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);
$key = env_required('NAPAY_TEST_POS_CLIENT_KEY'); $prefix = substr(hash('sha256',$key),0,16);
$pmsChecks = 0; $pmsSecrets = [$key,'fixture-password-only'];
function pmsCheck($ok,string $label): void {
    global $pmsChecks;
    if (!$ok) throw new RuntimeException($label);
    ++$pmsChecks; echo 'PASS: ' . $label . "\n";
}
function pmsHttp(string $method = 'POST',?string $token = null,string $path = 'sessions'): array {
    global $key;
    $headers = ['X-POS-Client-Key: ' . $key,'Accept: application/json'];
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    $curl = curl_init('http://127.0.0.1/api/pos/v1/' . $path);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_PROXY=>'',CURLOPT_CUSTOMREQUEST=>$method]);
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode(['email'=>'user@example.invalid','password'=>'fixture-password-only']));
    }
    curl_setopt($curl,CURLOPT_HTTPHEADER,$headers); $raw = curl_exec($curl);
    if ($raw === false) { curl_close($curl); throw new RuntimeException('Integrated D3 HTTP transport failed.'); }
    $size = curl_getinfo($curl,CURLINFO_HEADER_SIZE);
    $response = ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'headers'=>substr($raw,0,$size),'body'=>substr($raw,$size)];
    curl_close($curl); $response['json'] = json_decode($response['body'],true); return $response;
}
function pmsError(array $r,string $code): bool {
    $messages = ['invalid_credentials'=>'Invalid credentials.','invalid_token'=>'Operator authentication required.'];
    return $r['status'] === 401 && $r['json'] === ['error'=>['code'=>$code,'message'=>$messages[$code]]]
        && str_contains(strtolower($r['headers']),'cache-control: no-store') && !preg_match('/^(?:set-cookie:|access-control-)/mi',$r['headers']);
}
function pmsRead(array $worker): string {
    $line = fgets($worker['pipes'][1]);
    if (!is_string($line)) throw new RuntimeException('Integrated D3 worker control timeout.');
    return $line;
}
function pmsSend(array $worker,string $message): void {
    if (fwrite($worker['pipes'][0],$message) !== strlen($message)) throw new RuntimeException('Integrated D3 worker control failed.');
}
function pmsClose(array &$worker,bool $terminate = false): int {
    foreach ($worker['pipes'] ?? [] as $pipe) if (is_resource($pipe)) fclose($pipe);
    $code = -1;
    if (isset($worker['process']) && is_resource($worker['process'])) {
        if ($terminate) proc_terminate($worker['process']);
        $code = proc_close($worker['process']);
    }
    $worker = []; return $code;
}
function pmsSpawn(string $mode,int $userId,int $adminId): array {
    $process = proc_open([PHP_BINARY,__FILE__,'--pms-worker',$mode,(string)$userId,(string)$adminId],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','a']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Integrated D3 worker unavailable.');
    $worker = ['process'=>$process,'pipes'=>$pipes]; stream_set_timeout($pipes[1],15);
    try {
        if (pmsRead($worker) !== "ready\n") throw new RuntimeException('Integrated D3 worker initialization failed.');
        pmsSend($worker,'g'); return $worker;
    } catch (Throwable $error) { pmsClose($worker,true); throw $error; }
}
function pmsWaiting(array $worker,string $label): void {
    global $db;
    $deadline = microtime(true)+8; $observed = false;
    do {
        // SHOW PROCESSLIST exposes this DB user's own threads without PROCESS privilege.
        // Never print process SQL: inspect only presence of the known locking statement.
        foreach ($db->createCommand('SHOW FULL PROCESSLIST')->queryAll() as $process) {
            $sql = $process['Info'] ?? null;
            if (is_string($sql) && preg_match('/^SELECT id FROM [`]?merchants[`]?.*FOR UPDATE/is',$sql)) { $observed = true; break; }
        }
        if (!$observed) usleep(20000);
    } while (!$observed && microtime(true)<$deadline);
    pmsCheck($observed,$label . ': server-side merchant lock query observed');
    $read = [$worker['pipes'][1]]; $write = null; $except = null;
    pmsCheck(stream_select($read,$write,$except,0,300000) === 0,$label);
}
// Hooks pause AFTER real writes. Nothing replaces service logic, locking or commit.
class PmsRaceCommand extends yii\db\Command {
    public static $pause = '';
    public function queryScalar() {
        if (str_contains($this->getSql(),'merchants') && str_contains($this->getSql(),'FOR UPDATE')) fwrite(STDOUT,"merchant-lock\n");
        return parent::queryScalar();
    }
    public function execute() {
        $result = parent::execute(); $sql = $this->getSql();
        if (self::$pause === 'insert' && preg_match('/^INSERT INTO [`]?pos_operator_sessions[`]?\s/i',$sql)) {
            self::$pause = '';
            if ($this->db->getTransaction() === null) throw new RuntimeException('API insert hook is not transactional.');
            fwrite(STDOUT,'inserted:' . $this->db->lastInsertID . "\n");
            if (fread(STDIN,1) !== 'c') throw new RuntimeException('API commit release missing.');
        } elseif (self::$pause === 'disable' && preg_match('/^UPDATE [`]?pos_operators[`]?\s/i',$sql)) {
            self::$pause = '';
            $id = (int)$GLOBALS['pmsWorkerUid'];
            if ($this->db->getTransaction() === null || (int)$this->db->createCommand('SELECT is_enabled FROM pos_operators WHERE user_id=:u',[':u'=>$id])->queryScalar() !== 0) throw new RuntimeException('C4 disable hook did not observe transactional flag zero.');
            fwrite(STDOUT,"profile-uncommitted\n");
            if (fread(STDIN,1) !== 'c') throw new RuntimeException('C4 commit release missing.');
        }
        return $result;
    }
}
if (($argv[1] ?? '') === '--pms-worker') {
    try {
        $mode = $argv[2] ?? ''; $pmsWorkerUid = (int)($argv[3] ?? 0); $actor = (int)($argv[4] ?? 0);
        if (!in_array($mode,['api-direct-hold','api-direct','api-http','disable','disable-hold'],true)) exit(2);
        $db->createCommand('SET SESSION innodb_lock_wait_timeout=10')->execute(); stream_set_timeout(STDIN,15);
        $user = Users::findOne($pmsWorkerUid);
        if ($user === null) exit(2);
        if (str_starts_with($mode,'disable')) {
            $app->user->setIdentity(Users::findOne($actor)); $scope = Scope::current();
            if ($scope === null) exit(2);
        }
        $db->commandClass = PmsRaceCommand::class;
        PmsRaceCommand::$pause = $mode === 'disable-hold' ? 'disable' : ($mode === 'api-direct-hold' ? 'insert' : '');
        fwrite(STDOUT,"ready\n"); if (fread(STDIN,1) !== 'g') exit(2);
        if (str_starts_with($mode,'disable')) {
            $revoked = PosManagement::setOperatorEnabled($scope,$pmsWorkerUid,false);
            fwrite(STDOUT,'disabled:' . $revoked . "\n"); exit(0);
        }
        if ($mode === 'api-http') {
            fwrite(STDOUT,"http-start\n"); $http = pmsHttp();
            if (pmsError($http,'invalid_credentials')) { fwrite(STDOUT,"denied401\n"); exit(0); }
            if ($http['status'] !== 201 || !is_string($http['json']['access_token'] ?? null)) throw new RuntimeException('Worker HTTP login failed.');
            $token = $http['json']['access_token'];
            $id = $db->createCommand('SELECT id FROM pos_operator_sessions WHERE token_sha256=:d',[':d'=>hash('sha256',$token)])->queryScalar();
        } else {
            $created = PosApi::createSession($user,$prefix,null);
            if ($created === null) { fwrite(STDOUT,"denied\n"); exit(0); }
            [$token,$session] = $created; $id = $session->id;
        }
        if (!(int)$id) throw new RuntimeException('Worker session row missing.');
        // Bearer remains private to worker memory: only row ID and boolean status cross pipes.
        fwrite(STDOUT,'created:' . $id . "\n"); if (fread(STDIN,1) !== 'v') exit(2);
        $invalid = PosApi::findActiveSession($token,$prefix) === null;
        if ($mode === 'api-http') $invalid = $invalid && pmsError(pmsHttp('GET',$token,'terminals'),'invalid_token');
        fwrite(STDOUT,$invalid ? "invalid\n" : "active\n"); exit(0);
    } catch (ManagementException $error) {
        fwrite(STDOUT,$error->getMessage() === ManagementException::NOT_FOUND ? "not-found\n" : "worker-error\n");
        exit($error->getMessage() === ManagementException::NOT_FOUND ? 0 : 2);
    } catch (Throwable $error) { fwrite(STDOUT,"worker-error\n"); exit(2); }
}
require_once __DIR__ . '/pos-session-fixture.php';
$fixture = napayTestPosSessionFixture(); $uid = $fixture['user_id']; $mid = $fixture['merchant_id'];
$adminId = (int)$db->createCommand("SELECT id FROM users WHERE username='runtime-admin'")->queryScalar();
$app->user->setIdentity(Users::findOne($adminId)); $scope = Scope::current();
pmsCheck($scope !== null && $scope->isAdmin() && $scope->actorId === $adminId,'Runtime admin scope established with browser sessions disabled');
pmsCheck(app\components\identity\AccountEligibility::isOperatorAccount($uid) && app\components\identity\AccountEligibility::qualifiedMerchant($mid),'Dedicated operator and qualified PG merchant fixture');
function pmsCount(): int { global $db,$uid; return (int)$db->createCommand('SELECT COUNT(*) FROM pos_operator_sessions WHERE user_id=:u',[':u'=>$uid])->queryScalar(); }
function pmsFlag(): int { global $db,$uid; return (int)$db->createCommand('SELECT is_enabled FROM pos_operators WHERE user_id=:u',[':u'=>$uid])->queryScalar(); }
function pmsEnable(): void { global $scope,$uid; PosManagement::setOperatorEnabled($scope,$uid,true); }
function pmsFresh(): void { global $app; if (!$app->cache->flush()) throw new RuntimeException('Integrated D3 cache reset failed.'); }
$initialIsolation = $db->createCommand('SELECT @@session.transaction_isolation')->queryScalar();

// Login-first: direct API is held after INSERT with merchant/profile/user locks still held.
// HTTP variant commits its real 201 before the real C4 disable starts.
foreach (['api-direct-hold','api-http'] as $mode) {
    pmsFresh(); pmsEnable(); $api = []; $c4 = []; $before = pmsCount();
    try {
        $api = pmsSpawn($mode,$uid,$adminId);
        pmsCheck(pmsRead($api) === ($mode === 'api-http' ? "http-start\n" : "merchant-lock\n"),'Login-first worker enters actual API: ' . $mode);
        $line = pmsRead($api);
        pmsCheck(preg_match($mode === 'api-http' ? '/^created:([1-9][0-9]*)\n$/D' : '/^inserted:([1-9][0-9]*)\n$/D',$line,$match),'Login-first real API produced session ID without exposing bearer: ' . $mode);
        $id = (int)$match[1];
        if ($mode !== 'api-http') pmsCheck(pmsCount() === $before,'Uncommitted API INSERT is invisible to independent parent connection');
        $c4 = pmsSpawn('disable',$uid,$adminId);
        pmsCheck(pmsRead($c4) === "merchant-lock\n",'Real C4 disable reaches common merchant lock: ' . $mode);
        if ($mode !== 'api-http') {
            pmsWaiting($c4,'C4 cannot finish while real API transaction holds merchant lock');
            pmsSend($api,'c'); pmsCheck(pmsRead($api) === 'created:' . $id . "\n",'Real API commit released before waiting C4 continues');
        }
        $line = pmsRead($c4);
        pmsCheck(preg_match('/^disabled:([1-9][0-9]*)\n$/D',$line,$revoked),'Real C4 disable commits with nonzero revocation count: ' . $mode);
        pmsCheck(pmsClose($c4) === 0 && pmsFlag() === 0 && pmsCount() === $before+1,'Login-first workers leave one inserted session and disabled profile: ' . $mode);
        $row = $db->createCommand('SELECT revoked_at,revoked_reason FROM pos_operator_sessions WHERE id=:id AND user_id=:u',[':id'=>$id,':u'=>$uid])->queryOne();
        pmsCheck($row && $row['revoked_at'] !== null && $row['revoked_reason'] === 'operator_disabled','C4 persisted revocation of the exact real API session: ' . $mode);
        pmsEnable(); pmsSend($api,'v');
        pmsCheck(pmsRead($api) === "invalid\n",'Reenable never revives worker bearer (HTTP variant also checks catalog 401): ' . $mode);
        pmsCheck(pmsClose($api) === 0 && pmsCount() === $before+1,'Login-first API worker exits without creating replacement bearer: ' . $mode);
    } finally { if ($api !== []) pmsClose($api,true); if ($c4 !== []) pmsClose($c4,true); pmsEnable(); }
}

// Disable-first: pause inside actual C4 service AFTER profile UPDATE, BEFORE commit.
foreach (['api-direct','api-http'] as $mode) {
    pmsFresh(); pmsEnable(); $api = []; $c4 = []; $before = pmsCount();
    try {
        $c4 = pmsSpawn('disable-hold',$uid,$adminId);
        pmsCheck(pmsRead($c4) === "merchant-lock\n" && pmsRead($c4) === "profile-uncommitted\n",'Real C4 holds transaction after disabling profile: ' . $mode);
        pmsCheck(pmsFlag() === 1,'C4 disabled flag remains uncommitted and invisible to independent connection: ' . $mode);
        $api = pmsSpawn($mode,$uid,$adminId);
        pmsCheck(pmsRead($api) === ($mode === 'api-http' ? "http-start\n" : "merchant-lock\n"),'Concurrent real API starts behind C4 transaction: ' . $mode);
        pmsWaiting($api,'API cannot complete before C4 releases disable transaction: ' . $mode);
        pmsSend($c4,'c'); $line = pmsRead($c4);
        pmsCheck(preg_match('/^disabled:([0-9]+)\n$/D',$line) && pmsClose($c4) === 0,'Real C4 completes disable before concurrent login result: ' . $mode);
        pmsCheck(pmsRead($api) === ($mode === 'api-http' ? "denied401\n" : "denied\n"),'Concurrent real API sees committed disabled profile, null/401 without bearer: ' . $mode);
        pmsCheck(pmsClose($api) === 0 && pmsFlag() === 0 && pmsCount() === $before,'Disable-first creates no session row: ' . $mode);
        pmsEnable(); pmsCheck(pmsCount() === $before,'Reenable after denied concurrent login creates no implicit session: ' . $mode);
    } finally { if ($api !== []) pmsClose($api,true); if ($c4 !== []) pmsClose($c4,true); pmsEnable(); }
}

pmsFresh(); $login = pmsHttp();
pmsCheck($login['status'] === 201 && is_string($login['json']['access_token'] ?? null),'Fresh HTTP login succeeds after C4 reenables profile');
$token = $login['json']['access_token']; $pmsSecrets[] = $token;
$session = $db->createCommand('SELECT id FROM pos_operator_sessions WHERE token_sha256=:d',[':d'=>hash('sha256',$token)])->queryScalar();
$revoked = PosManagement::setOperatorEnabled($scope,$uid,false); $before = pmsCount();
pmsCheck($revoked >= 1 && pmsFlag() === 0,'Actual C4 service disables and revokes current HTTP session');
pmsCheck(pmsError(pmsHttp(),'invalid_credentials') && pmsCount() === $before,'HTTP login on already disabled profile returns generic 401 and inserts no row');
pmsCheck(PosApi::createSession(Users::findOne($uid),$prefix,null) === null && pmsCount() === $before,'Direct API on already disabled profile returns null and inserts no row');
$logout = pmsHttp('DELETE',$token);
pmsCheck($logout['status'] === 204 && $logout['body'] === '' && pmsCount() === $before,'Logout remains empty 204 while C4 profile is disabled');
pmsEnable();
pmsCheck(PosApi::findActiveSession($token,$prefix) === null && pmsError(pmsHttp('GET',$token,'terminals'),'invalid_token'),'HTTP bearer revoked by C4 remains unusable after reenable');

pmsFresh(); $restoredLogin = pmsHttp();
pmsCheck($restoredLogin['status'] === 201 && pmsCount() === $before+1,'Fresh HTTP login after explicit C4 reenable returns 201 and creates one new session');
$pmsSecrets[] = $restoredLogin['json']['access_token'];
pmsCheck(PosApi::findActiveSession($restoredLogin['json']['access_token'],$prefix) !== null,'Admin-revocation race starts with a genuinely active session to preserve');

// Parent SQL holds ONLY merchant coordination lock; disable itself still uses real C4.
// The worker has an initially valid Scope and stale identity when admin is deactivated.
$beforeProfile = $db->createCommand('SELECT * FROM pos_operators WHERE user_id=:u',[':u'=>$uid])->queryOne();
$beforeSessions = $db->createCommand('SELECT * FROM pos_operator_sessions WHERE user_id=:u ORDER BY id',[':u'=>$uid])->queryAll();
$beforeLogs = (int)$db->createCommand('SELECT COUNT(*) FROM logs')->queryScalar();
$c4 = []; $lock = null;
try {
    $lock = $db->beginTransaction(yii\db\Transaction::READ_COMMITTED);
    $db->createCommand('SELECT id FROM merchants WHERE id=:m FOR UPDATE',[':m'=>$mid])->queryScalar();
    $c4 = pmsSpawn('disable',$uid,$adminId);
    pmsCheck(pmsRead($c4) === "merchant-lock\n",'C4 admin scope acquired before merchant-lock wait');
    pmsWaiting($c4,'Real C4 admin operation remains blocked on merchant lock');
    $db->createCommand()->update('users',['is_active'=>0],['id'=>$adminId])->execute(); $lock->commit();
    pmsCheck(pmsRead($c4) === "not-found\n" && pmsClose($c4) === 0,'Admin deactivated during lock wait: actual C4 rechecks Scope and denies write');
    pmsCheck(Scope::current() === null,'Stale in-memory admin identity cannot regain Scope after DB deactivation');
    pmsCheck($beforeProfile === $db->createCommand('SELECT * FROM pos_operators WHERE user_id=:u',[':u'=>$uid])->queryOne(),'Denied stale-admin C4 write leaves operator attributes unchanged');
    pmsCheck($beforeSessions === $db->createCommand('SELECT * FROM pos_operator_sessions WHERE user_id=:u ORDER BY id',[':u'=>$uid])->queryAll(),'Denied stale-admin C4 write neither revokes nor inserts sessions');
    pmsCheck($beforeLogs === (int)$db->createCommand('SELECT COUNT(*) FROM logs')->queryScalar(),'Denied stale-admin C4 write has no success audit');
} finally {
    if ($lock !== null && $lock->isActive) $lock->rollBack();
    if ($c4 !== []) pmsClose($c4,true);
    $db->createCommand()->update('users',['is_active'=>1],['id'=>$adminId])->execute();
    $app->user->setIdentity(Users::findOne($adminId)); $scope = Scope::current();
}
pmsCheck($scope !== null && $scope->isAdmin() && pmsFlag() === 1,'Admin and operator fixture restored after denied concurrent write');
pmsCheck($db->getTransaction() === null && $db->createCommand('SELECT @@session.transaction_isolation')->queryScalar() === $initialIsolation,'Integrated C4/API tests leave no open transaction or global isolation change');
$logs = json_encode($db->createCommand('SELECT description,controller,action FROM logs')->queryAll());
foreach (glob($root . '/runtime/logs/*.log') ?: [] as $file) $logs .= file_get_contents($file);
pmsCheck(!preg_match('/napos_[A-Za-z0-9_-]{43}/',$logs) && !array_filter($pmsSecrets,static fn($secret)=>str_contains($logs,$secret)),'No bearer, client key or fixture password in integrated C4/API logs');
echo "PASS {$pmsChecks} integrated C4/API D3 session checks\n";
