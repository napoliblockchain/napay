<?php
// SIP-022 B1 W4b: lavoro merchant_account accodato dall'attivazione merchant nella stessa transazione; utente web ristretto reale, DB usa e getta.
// Il container web riceve BTCPAY_PROVISION_INSTANCE dal runner; i casi "funzione spenta/malformata" usano putenv solo in questo processo.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';

use app\components\btcpay\provisioning\ProvisioningOutbox as Outbox;
use app\components\identity\AccountEligibility as Policy;
use app\components\identity\MerchantActivation as Activation;
use app\models\Merchants;
use app\models\Users;

$app = new yii\web\Application(require $root . '/config/web.php');
$app->user->enableSession = false; $app->user->enableAutoLogin = false;
$db = $app->db;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);
$instance = (string) getenv('BTCPAY_PROVISION_INSTANCE');
if (preg_match('/^[0-9a-f]{64}$/D', $instance) !== 1) exit(1);
function data(): array { return ['description' => 'W4b PG', 'email' => 'company@example.invalid', 'vatNumber' => '12345678903', 'phone' => '081 1234567', 'mobile' => '',
    'addressStreet' => 'Via Test', 'addressNumberHouse' => '1', 'addressCity' => 'Napoli', 'addressZip' => '80100', 'addressProvince' => 'NA', 'addressCountry' => 'IT']; }
function activate(int $owner, ?int $actor = null): ?int {
    Yii::$app->user->setIdentity(Users::findOne($actor ?? $owner));
    return Activation::activate($actor ?? $owner, $owner, new Merchants(data()));
}
if (($argv[1] ?? '') === '--race') {
    echo "ready\n"; fgets(STDIN);
    try { echo activate((int) $argv[2]) . "\n"; exit(0); } catch (Throwable $e) { exit(11); }
}
$checks = 0;
function check($ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); ++$checks; echo "PASS: $label\n"; }
function scalar(string $sql, array $p = []) { return Yii::$app->db->createCommand($sql, $p)->queryScalar(); }
function refuses(callable $work, string $class, string $message, string $label): void {
    $got = null;
    try { $work(); } catch (Throwable $e) { $got = $e; }
    check($got instanceof $class && $got->getMessage() === $message, $label);
}
function user(string $name, ?int $type = 1): int {
    $db = Yii::$app->db;
    $row = $db->createCommand("SELECT * FROM users WHERE username='runtime-user'")->queryOne();
    unset($row['id'], $row['email_normalized']);
    $row['username'] = $row['email'] = $name . '@example.invalid'; $row['oauth_uid'] = $name;
    $row['privilege_id'] = (int) scalar('SELECT id FROM privileges WHERE level=0 ORDER BY id LIMIT 1');
    $row['is_active'] = 1;
    $db->createCommand()->insert('users', $row)->execute(); $id = (int) $db->lastInsertID;
    if ($type !== null) $db->createCommand()->insert('member_profiles', ['user_id' => $id, 'is_corporate' => $type, 'denomination' => $type === 1 ? 'PG ' . $name : null])->execute();
    return $id;
}
$jobs = static function (int $merchant) use ($db): array {
    $rows = $db->createCommand("SELECT kind, instance, merchant_id, entity_id, generation, state, attempts, next_attempt_at FROM btcpay_provisioning_jobs WHERE kind='merchant_account' AND entity_id=:e", [':e' => $merchant])->queryAll();
    $text = ['instance' => 1, 'kind' => 1, 'state' => 1];
    return array_map(static fn(array $r): array => array_intersect_key($r, $text) + array_map('intval', array_diff_key($r, $text)), $rows);
};
$expected = static fn(int $merchant): array => [['kind' => 'merchant_account', 'instance' => $instance, 'state' => 'pending', 'merchant_id' => $merchant,
    'entity_id' => $merchant, 'generation' => 1, 'attempts' => 0, 'next_attempt_at' => 0]];
$withInstance = static function (string $value, callable $work) use ($instance) {
    putenv('BTCPAY_PROVISION_INSTANCE=' . $value);
    try { return $work(); } finally { putenv('BTCPAY_PROVISION_INSTANCE=' . $instance); }
};
$role = static fn(int $user): int => (int) scalar('SELECT p.level FROM users u JOIN privileges p ON p.id=u.privilege_id WHERE u.id=:id', [':id' => $user]);
$admin = (int) scalar("SELECT id FROM users WHERE username='runtime-admin'");

// --- attivazione dal gestore PG: merchant, ruolo e lavoro nella stessa transazione
$pg = user('w4b-pg');
$merchant = activate($pg);
check($merchant !== null && Policy::merchantForManager($pg) === $merchant, 'PG activated as unique merchant manager');
check($jobs($merchant) === $expected($merchant), 'Activation enqueues merchant_account generation 1, entity = merchant, due now');
check(activate($pg) === $merchant && count($jobs($merchant)) === 1, 'Replayed activation: same merchant, still one job');
$adminPg = user('w4b-admin-pg');
$byAdmin = activate($adminPg, $admin);
check($byAdmin !== null && $jobs($byAdmin) === $expected($byAdmin), 'Admin activation for a PG enqueues the same job');

