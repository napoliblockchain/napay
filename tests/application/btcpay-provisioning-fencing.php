<?php
// SIP-022 B1 W3a: esiti della coda con callback di mapping nella transazione interna (fencing), DB usa-e-getta,
// utente worker ristretto. stdin: password del worker (prima riga). Nessun segreto stampato.
if (PHP_SAPI !== 'cli') exit(1);
ini_set('zend.exception_ignore_args', '0');
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@app', $root);
use app\components\btcpay\provisioning\{ProvisioningJobs, ProvisioningStore};

$password = trim((string) fgets(STDIN));
if (!preg_match('/^[0-9a-f]{48}$/D', $password)) exit(1);
$connect = static function () use ($password): yii\db\Connection {
    $c = new yii\db\Connection(['dsn' => env('DB_DSN'), 'username' => 'napay_b1b_worker', 'password' => $password, 'charset' => 'utf8mb4']);
    $c->open();
    return $c;
};
$instance = hash('sha256', 'https://btcpay.regtest.invalid');
$keyring = ['k1' => str_repeat("\x07", 32)];
// Figli: A esegue un checkpoint con callback che si ferma (o muore) a metà; B tenta un esito concorrente.
if (in_array($argv[1] ?? '', ['--hold', '--crash', '--late'], true)) {
    try {
        $db = $connect();
        [$id, $version, $owner, $merchant] = [(int) $argv[2], (int) $argv[3], $argv[4], (int) $argv[5]];
        $jobs = new ProvisioningJobs($db, $owner, 60);
        $store = new ProvisioningStore($db, $instance, $keyring, 'k1');
        if ($argv[1] === '--late') {
            fwrite(STDOUT, "ready\n");
            $result = $jobs->succeed($id, $version);
            fwrite(STDOUT, ($result === null ? 'null' : (string) $result) . "\n");
            exit(0);
        }
        $result = $jobs->checkpoint($id, $version, 'create_user', function () use ($store, $merchant, $argv) {
            $account = $store->account($merchant);
            $store->updateAccount($account, ['state' => 'user_requested']);
            fwrite(STDOUT, "in_callback\n"); fread(STDIN, 1);
            if ($argv[1] === '--crash') exit(9);
        });
        fwrite(STDOUT, ($result === null ? 'null' : (string) $result) . "\n");
        exit(0);
    } catch (Throwable $e) { exit(2); }
}
$app = new yii\console\Application(require $root . '/config/console.php');
$db = $app->db;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);
$worker = $connect();
$checks = 0;
function check($condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException($label); ++$checks; echo 'PASS: ' . $label . "\n"; }
$uuid = static function (): string { $b = random_bytes(16); $b[6] = chr(ord($b[6]) & 0x0f | 0x40); $b[8] = chr(ord($b[8]) & 0x3f | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4)); };
$t = time();
$clock = static function () use (&$t): int { return $t; };
$ownerA = $uuid(); $ownerB = $uuid();
$a = new ProvisioningJobs($worker, $ownerA, 60, $clock);
$b = new ProvisioningJobs($connect(), $ownerB, 60, $clock);
$store = new ProvisioningStore($worker, $instance, $keyring, 'k1');
$merchant = (int) $db->createCommand("SELECT id FROM merchants WHERE description='Runtime company merchant'")->queryScalar();
$generation = 0;
$job = static function () use ($db, $instance, $merchant, &$generation, &$t): int {
    $db->createCommand()->insert('btcpay_provisioning_jobs', ['kind' => 'merchant_account', 'instance' => $instance, 'merchant_id' => $merchant, 'entity_id' => $merchant,
        'generation' => ++$generation, 'created_at' => $t, 'updated_at' => $t])->execute();
    return (int) $db->lastInsertID;
};
$row = static fn(int $id): array => $db->createCommand('SELECT state,version,lease_owner,in_flight_op FROM btcpay_provisioning_jobs WHERE id=:id', [':id' => $id])->queryOne();
$accountState = static fn(): string => (string) $db->createCommand('SELECT state FROM btcpay_merchant_accounts WHERE instance=:i AND merchant_id=:m', [':i' => $instance, ':m' => $merchant])->queryScalar();
$passwords = static fn(): int => (int) $db->createCommand("SELECT COUNT(*) FROM btcpay_merchant_credentials c JOIN btcpay_merchant_accounts a ON a.id=c.account_id WHERE a.merchant_id=:m AND a.instance=:i AND c.kind='onboarding_password'", [':m' => $merchant, ':i' => $instance])->queryScalar();
$account = $store->ensureAccount($merchant);

