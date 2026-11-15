<?php
// SIP-022 B1 W3b: esecutore merchant_account su DB usa-e-getta con l'utente worker ristretto; HTTP finto (nessuna rete).
// stdin: password del worker (prima riga). Ogni scenario usa un'istanza diversa (account e lavori separati).
if (PHP_SAPI !== 'cli') exit(1);
ini_set('zend.exception_ignore_args', '0');
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
use app\components\btcpay\provisioning\{ProvisioningHttpException as Http, ProvisioningJobs, ProvisioningStore, ProvisioningWorker};
use app\components\btcpay\provisioning\steps\MerchantAccountStep;

$password = trim((string) fgets(STDIN));
if (!preg_match('/^[0-9a-f]{48}$/D', $password)) exit(1);
$app = new yii\console\Application(require $root . '/config/console.php');
$admin = $app->db;
if ($admin->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);
$connect = static function () use ($password): yii\db\Connection {
    $c = new yii\db\Connection(['dsn' => env('DB_DSN'), 'username' => 'napay_b1b_worker', 'password' => $password, 'charset' => 'utf8mb4']);
    $c->open();
    return $c;
};
$worker = $connect();
$checks = 0;
function check($condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException($label); ++$checks; echo 'PASS: ' . $label . "\n"; }

// --- fixture: merchant del titolare PG qualificato
$merchant = (int) $admin->createCommand("SELECT id FROM merchants WHERE description='Runtime company merchant'")->queryScalar();
$owner = (int) $admin->createCommand('SELECT user_id FROM merchants WHERE id=:m', [':m' => $merchant])->queryScalar();
$admin->createCommand()->insert('member_profiles', ['user_id' => $owner, 'is_corporate' => 1, 'denomination' => 'W3b PG'])->execute();
$app->set('db', $worker);
check(app\components\identity\AccountEligibility::merchantForManager($owner) === $merchant, 'Fixture merchant qualified, read through the worker grants');

/** Client finto: coda di risposte/eccezioni/callable, registra ogni chiamata. */
final class FakeHttp
{
    public array $calls = []; public array $queue = [];
    public function key(): Closure { return function (string $m, string $p, ?array $b, string $k) { $this->calls[] = [$m, $p, $b, 'key:' . $k]; return $this->next(); }; }
    public function basic(): Closure { return function (string $m, string $p, ?array $b, string $u, string $pw) { $this->calls[] = [$m, $p, $b, 'basic:' . $u . ':' . $pw]; return $this->next(); }; }
    private function next(): array {
        if (Yii::$app->db->getTransaction() !== null) throw new RuntimeException('HTTP inside a database transaction');
        $reply = array_shift($this->queue) ?? throw new RuntimeException('Unexpected HTTP call');
        if ($reply instanceof Closure) $reply = $reply();
        if ($reply instanceof Throwable) throw $reply;
        return $reply;
    }
}
$r = static fn(int $status, $json = null, ?string $retry = null): array => ['status' => $status, 'retry_after' => $retry, 'json' => $json];
$perm = ['btcpay.store.canmodifystoresettings'];
$t = time();
$clock = static function () use (&$t): int { return $t; };
$uuid = static function (): string { $b = random_bytes(16); $b[6] = chr(ord($b[6]) & 0x0f | 0x40); $b[8] = chr(ord($b[8]) & 0x3f | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4)); };
$prov = 'prov-' . bin2hex(random_bytes(16));
$keyring = ['k1' => random_bytes(32)];
/** Nuovo scenario: istanza, coda, store, client finto, worker. */
$scenario = static function () use ($worker, $clock, $uuid, $prov, $keyring, $merchant, $admin, &$t) {
    $instance = bin2hex(random_bytes(32));
    $http = new FakeHttp();
    $store = new ProvisioningStore($worker, $instance, $keyring, 'k1');
    $jobs = new ProvisioningJobs($worker, $uuid(), 60, $clock);
    $run = new ProvisioningWorker($worker, $jobs, $store, new MerchantAccountStep($http->key(), $http->basic()), $instance, $prov, $clock);
    $admin->createCommand()->insert('btcpay_provisioning_jobs', ['kind' => 'merchant_account', 'instance' => $instance, 'merchant_id' => $merchant,
        'entity_id' => $merchant, 'created_at' => $t, 'updated_at' => $t])->execute();
    return [$instance, $http, $store, $run, (int) $admin->lastInsertID];
};
$job = static fn(int $id): array => $admin->createCommand('SELECT state,attempts,next_attempt_at,in_flight_op,last_error_code FROM btcpay_provisioning_jobs WHERE id=:id', [':id' => $id])->queryOne();
$happy = static fn(string $user = 'user-1', string $key = 'k-0123456789abcdef0123456789') => [$r(201, ['id' => $user]), $r(200, ['apiKey' => $key, 'permissions' => $perm]), $r(200, [])];

