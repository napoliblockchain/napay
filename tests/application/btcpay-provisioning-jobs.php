<?php
// SIP-022 B1c: coda dei lavori di provisioning, solo sul DB usa-e-getta del runner.
// stdin: password dell'utente worker usa-e-getta (prima riga), mai negli argomenti.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@app', $root);
use app\components\btcpay\provisioning\ProvisioningJobs;

$password = trim((string) fgets(STDIN));
if (!preg_match('/^[0-9a-f]{48}$/D', $password)) exit(1);
$connectWorker = static function () use ($password): yii\db\Connection {
    $connection = new yii\db\Connection(['dsn' => env('DB_DSN'), 'username' => 'napay_b1b_worker', 'password' => $password, 'charset' => 'utf8mb4']);
    $connection->open();
    return $connection;
};
// Processo figlio per la presa concorrente: connessione worker propria, partenza su segnale.
if (($argv[1] ?? '') === '--claim-child') {
    try {
        $jobs = new ProvisioningJobs($connectWorker(), $argv[3]);
        fwrite(STDOUT, "ready\n"); fread(STDIN, 1);
        $job = $jobs->claim($argv[2]);
        fwrite(STDOUT, $job === null ? "none\n" : $job['id'] . "\n");
        exit(0);
    } catch (Throwable $e) { exit(2); }
}
$app = new yii\console\Application(require $root . '/config/console.php');
$db = $app->db;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);
$worker = $connectWorker(); $other = $connectWorker();

$checks = 0;
function check($value, string $label): void {
    global $checks;
    if (!$value) throw new RuntimeException($label);
    ++$checks; echo 'PASS: ' . $label . "\n";
}
/** Rifiuto con codice fisso, senza riportare l'input. */
function refuses(callable $operation, string $class, string $code, string $label): void {
    $ok = false;
    try { $operation(); } catch (Throwable $e) { $ok = $e instanceof $class && $e->getMessage() === $code; }
    check($ok, $label);
}
$uuid = static function (): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40); $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
};
$t = time();
$clock = static function () use (&$t): int { return $t; };
$ownerA = $uuid(); $ownerB = $uuid();
$a = new ProvisioningJobs($worker, $ownerA, 60, $clock);
$b = new ProvisioningJobs($other, $ownerB, 60, $clock);
check(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $ownerA) === 1 && $ownerA !== $ownerB, 'Distinct UUID v4 owners for two workers');
check($worker->createCommand('SELECT CURRENT_USER()')->queryScalar() === 'napay_b1b_worker@%' && $other->createCommand('SELECT CONNECTION_ID()')->queryScalar() !== $worker->createCommand('SELECT CONNECTION_ID()')->queryScalar(), 'Two real connections as the limited worker user');
$merchant = (int) $db->createCommand("SELECT id FROM merchants WHERE description='Runtime company merchant'")->queryScalar();
$generation = 0;
$instance = static fn(): string => bin2hex(random_bytes(32));
$insert = static function (string $instance, array $over = []) use ($db, $merchant, &$generation, &$t): int {
    $db->createCommand()->insert('btcpay_provisioning_jobs', array_replace(['kind' => 'merchant_account', 'instance' => $instance, 'merchant_id' => $merchant,
        'entity_id' => $merchant, 'generation' => ++$generation, 'created_at' => $t, 'updated_at' => $t], $over))->execute();
    return (int) $db->lastInsertID;
};
$row = static fn(int $id): array => $db->createCommand('SELECT state,attempts,version,lease_owner,lease_until,in_flight_op,in_flight_owner,in_flight_started_at,next_attempt_at,last_error_code FROM btcpay_provisioning_jobs WHERE id=:id', [':id' => $id])->queryOne();