// --- Token non valido: nessun callback, nessuna scrittura
$id = $job(); $claimed = $a->claim($instance); $v = $claimed['version'];
$called = 0;
$mark = function () use (&$called) { ++$called; };
check($a->checkpoint($id, $v + 1, 'create_user', $mark) === null && $b->checkpoint($id, $v, 'create_user', $mark) === null && $called === 0, 'Stale version or other owner: callback never called');
$t += 61;
check($a->checkpoint($id, $v, 'create_user', $mark) === null && $called === 0 && $row($id)['state'] === 'running', 'Expired lease: callback never called, job unchanged');
$t -= 61;

// --- Checkpoint atomico con il mapping
$secret = 'pw-' . bin2hex(random_bytes(16));
$v2 = $a->checkpoint($id, $v, 'create_user', function () use ($store, &$account, $secret) {
    $account = $store->updateAccount($account, ['state' => 'user_requested']);
    $store->storePassword($account, $secret);
});
check($v2 === $v + 1 && $row($id)['state'] === 'in_flight' && $accountState() === 'user_requested' && $passwords() === 1, 'Checkpoint and mapping (state + encrypted password) committed together');
check($a->checkpoint($id, $v2, 'create_user', $mark) === null && $called === 0, 'Already in flight: no second checkpoint, no callback');

// --- Callback in errore: rollback di lavoro e mapping
$before = [$row($id), $accountState()];
$thrown = false;
try {
    $a->succeed($id, $v2, function () use ($store, &$account) { $store->updateAccount($account, ['state' => 'user_created', 'remote_user_id' => 'u-1']); throw new RuntimeException('boom'); });
} catch (RuntimeException $e) { $thrown = $e->getMessage() === 'boom'; }
$account = $store->account($merchant);
check($thrown && [$row($id), $accountState()] === $before && $worker->getTransaction() === null, 'Callback failure rethrown; job and mapping rolled back, no transaction left open');

// --- Lease scaduta durante il callback: rollback
$result = $a->succeed($id, $v2, function () use ($store, &$account, &$t) { $store->updateAccount($account, ['state' => 'user_created', 'remote_user_id' => 'u-1']); $t += 61; });
$account = $store->account($merchant);
check($result === null && [$row($id), $accountState()] === $before, 'Lease expiring inside the callback: CAS with fresh time fails, everything rolled back');
$t -= 61;

// --- Esito riuscito e atomico
$v3 = $a->succeed($id, $v2, function () use ($store, &$account) { $account = $store->updateAccount($account, ['state' => 'user_created', 'remote_user_id' => 'u-1']); });
check($v3 === $v2 + 1 && $row($id)['state'] === 'done' && $accountState() === 'user_created', 'Success outcome and mapping committed together');
check($a->succeed($id, $v3, $mark) === null && $called === 0, 'Late repeat: callback not called');
$jobsBefore = (int) $db->createCommand('SELECT COUNT(*) FROM btcpay_provisioning_jobs')->queryScalar();

// --- Nessuna transazione esterna, e il callback non può rientrare nella coda
$transaction = $worker->beginTransaction();
$refused = false;
try { $a->succeed($id, $v3, $mark); } catch (LogicException $e) { $refused = $e->getMessage() === 'caller_transaction_open'; }
$transaction->rollBack();
check($refused && $called === 0, 'Caller transaction still refused');
$id2 = $job(); $c2 = $a->claim($instance);
$nested = false;
try { $a->reconcile($id2, $c2['version'], 'x', function () use ($a, $id2, $c2) { $a->succeed($id2, $c2['version']); }); } catch (LogicException $e) { $nested = $e->getMessage() === 'caller_transaction_open'; }
check($nested && $row($id2)['state'] === 'running', 'Callback cannot issue a second job SQL through the queue');