// --- percorso completo A -> B -> C
[$instance, $http, $store, $run, $id] = $scenario();
$http->queue = $happy();
check($run->runOnce() === true, 'A job was taken');
$account = $store->account($merchant);
$stored = $store->password($account);
check($job($id)['state'] === 'done' && $account['state'] === 'synced' && $account['remote_user_id'] === 'user-1', 'Account synced and job done after A, B and C');
check(count($http->calls) === 3 && $http->calls[0][0] === 'POST' && $http->calls[0][1] === '/api/v1/users' && $http->calls[0][3] === 'key:' . $prov
    && $http->calls[1][1] === '/api/v1/api-keys' && $http->calls[1][3] === 'basic:' . $account['technical_email'] . ':' . $stored
    && $http->calls[2] === ['GET', '/api/v1/stores', null, 'key:k-0123456789abcdef0123456789'], 'Exactly POST user, POST key (Basic, stored password), GET stores with the new key');
check(preg_match('/^[0-9a-f]{64}$/', $stored) && $http->calls[0][2]['password'] === $stored, 'Password: 64 hex, stored before A and the one sent');
check($store->currentKey($account) === ['generation' => 1, 'key' => 'k-0123456789abcdef0123456789'] && $account['key_generations_used'] === 1, 'Key generation 1 current only after verification');
check($run->runOnce() === false, 'No job left: runOnce returns false');

// --- A 429: nessuna esecuzione, stessa password al nuovo tentativo
[$instance, $http, $store, $run, $id] = $scenario();
$http->queue = [$r(429, null, '120')];
$run->runOnce();
$account = $store->account($merchant);
$first = $store->password($account);
check($job($id)['state'] === 'retry_wait' && (int) $job($id)['next_attempt_at'] >= $t + 120 && $account['state'] === 'user_requested', 'A 429: retry_wait at Retry-After, account user_requested');
check($run->runOnce() === false && count($http->calls) === 1, 'Not retried before Retry-After');
$t += 130;
$http->queue = $happy('user-2');
$run->runOnce();
check($http->calls[1][2]['password'] === $first && $store->account($merchant)['state'] === 'synced', 'Retry reuses the saved password (never regenerated) and completes');
$t -= 130;

// --- A esito ignoto: riconciliazione, mai un secondo POST
[$instance, $http, $store, $run, $id] = $scenario();
$http->queue = [new Http(Http::OUTCOME_UNKNOWN)];
$run->runOnce();
check($job($id)['state'] === 'reconcile_required' && $store->account($merchant)['state'] === 'reconcile_required', 'A unknown: job and account in reconciliation');
$t += 3600;
check($run->runOnce() === false && count($http->calls) === 1, 'No automatic second POST after an unknown outcome');
$t -= 3600;
[$instance, $http, $store, $run, $id] = $scenario();
$http->queue = [$r(422, ['code' => 'duplicate'])];
$run->runOnce();
check($job($id)['state'] === 'reconcile_required' && $job($id)['last_error_code'] === 'user_exists', 'A 422: reconciliation (A-r later), no adoption');
[$instance, $http, $store, $run, $id] = $scenario();
$http->queue = [$r(403)];
$run->runOnce();
check($job($id)['state'] === 'failed' && $store->account($merchant)['state'] === 'failed' && count($http->calls) === 1, 'A 403: failed, no escalation');