// --- Input non validi: codici fissi, nessuna scrittura ---
$bad = 'secret-looking-' . bin2hex(random_bytes(4));
refuses(fn() => new ProvisioningJobs($worker, $bad), InvalidArgumentException::class, 'invalid_owner', 'Malformed owner refused');
refuses(fn() => new ProvisioningJobs($worker, strtoupper($ownerA)), InvalidArgumentException::class, 'invalid_owner', 'Uppercase owner refused');
refuses(fn() => new ProvisioningJobs($worker, $ownerA, 0), InvalidArgumentException::class, 'invalid_lease', 'Zero lease refused');
refuses(fn() => new ProvisioningJobs($worker, $ownerA, 3601), InvalidArgumentException::class, 'invalid_lease', 'Lease over one hour refused');
refuses(fn() => $a->claim($bad), InvalidArgumentException::class, 'invalid_instance', 'Malformed instance refused');
refuses(fn() => $a->claim(strtoupper($instance())), InvalidArgumentException::class, 'invalid_instance', 'Uppercase instance refused');
refuses(fn() => $a->checkpoint(1, 0, 'delete_store'), InvalidArgumentException::class, 'invalid_operation', 'Unknown remote operation refused');
refuses(fn() => $a->fail(1, 0, 'HTTP 500: ' . $bad), InvalidArgumentException::class, 'invalid_error_code', 'Free-text error refused');
refuses(fn() => $a->succeed(0, 0), InvalidArgumentException::class, 'invalid_job', 'Non-positive job id refused');
refuses(fn() => $a->succeed(1, -1), InvalidArgumentException::class, 'invalid_job', 'Negative version refused');
refuses(fn() => $a->retryLater(1, 0, 'rate_limited', $t), InvalidArgumentException::class, 'invalid_next_attempt', 'Retry not in the future refused');
refuses(fn() => $a->remoteRejected(1, 0, 500), InvalidArgumentException::class, 'outcome_not_proven', 'Server error is not proof of non-execution');
refuses(fn() => $a->remoteRejected(1, 0, 200), InvalidArgumentException::class, 'outcome_not_proven', 'Success cannot be turned back into running');
refuses(fn() => (new ProvisioningJobs($worker, $ownerA, 60, fn() => '1'))->claim($instance()), UnexpectedValueException::class, 'invalid_clock', 'Non-integer clock refused');

// --- Presa: dovuti, istanza, transazione propria ---
$i1 = $instance(); $i2 = $instance();
$j1 = $insert($i1); $future = $insert($i1, ['next_attempt_at' => $t + 100]); $j2 = $insert($i2);
$transaction = $worker->beginTransaction();
refuses(fn() => $a->claim($i1), LogicException::class, 'caller_transaction_open', 'Claim refuses to join a caller transaction');
refuses(fn() => $a->succeed($j1, 0), LogicException::class, 'caller_transaction_open', 'Outcome refuses to join a caller transaction');
$transaction->rollBack();
check($row($j1)['state'] === 'pending', 'Nothing written while a caller transaction was open');
$job = $a->claim($i1);
check($job === ['id' => $j1, 'kind' => 'merchant_account', 'instance' => $i1, 'merchant_id' => $merchant, 'entity_id' => $merchant, 'generation' => $generation - 2, 'attempts' => 1, 'version' => 1, 'lease_until' => $t + 60], 'Claim returns only identifiers, attempt, version and lease');
check($worker->getTransaction() === null, 'Claim transaction closed before returning');
$r = $row($j1);
check($r['state'] === 'running' && $r['lease_owner'] === $ownerA && (int) $r['lease_until'] === $t + 60 && (int) $r['attempts'] === 1, 'Claimed job running under owner lease');
check($b->claim($i1) === null && $row($future)['state'] === 'pending', 'Leased job not claimed twice; future job not due');
check($row($j2)['state'] === 'pending', 'Other instance untouched');
check($b->claim($i2)['id'] === $j2, 'Each instance claims only its own jobs');

// --- SKIP LOCKED: una riga bloccata da un'altra connessione viene saltata senza attesa ---
$i3 = $instance(); $k1 = $insert($i3); $k2 = $insert($i3);
$lock = $db->beginTransaction();
$db->createCommand('SELECT id FROM btcpay_provisioning_jobs WHERE id=:id FOR UPDATE', [':id' => $k1])->queryAll();
$worker->createCommand('SET SESSION innodb_lock_wait_timeout = 1')->execute();
$started = microtime(true);
$job = $a->claim($i3);
$elapsed = microtime(true) - $started;
$lock->rollBack();
check($job['id'] === $k2 && $elapsed < 0.9 && $row($k1)['state'] === 'pending', 'Locked job skipped without waiting');