// --- Senza callback la semantica è invariata
$v4 = $a->retryLater($id2, $c2['version'], 'rate_limited', $t + 30);
check($v4 === $c2['version'] + 1 && $row($id2)['state'] === 'retry_wait', 'Without callback the old single-UPDATE semantics are unchanged');

// --- Due connessioni: B attende il lock di A e poi trova il token cambiato
$t = time();
$id3 = $job();
$a3 = new ProvisioningJobs($worker, $ownerA, 60);
$c3 = $a3->claim($instance);
$account = $store->account($merchant);
$store->updateAccount($account, ['state' => 'pending']);
$hold = proc_open([PHP_BINARY, __FILE__, '--hold', (string) $id3, (string) $c3['version'], $ownerA, (string) $merchant], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $hp);
fwrite($hp[0], $password . "\n"); fflush($hp[0]);
stream_set_timeout($hp[1], 15);
check(fgets($hp[1]) === "in_callback\n", 'Worker A holds the job row inside its callback');
$late = proc_open([PHP_BINARY, __FILE__, '--late', (string) $id3, (string) $c3['version'], $ownerA, (string) $merchant], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $lp);
fwrite($lp[0], $password . "\n"); fclose($lp[0]);
stream_set_timeout($lp[1], 15);
fgets($lp[1]);
usleep(500000);
check($row($id3)['state'] === 'running' && $accountState() === 'pending', 'While A is in its callback nothing is visible to others');
fwrite($hp[0], 'x'); fclose($hp[0]);
$resultA = trim((string) fgets($hp[1])); fclose($hp[1]); proc_close($hold);
$resultB = trim((string) fgets($lp[1])); fclose($lp[1]); proc_close($late);
check($resultA === (string) ($c3['version'] + 1) && $resultB === 'null' && $row($id3)['state'] === 'in_flight' && $accountState() === 'user_requested', 'Concurrent outcome with the old version waits for the lock, then is rejected');

// --- Crash dentro il callback: MySQL annulla tutto; il lavoro si riprende alla scadenza della lease
$id4 = $job();
$c4 = $a3->claim($instance);
$store->updateAccount($store->account($merchant), ['state' => 'pending']);
$crash = proc_open([PHP_BINARY, __FILE__, '--crash', (string) $id4, (string) $c4['version'], $ownerA, (string) $merchant], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $cp);
fwrite($cp[0], $password . "\n"); fflush($cp[0]);
stream_set_timeout($cp[1], 15);
fgets($cp[1]); fwrite($cp[0], 'x'); fclose($cp[0]); fclose($cp[1]);
check(proc_close($crash) === 9 && $row($id4)['state'] === 'running' && $row($id4)['in_flight_op'] === null && $accountState() === 'pending', 'Crash inside the callback: job and mapping rolled back by MySQL');
$t = time() + 61;
$bClock = new ProvisioningJobs($connect(), $ownerB, 60, $clock);
$re = $bClock->claim($instance);
check($re !== null && $re['id'] === $id4 && $re['attempts'] === 2, 'After the lease expires another worker reuses the job (no checkpoint was persisted)');

// --- Segreti: Connection e callback fuori dalle trace
$canary = 'canary-' . bin2hex(random_bytes(10));
$probe = new yii\db\Connection(['dsn' => 'mysql:host=127.0.0.1;dbname=none', 'username' => 'none', 'password' => $canary]);
$frames = '';
try { new ProvisioningJobs($probe, 'bad-owner'); } catch (InvalidArgumentException $e) { $frames = print_r($e->getTrace(), true) . var_export($e->getTrace(), true); }
check($frames !== '' && !str_contains($frames, $canary), 'Constructor trace hides the DB connection password');
$frames = '';
try { $bClock->succeed($re['id'], $re['version'], function () use ($canary) { throw new RuntimeException('x' . strlen($canary)); }); } catch (RuntimeException $e) {
    $frames = print_r(array_filter($e->getTrace(), fn($f) => ($f['class'] ?? '') === ProvisioningJobs::class), true);
}
check($frames !== '' && !str_contains($frames, $canary), 'Callback (capturing secrets) hidden from queue frames');
echo "PASS $checks BTCPay provisioning fencing checks\n";