// --- B 429 = deferred, generazione non consumata
[$instance, $http, $store, $run, $id] = $scenario();
$http->queue = [$r(201, ['id' => 'user-3']), $r(429, null, '60')];
$run->runOnce();
$account = $store->account($merchant);
check($job($id)['state'] === 'deferred' && $account['state'] === 'user_created' && $account['key_generations_used'] === 0, 'B 429: deferred (admin), generation counter restored');
$t += 90000;
check($run->runOnce() === false && count($http->calls) === 2, 'Deferred job never claimed automatically');
$t -= 90000;

// --- B esito ignoto: generazione consumata, nuova generazione dalla coda, limite 3
[$instance, $http, $store, $run, $id] = $scenario();
$http->queue = [$r(201, ['id' => 'user-4']), new Http(Http::OUTCOME_UNKNOWN)];
$run->runOnce();
$account = $store->account($merchant);
check($job($id)['state'] === 'retry_wait' && $account['key_generations_used'] === 1 && $account['state'] === 'user_created', 'B unknown: generation 1 consumed, retry later');
for ($g = 2; $g <= 3; ++$g) {
    $t += 7200;
    $http->queue = [new Http(Http::OUTCOME_UNKNOWN)];
    $run->runOnce();
    check(end($http->calls)[2]['label'] === 'napay-' . $account['ref'] . '-g' . $g, "Retry uses generation $g");
}
$t += 7200;
$calls = count($http->calls);
$run->runOnce();
check($job($id)['state'] === 'reconcile_required' && $job($id)['last_error_code'] === 'generations_exhausted' && count($http->calls) === $calls, 'Beyond 3 generations: reconciliation without any call');
$t -= 3 * 7200;

// --- Ripresa da key_saved: solo GET di verifica, nessun POST
[$instance, $http, $store, $run, $id] = $scenario();
$account = $store->ensureAccount($merchant);
$store->storePassword($account, bin2hex(random_bytes(32)));
$account = $store->updateAccount($account, ['state' => 'user_created', 'remote_user_id' => 'user-5', 'key_generations_used' => 1]);
$store->storeKey($account, 1, 'k-resume-0123456789abcdef');
$store->updateAccount($account, ['state' => 'key_saved']);
$http->queue = [$r(200, [['id' => 'store-1']])];
$run->runOnce();
check(count($http->calls) === 1 && $http->calls[0][0] === 'GET' && $store->account($merchant)['state'] === 'synced'
    && $store->currentKey($store->account($merchant))['generation'] === 1, 'Crash after key_saved: resumed with GET verification only, key promoted');

// --- Merchant non idoneo prima e durante l'HTTP
[$instance, $http, $store, $run, $id] = $scenario();
$admin->createCommand()->update('users', ['is_active' => 0], ['id' => $owner])->execute();
$run->runOnce();
$admin->createCommand()->update('users', ['is_active' => 1], ['id' => $owner])->execute();
check($http->calls === [] && $job($id)['state'] === 'failed' && $job($id)['last_error_code'] === 'merchant_ineligible' && $store->account($merchant) === null, 'Disabled owner before work: no call or mapping, job failed');
[$instance, $http, $store, $run, $id] = $scenario();
$http->queue = [function () use ($admin, $owner, $r) { $admin->createCommand()->update('users', ['is_active' => 0], ['id' => $owner])->execute(); return $r(201, ['id' => 'user-late']); }];
$run->runOnce();
$admin->createCommand()->update('users', ['is_active' => 1], ['id' => $owner])->execute();
$account = $store->account($merchant);
check($job($id)['state'] === 'failed' && $account['state'] === 'suspended' && $account['remote_user_id'] === null && count($http->calls) === 1, 'Disabled during HTTP: late result not adopted (no remote id), account suspended');