// --- Presa concorrente da due processi con connessioni worker proprie ---
$i4 = $instance(); $only = $insert($i4);
$children = [];
foreach ([$uuid(), $uuid()] as $owner) {
    $process = proc_open([PHP_BINARY, __FILE__, '--claim-child', $i4, $owner], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Concurrency fixture unavailable.');
    fwrite($pipes[0], $password . "\n"); fflush($pipes[0]);
    stream_set_timeout($pipes[1], 10);
    if (fgets($pipes[1]) !== "ready\n") throw new RuntimeException('Concurrency fixture did not initialize.');
    $children[] = [$process, $pipes];
}
foreach ($children as [$process, $pipes]) { fwrite($pipes[0], 'x'); fclose($pipes[0]); }
$results = [];
foreach ($children as [$process, $pipes]) { $results[] = trim((string) fgets($pipes[1])); fclose($pipes[1]); $results[] = 'exit' . proc_close($process); }
sort($results);
check($results === [(string) $only, 'exit0', 'exit0', 'none'] && (int) $row($only)['attempts'] === 1, 'Concurrent claims: exactly one worker gets the job');

// --- Checkpoint e esiti: CAS su owner, versione, stato e lease ---
$i5 = $instance(); $c = $insert($i5);
$v = $a->claim($i5)['version'];
check($a->checkpoint($c, $v + 1, 'create_user') === null && $b->checkpoint($c, $v, 'create_user') === null && $row($c)['state'] === 'running', 'Checkpoint refused with wrong version or other owner');
$v = $a->checkpoint($c, $v, 'create_user');
$r = $row($c);
check($v === 2 && $r['state'] === 'in_flight' && $r['in_flight_op'] === 'create_user' && $r['in_flight_owner'] === $ownerA && (int) $r['in_flight_started_at'] === $t, 'Checkpoint recorded before the remote call');
check($a->checkpoint($c, $v, 'create_user') === null, 'No second checkpoint while in flight');
$before = $row($c);
check($a->retryLater($c, $v, 'rate_limited', $t + 30) === null && $row($c) === $before, 'In-flight job cannot be scheduled for retry without remote proof');
$v = $a->remoteRejected($c, $v, 429);
$r = $row($c);
check($v === 3 && $r['state'] === 'running' && $r['in_flight_op'] === null && $r['in_flight_owner'] === null, 'HTTP 429 proves non-execution: back to running, markers cleared');
$v = $a->retryLater($c, $v, 'rate_limited', $t + 30);
$r = $row($c);
check($v === 4 && $r['state'] === 'retry_wait' && $r['lease_owner'] === null && (int) $r['next_attempt_at'] === $t + 30 && $r['last_error_code'] === 'rate_limited', 'Retry scheduled at the given time, lease released');
check($b->claim($i5) === null, 'Retry not claimed before it is due');
$t += 31;
$job = $b->claim($i5);
check($job['id'] === $c && $job['attempts'] === 2 && $row($c)['lease_owner'] === $ownerB, 'Due retry claimed again with a new attempt');
$v = $b->retryLater($c, $job['version'], 'retry_after_long', $t + 90000, true);
check($row($c)['state'] === 'deferred' && $b->claim($i5) === null, 'Deferred job not claimed before its time');
$t += 90001;
$before = $row($c);
check($a->claim($i5) === null && $b->claim($i5) === null && $row($c) === $before, 'Due deferred job never claimed automatically (admin only)');
$failing = $insert($i5);
$job = $a->claim($i5);
check($job['id'] === $failing && $a->fail($failing, $job['version'], 'remote_rejected') === $job['version'] + 1 && $row($failing)['state'] === 'failed' && $row($failing)['lease_owner'] === null, 'Definitive failure recorded, lease released');
check($a->claim($i5) === null, 'Failed job never claimed again');

// --- Esito tardivo: lease scaduta senza checkpoint, ripresa da un altro worker ---
$i6 = $instance(); $late = $insert($i6);
$va = $a->claim($i6)['version'];
$t += 61;
check($a->succeed($late, $va) === null && $row($late)['state'] === 'running', 'Outcome after lease expiry rejected');
$job = $b->claim($i6);
check($job['id'] === $late && $job['attempts'] === 2 && $row($late)['lease_owner'] === $ownerB, 'Expired running job without checkpoint re-claimed by another worker');
check($a->checkpoint($late, $va, 'create_user') === null && $a->succeed($late, $job['version']) === null && $a->fail($late, $job['version'], 'late') === null, 'Old owner can no longer checkpoint or record outcomes');
check($b->succeed($late, $job['version']) === $job['version'] + 1 && $row($late)['state'] === 'done' && $row($late)['lease_owner'] === null, 'Current owner records success');
check($b->succeed($late, $job['version'] + 1) === null, 'Done job cannot be completed twice');

// --- In volo scaduto: solo riconciliazione, mai ripresa ---
$i7 = $instance(); $flight = $insert($i7);
$va = $a->claim($i7)['version'];
$va = $a->checkpoint($flight, $va, 'create_store');
$t += 61;
check($b->claim($i7) === null, 'Expired in-flight job is not handed to another worker');
$r = $row($flight);
check($r['state'] === 'reconcile_required' && $r['last_error_code'] === 'in_flight_expired' && $r['lease_owner'] === null && $r['in_flight_op'] === 'create_store' && $r['in_flight_owner'] === $ownerA, 'Expired in-flight job moved to reconciliation, markers kept');
check($a->succeed($flight, $va) === null && $a->remoteRejected($flight, $va, 429) === null && $row($flight)['state'] === 'reconcile_required', 'Late outcome of the expired in-flight owner rejected');
$t += 3600;
check($a->claim($i7) === null && $b->claim($i7) === null && $row($flight)['state'] === 'reconcile_required', 'Reconciliation job never resumed automatically');
$i8 = $instance();
$marked = [
    'running' => $insert($i8, ['state' => 'running', 'lease_owner' => $ownerA, 'lease_until' => $t - 1, 'in_flight_op' => 'create_api_key', 'in_flight_started_at' => $t - 5, 'in_flight_owner' => $ownerA]),
    'pending' => $insert($i8, ['in_flight_op' => 'create_pos_app', 'in_flight_started_at' => $t - 5, 'in_flight_owner' => $ownerA]),
    'retry_wait' => $insert($i8, ['state' => 'retry_wait', 'next_attempt_at' => $t + 1000, 'in_flight_op' => 'revoke_api_key', 'in_flight_started_at' => $t - 5, 'in_flight_owner' => $ownerA]),
];
// RV2: marker con lease ancora valida -> owner attivo preservato; solo dopo la scadenza riconciliazione.
$i11 = $instance();
$leased = [
    'pending' => $insert($i11, ['lease_owner' => $ownerA, 'lease_until' => $t + 30, 'in_flight_op' => 'create_store', 'in_flight_started_at' => $t, 'in_flight_owner' => $ownerA]),
    'retry_wait' => $insert($i11, ['state' => 'retry_wait', 'lease_owner' => $ownerA, 'lease_until' => $t + 30, 'in_flight_op' => 'create_pos_app', 'in_flight_started_at' => $t, 'in_flight_owner' => $ownerA]),
];
$snapshot = array_map($row, $leased);
check($b->claim($i11) === null && array_map($row, $leased) === $snapshot, 'Marker with a live lease left untouched, owner preserved');
$t += 31;
check($b->claim($i11) === null, 'Expired marked jobs not handed to another worker');
foreach ($leased as $state => $id) check($row($id)['state'] === 'reconcile_required' && $row($id)['lease_owner'] === null && $row($id)['in_flight_owner'] === $ownerA, 'Expired marker in ' . $state . ' sent to reconciliation');
check($a->claim($i11) === null && $b->claim($i11) === null, 'No new claim after reconciliation');
check($b->claim($i8) === null, 'Jobs carrying an in-flight marker are never claimed');
foreach ($marked as $state => $id) check($row($id)['state'] === 'reconcile_required', 'In-flight marker with state ' . $state . ' sent to reconciliation');
$i9 = $instance(); $live = $insert($i9);
$va = $a->claim($i9)['version']; $va = $a->checkpoint($live, $va, 'create_user');
check($b->claim($i9) === null && $row($live)['state'] === 'in_flight', 'In-flight job with a valid lease left alone');
check($a->reconcile($live, $va, 'timeout') === $va + 1 && $row($live)['state'] === 'reconcile_required' && $row($live)['in_flight_op'] === 'create_user', 'Owner reports ambiguous outcome as reconciliation');

// --- Tentativi esauriti, esito senza chiamata remota ---
$i10 = $instance(); $spent = $insert($i10, ['attempts' => ProvisioningJobs::MAX_ATTEMPTS]); $fresh = $insert($i10);
$job = $a->claim($i10);
check($job['id'] === $fresh && $row($spent)['state'] === 'failed' && $row($spent)['last_error_code'] === 'attempts_exhausted', 'Exhausted job failed and skipped in the same claim');
check($a->succeed($fresh, $job['version']) === $job['version'] + 1 && $row($fresh)['in_flight_op'] === null, 'Job completed without remote call');

// --- Nessun segreto: valori restituiti e sorgente ---
$source = file_get_contents($root . '/components/btcpay/provisioning/ProvisioningJobs.php');
check(preg_match('/password|secret|token|kek|authorization|Yii::|error_log|file_put_contents|echo |print/i', $source) === 0, 'Component holds no credential handling or logging');
$methods = array_map(fn($m) => $m->name, (new ReflectionClass(ProvisioningJobs::class))->getMethods(ReflectionMethod::IS_PUBLIC));
sort($methods);
check($methods === ['__construct', 'checkpoint', 'claim', 'fail', 'reconcile', 'remoteRejected', 'retryLater', 'succeed'], 'Public API limited to queue transitions');

echo "PASS $checks BTCPay provisioning queue checks\n";