// --- funzione spenta: comportamento invariato; il replay successivo a funzione attiva recupera il lavoro mancante
$off = user('w4b-off');
$offMerchant = $withInstance('', fn() => activate($off));
check($offMerchant !== null && $role($off) === 30 && $jobs($offMerchant) === [], 'Feature off: merchant and role committed, no job');
check(activate($off) === $offMerchant && $jobs($offMerchant) === [], 'Replay after enabling the feature: same merchant, still no job (no implicit backfill)');

// --- configurazione malformata: rollback di merchant, ruolo e lavoro
$total = (int) scalar('SELECT COUNT(*) FROM btcpay_provisioning_jobs');
foreach (['xyz', strtoupper($instance), substr($instance, 1)] as $n => $bad) {
    $owner = user('w4b-bad-' . $n);
    refuses(fn() => $withInstance($bad, fn() => activate($owner)), yii\base\InvalidConfigException::class, 'invalid_provision_instance', 'Malformed instance refused (' . strlen($bad) . ' chars)');
    check(Policy::canActivateMerchant($owner) && $role($owner) === 0 && (int) scalar('SELECT COUNT(*) FROM merchants WHERE user_id=:u', [':u' => $owner]) === 0,
        'Malformed instance rolls back merchant and role together');
}
check((int) scalar('SELECT COUNT(*) FROM btcpay_provisioning_jobs') === $total, 'No job left by refused activations');

// --- helper diretto: transazione, ID, scope, connessione
refuses(fn() => Outbox::enqueueMerchant($db, $merchant, $pg), LogicException::class, 'transaction_required', 'Outside a transaction: refused');
$tx = $db->beginTransaction();
try {
    refuses(fn() => Outbox::enqueueMerchant($db, 0, $pg), InvalidArgumentException::class, 'invalid_id', 'Merchant id must be positive');
    refuses(fn() => Outbox::enqueueMerchant($db, $merchant, 0), InvalidArgumentException::class, 'invalid_id', 'Actor id must be positive');
    refuses(fn() => Outbox::enqueueMerchant($db, 999999999, $pg), InvalidArgumentException::class, 'scope_mismatch', 'Missing merchant refused');
    $unqualified = (int) scalar("SELECT id FROM merchants WHERE description='Runtime company other'");
    refuses(fn() => Outbox::enqueueMerchant($db, $unqualified, $admin), InvalidArgumentException::class, 'scope_mismatch', 'Merchant whose owner is not a qualified PG manager refused');
    check($jobs($unqualified) === [], 'No job for the unqualified merchant');
} finally { $tx->rollBack(); }
$db->createCommand()->update('users', ['is_active' => 0], ['id' => $pg])->execute();
$tx = $db->beginTransaction();
try { refuses(fn() => Outbox::enqueueMerchant($db, $merchant, $pg), InvalidArgumentException::class, 'scope_mismatch', 'Disabled manager: merchant out of scope'); }
finally { $tx->rollBack(); $db->createCommand()->update('users', ['is_active' => 1], ['id' => $pg])->execute(); }
$other = new yii\db\Connection(['dsn' => $db->dsn, 'username' => $db->username, 'password' => $db->password, 'charset' => 'utf8mb4']);
$tx = $other->beginTransaction();
try { refuses(fn() => Outbox::enqueueMerchant($other, $merchant, $pg), LogicException::class, 'connection_mismatch', 'Eligibility must be read on the same connection as the transaction'); }
finally { $tx->rollBack(); $other->close(); }

// --- SIP-022: merchant attivato prima del provisioning (o importato dalla v1): l'admin lo collega con il primo lavoro
$early = user('w4b-early');
$earlyMerchant = $withInstance('', fn() => activate($early));
check($earlyMerchant !== null && $jobs($earlyMerchant) === [], 'Merchant activated while provisioning was off: no job');
$tx = $db->beginTransaction();
try { $linked = Outbox::retry($db, 'merchant_account', $earlyMerchant, $admin); $tx->commit(); } catch (Throwable $e) { $tx->rollBack(); throw $e; }
check($linked === true && $jobs($earlyMerchant) === $expected($earlyMerchant), 'Admin link: first merchant_account job, same as an activation');
$tx = $db->beginTransaction();
try { $again = Outbox::retry($db, 'merchant_account', $earlyMerchant, $admin); $tx->commit(); } catch (Throwable $e) { $tx->rollBack(); throw $e; }
$unqualified = (int) scalar("SELECT id FROM merchants WHERE description='Runtime company other'");
$tx = $db->beginTransaction();
try { $refused = Outbox::retry($db, 'merchant_account', $unqualified, $admin); } finally { $tx->rollBack(); }
check($again === false && count($jobs($earlyMerchant)) === 1 && $refused === false && $jobs($unqualified) === [],
    'Link again while pending: nothing; merchant without a qualified PG manager: refused');