// --- Lease scaduta durante l'HTTP: esito scartato, poi solo riconciliazione
[$instance, $http, $store, $run, $id] = $scenario();
$http->queue = [function () use (&$t, $r) { $t += 61; return $r(201, ['id' => 'user-6']); }];
$run->runOnce();
check($job($id)['state'] === 'in_flight' && $store->account($merchant)['remote_user_id'] === null, 'Lease expired during HTTP: outcome discarded');
$run->runOnce();
check($job($id)['state'] === 'reconcile_required' && count($http->calls) === 1, 'Expired in-flight job goes to reconciliation, no second POST');
$t -= 61;

// --- Risorsa occupata da un altro lavoro (altra connessione con GET_LOCK)
[$instance, $http, $store, $run, $id] = $scenario();
$other = $connect();
$lock = 'napay-btcpay-' . substr(hash('sha256', $instance . '|merchant|' . $merchant), 0, 40);
check((int) $other->createCommand('SELECT GET_LOCK(:l, 0)', [':l' => $lock])->queryScalar() === 1, 'Another connection holds the resource lock');
$run->runOnce();
check($http->calls === [] && $job($id)['state'] === 'retry_wait' && $job($id)['last_error_code'] === 'resource_busy', 'Same resource busy: no HTTP, job retried later');
$other->createCommand('SELECT RELEASE_LOCK(:l)', [':l' => $lock])->queryScalar();
check((int) $worker->createCommand('SELECT IS_USED_LOCK(:l)', [':l' => $lock])->queryScalar() === 0, 'Worker never keeps the lock after runOnce');

// --- Scope and local preparation are fenced before any mapping/GET.
[$instance, $http, $store, $run, $id] = $scenario();
$admin->createCommand()->update('btcpay_provisioning_jobs', ['entity_id' => $merchant + 1], ['id' => $id])->execute();
$run->runOnce();
check($job($id)['state'] === 'failed' && $job($id)['last_error_code'] === 'resource_scope_mismatch' && $store->account($merchant) === null && $http->calls === [], 'Mismatched job resource: no mapping or HTTP');
[$instance, $http, $store, $run, $id] = $scenario();
$admin->createCommand()->update('btcpay_provisioning_jobs', ['merchant_id' => 2147483647, 'entity_id' => 2147483647], ['id' => $id])->execute();
$run->runOnce();
check($job($id)['state'] === 'failed' && $job($id)['last_error_code'] === 'merchant_ineligible' && $store->account(2147483647) === null && $http->calls === [], 'Nonexistent merchant: controlled failure without FK insert or HTTP');
[$instance, $http, $store, $run, $id] = $scenario();
$tick = 0;
$expiredClock = static function () use (&$tick, &$t): int { return ++$tick === 1 ? $t : $t + 61; };
$run = new ProvisioningWorker($worker, new ProvisioningJobs($worker, $uuid(), 60, $expiredClock), $store, new MerchantAccountStep($http->key(), $http->basic()), $instance, $prov, $clock);
$run->runOnce();
check($store->account($merchant) === null && $http->calls === [] && $job($id)['state'] === 'running', 'Lease expired after claim: no local mapping or HTTP');

[$instance, $http, $store, $run, $id] = $scenario();
$saved = $store->ensureAccount($merchant);
$store->storePassword($saved, bin2hex(random_bytes(32)));
$saved = $store->updateAccount($saved, ['state' => 'user_created', 'remote_user_id' => 'resume-disabled', 'key_generations_used' => 1]);
$store->storeKey($saved, 1, 'key-resume-not-a-real-secret');
$store->updateAccount($saved, ['state' => 'key_saved']);
$admin->createCommand()->update('users', ['is_active' => 0], ['id' => $owner])->execute();
$run->runOnce();
$admin->createCommand()->update('users', ['is_active' => 1], ['id' => $owner])->execute();
check($http->calls === [] && $job($id)['state'] === 'failed' && $store->account($merchant)['state'] === 'suspended', 'Disabled owner with key_saved: verification GET never sent');