// --- trace COMPLETA senza Connection né password: chiamate dirette, nessuna closure del test nello stack
ini_set('zend.exception_ignore_args', '0');
$secret = (string) $db->password;
check(strlen($secret) >= 16, 'Canary: web DB password available to compare');
$clean = static function (Throwable $e) use ($secret): bool {
    $seen = 0;
    for (; $e !== null; $e = $e->getPrevious()) {
        foreach ($e->getTrace() as $frame) foreach ($frame['args'] ?? [] as $arg) { ++$seen; if ($arg instanceof yii\db\Connection) return false; }
        if (str_contains(print_r($e->getTrace(), true), $secret) || str_contains($e->getMessage(), $secret)) return false;
    }
    return $seen > 0;
};
$traces = [];
$canary = user('w4b-canary');
Yii::$app->user->setIdentity(Users::findOne($canary));
putenv('BTCPAY_PROVISION_INSTANCE=not-a-digest');
try { Activation::activate($canary, $canary, new Merchants(data())); } catch (yii\base\InvalidConfigException $e) { $traces['activation-config'] = $e; }
putenv('BTCPAY_PROVISION_INSTANCE=' . $instance);
$tx = $db->beginTransaction();
try { Outbox::enqueueMerchant($db, 999999999, $pg); } catch (InvalidArgumentException $e) { $traces['scope'] = $e; }
// Merchant qualificato senza lavoro (attivato a funzione spenta): enqueueMerchant diretto nella TX, l'attore inesistente dà FK 1452.
try { Outbox::enqueueMerchant($db, $offMerchant, 999999998); } catch (yii\db\IntegrityException $e) { $traces['integrity'] = (int) ($e->errorInfo[1] ?? 0) === 1452 ? $e : null; }
$tx->rollBack();
check(array_keys(array_filter($traces)) === ['activation-config', 'scope', 'integrity'], 'Canary errors raised (activation config, scope, SQL)');
foreach ($traces as $case => $e) check($clean($e), "Full structured trace without Connection or password: $case");
check(Policy::canActivateMerchant($canary), 'Canary activation rolled back');

// --- attivazioni concorrenti dello stesso PG: un merchant, un lavoro
$race = user('w4b-race'); $workers = [];
foreach ([1, 2] as $_) {
    $p = proc_open([PHP_BINARY, __FILE__, '--race', (string) $race], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes);
    if (!is_resource($p)) throw new RuntimeException('Worker unavailable');
    stream_set_timeout($pipes[1], 30);
    if (fgets($pipes[1]) !== "ready\n") throw new RuntimeException('Worker not ready');
    $workers[] = [$p, $pipes];
}
foreach ($workers as [$p, $pipes]) { fwrite($pipes[0], "go\n"); fclose($pipes[0]); }
$results = [];
foreach ($workers as [$p, $pipes]) { $results[] = trim(stream_get_contents($pipes[1])); fclose($pipes[1]); check(proc_close($p) === 0, 'Concurrent activation worker completed'); }
check($results[0] !== '' && $results[0] === $results[1] && $jobs((int) $results[0]) === $expected((int) $results[0]), 'Concurrent activations: one merchant, one job');

// --- HTTP reale: attivazione dal portale con l'istanza del container web
$jar = tempnam(sys_get_temp_dir(), 'napay-w4b-');
function http(string $path, ?array $form = null): array {
    global $jar;
    $c = curl_init('http://127.0.0.1' . $path);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false]);
    if ($form !== null) curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form)]);
    $raw = curl_exec($c); if ($raw === false) throw new RuntimeException('HTTP transport failed.');
    $size = curl_getinfo($c, CURLINFO_HEADER_SIZE); $r = ['status' => curl_getinfo($c, CURLINFO_RESPONSE_CODE), 'body' => substr($raw, $size)]; curl_close($c); return $r;
}
function csrf(array $page): string { if (!preg_match('/<meta name="csrf-token" content="([^"]+)"/', $page['body'], $m)) throw new RuntimeException('CSRF missing.'); return html_entity_decode($m[1]); }
$web = user('w4b-http');
$app->cache->flush();
$p = http('/site/login');
check(http('/site/login', ['_csrf' => csrf($p), 'LoginForm' => ['email' => 'w4b-http@example.invalid', 'password' => 'fixture-password-only']])['status'] === 302, 'PG logged in');
$p = http('/merchants/activate');
check(http('/merchants/activate', ['_csrf' => csrf($p), 'Merchants' => data()])['status'] === 302, 'HTTP activation succeeds');
$httpMerchant = Policy::merchantForManager($web);
check($httpMerchant !== null && $jobs($httpMerchant) === $expected($httpMerchant), 'HTTP activation enqueues merchant_account with the web instance');
@unlink($jar);
echo "PASS $checks BTCPay provisioning outbox activation checks\n";