[$instance, $http, $store, $run, $id] = $scenario();
$saved = $store->ensureAccount($merchant);
$store->storePassword($saved, bin2hex(random_bytes(32)));
$saved = $store->updateAccount($saved, ['state' => 'user_created', 'remote_user_id' => 'resume-expired', 'key_generations_used' => 1]);
$store->storeKey($saved, 1, 'key-resume-expired-fixture');
$store->updateAccount($saved, ['state' => 'key_saved']);
$getClock = static function () use ($admin, $id, &$t): int {
    $version = (int) $admin->createCommand('SELECT version FROM btcpay_provisioning_jobs WHERE id=:id', [':id' => $id])->queryScalar();
    return $version >= 2 ? $t + 61 : $t;
};
$run = new ProvisioningWorker($worker, new ProvisioningJobs($worker, $uuid(), 60, $getClock), $store, new MerchantAccountStep($http->key(), $http->basic()), $instance, $prov, $clock);
$run->runOnce();
check($http->calls === [] && $job($id)['state'] === 'running' && $store->account($merchant)['state'] === 'key_saved', 'Lease expires between initial preparation and GET: no verification request or promotion');

[$instance, $http, $store, $run, $id] = $scenario();
$saved = $store->ensureAccount($merchant);
$store->storePassword($saved, bin2hex(random_bytes(32)));
$saved = $store->updateAccount($saved, ['state' => 'user_created', 'remote_user_id' => 'resume-rate-limit', 'key_generations_used' => 1]);
$store->storeKey($saved, 1, 'key-rate-limit-fixture-only');
$store->updateAccount($saved, ['state' => 'key_saved']);
$http->queue = [function () use ($admin, $owner, $r) {
    $admin->createCommand()->update('users', ['is_active' => 0], ['id' => $owner])->execute();
    return $r(429, null, '60');
}];
$run->runOnce();
$admin->createCommand()->update('users', ['is_active' => 1], ['id' => $owner])->execute();
check(count($http->calls) === 1 && $job($id)['state'] === 'failed' && $store->account($merchant)['state'] === 'suspended', 'Disabled during verification 429: retry outcome rechecks eligibility');

// A native/callback failure must not propagate DB objects, closures or a previous exception.
[$instance, $http, $store, $run, $id] = $scenario();
$pending = $store->ensureAccount($merchant);
$store->storePassword($pending, bin2hex(random_bytes(32)));
$admin->createCommand()->update('btcpay_merchant_credentials', ['state' => 'pending'], ['account_id' => $pending['id'], 'kind' => 'onboarding_password'])->execute();
$safe = false;
try { $run->runOnce(); } catch (RuntimeException $e) {
    $trace = print_r($e->getTrace(), true) . var_export($e->getTrace(), true);
    $safe = $e->getMessage() === 'provisioning_worker_failed' && $e->getPrevious() === null && !str_contains($trace, $password)
        && !str_contains($trace, $prov) && !str_contains($trace, $keyring['k1']) && !str_contains($trace, 'Closure Object');
}
check($safe && $http->calls === [] && $job($id)['state'] === 'running', 'Full callback failure trace is sanitized; failed checkpoint rolled back');

// --- Connessione e segreti
$app->set('db', $admin);
$refused = false;
try { $run->runOnce(); } catch (LogicException $e) { $refused = $e->getMessage() === 'worker_connection_mismatch'; }
check($refused, 'Eligibility must run on the worker connection (same transactions)');
$app->set('db', $worker);
$frames = '';
// Real credential-bearing dependencies, not a bare connection that would hide a leak.
$bare = $worker;
$fresh = new FakeHttp();
try { new ProvisioningWorker($worker, new ProvisioningJobs($bare, $uuid()), new ProvisioningStore($bare, $instance, $keyring, 'k1'), new MerchantAccountStep($fresh->key(), $fresh->basic()), 'bad', $prov); }
catch (InvalidArgumentException $e) { $frames = print_r($e->getTrace(), true); }
check($frames !== '' && !str_contains($frames, $prov) && !str_contains($frames, $password) && !str_contains($frames, $keyring['k1']), 'Full constructor trace hides nested DB, keyring, transport and provisioner');
echo "PASS $checks BTCPay provisioning worker checks\n";
