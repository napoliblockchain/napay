<?php
// SIP-022 B1 W4a: lavori store_link/pos_link accodati dal web nella transazione della risorsa; utente web ristretto reale, DB usa e getta.
// Il container web riceve BTCPAY_PROVISION_INSTANCE dal runner; i casi "funzione spenta/malformata" usano putenv solo in questo processo.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';

use app\components\btcpay\provisioning\ProvisioningOutbox as Outbox;
use app\components\pos\management\ManagementException as Failure;
use app\components\pos\management\PosManagement;
use app\components\pos\management\Scope;

$app = new yii\web\Application(require $root . '/config/web.php');
$app->user->enableSession = false;
$db = $app->db;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);
$instance = (string) getenv('BTCPAY_PROVISION_INSTANCE');
if (preg_match('/^[0-9a-f]{64}$/D', $instance) !== 1) exit(1);
$checks = 0; $jar = null;
function check($condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException($label); ++$checks; echo 'PASS: ' . $label . "\n"; }
function scalar(string $sql, array $p = []) { global $db; return $db->createCommand($sql, $p)->queryScalar(); }
function refuses(callable $work, string $class, string $message, string $label): void {
    $got = null;
    try { $work(); } catch (Throwable $e) { $got = $e; }
    check($got instanceof $class && $got->getMessage() === $message, $label);
}
function web(string $path, ?array $form = null): array {
    global $jar;
    $curl = curl_init('http://127.0.0.1' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20,
        CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($form !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form)]);
    $raw = curl_exec($curl);
    if ($raw === false) throw new RuntimeException('HTTP transport failed.');
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $r = ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    curl_close($curl);
    return $r;
}
function csrf(array $page): string {
    if (!preg_match('/<meta name="csrf-token" content="([^"]+)"/', $page['body'], $m)) throw new RuntimeException('CSRF unavailable.');
    return html_entity_decode($m[1]);
}
$rows = static fn(string $kind, int $entity): array => $db->createCommand('SELECT kind, instance, merchant_id, entity_id, generation, state, attempts, next_attempt_at FROM btcpay_provisioning_jobs WHERE kind=:k AND entity_id=:e',
    [':k' => $kind, ':e' => $entity])->queryAll();
$jobs = static fn(string $kind, int $entity): array => array_map(static fn(array $r): array => array_map(static fn($v) => is_numeric($v) && !preg_match('/^[0-9a-f]{64}$/', $v) ? (int) $v : $v, $r), $rows($kind, $entity));
$total = static fn(): int => (int) scalar('SELECT COUNT(*) FROM btcpay_provisioning_jobs');
$store = static function (int $merchantId, string $description) use ($db): int {
    $db->createCommand()->insert('stores', ['merchant_id' => $merchantId, 'description' => $description, 'email' => 'fixture@example.invalid', 'phone' => '', 'mobile' => '',
        'addressStreet' => '', 'addressNumberHouse' => '', 'addressCity' => '', 'addressZip' => '', 'addressProvince' => '', 'addressCountry' => ''])->execute();
    return (int) $db->lastInsertID;
};
/** Lavoro nella transazione del chiamante; $fail simula un errore successivo del chiamante (rollback congiunto). */
$inTx = static function (callable $work, bool $fail = false) use ($db) {
    $transaction = $db->beginTransaction();
    try { $result = $work(); if ($fail) throw new DomainException('caller_failed'); $transaction->commit(); return $result; }
    finally { if ($transaction->isActive) $transaction->rollBack(); }
};
$withInstance = static function (string $value, callable $work) use ($instance) {
    putenv('BTCPAY_PROVISION_INSTANCE=' . $value);
    try { return $work(); } finally { putenv('BTCPAY_PROVISION_INSTANCE=' . $instance); }
};

// --- fixture: gestore PG qualificato del merchant A, merchant B di un altro titolare
$managerA = (int) scalar("SELECT id FROM users WHERE username='runtime-merchant'");
$merchantA = (int) scalar("SELECT id FROM merchants WHERE description='Runtime company merchant'");
$merchantB = (int) scalar("SELECT id FROM merchants WHERE description='Runtime company other'");
$db->createCommand()->insert('member_profiles', ['user_id' => $managerA, 'is_corporate' => 1, 'denomination' => 'W4a PG A'])->execute();

// --- utente web realmente ristretto sulla coda
$denied = static function (string $sql) use ($db): bool {
    try { $db->createCommand($sql)->execute(); } catch (yii\db\Exception $e) { return in_array((int) ($e->errorInfo[1] ?? 0), [1142, 1143], true); }
    return false;
};
check($denied("UPDATE btcpay_provisioning_jobs SET state='done' WHERE 1=0") && $denied('DELETE FROM btcpay_provisioning_jobs WHERE 1=0')
    && $denied("INSERT INTO btcpay_provisioning_jobs (kind, instance, merchant_id, entity_id, state) VALUES ('store_link', '$instance', 1, 1, 'done')")
    && $denied('SELECT lease_owner FROM btcpay_provisioning_jobs LIMIT 1') && $denied('SELECT ciphertext FROM btcpay_merchant_credentials LIMIT 1'),
    'Web user: no UPDATE/DELETE on jobs, no INSERT of state, no lease or credential reads');

// --- uso: transazione obbligatoria (anche a funzione spenta), ID validi, istanza
$s1 = $store($merchantA, 'W4a negozio senza transazione');
refuses(fn() => Outbox::enqueueStore($db, $s1, $merchantA, $managerA), LogicException::class, 'transaction_required', 'Outside a transaction: refused');
refuses(fn() => $withInstance('', fn() => Outbox::enqueueStore($db, $s1, $merchantA, $managerA)), LogicException::class, 'transaction_required', 'Outside a transaction: refused even with the feature off');
refuses(fn() => $inTx(fn() => Outbox::enqueueStore($db, $s1, $merchantA, 0)), InvalidArgumentException::class, 'invalid_id', 'Actor id must be positive');
refuses(fn() => $inTx(fn() => Outbox::enqueueTerminal($db, 0, $s1, $merchantA, $managerA)), InvalidArgumentException::class, 'invalid_id', 'Terminal id must be positive');
check($jobs('store_link', $s1) === [], 'No job after refused calls');

// --- funzione spenta: nessun lavoro, comportamento invariato
$before = $total();
$off = $withInstance('', fn() => $inTx(function () use ($db, $store, $merchantA, $managerA) {
    $id = $store($merchantA, 'W4a negozio funzione spenta');
    return [$id, Outbox::enqueueStore($db, $id, $merchantA, $managerA)];
}));
check($off[1] === false && $total() === $before && (int) scalar('SELECT COUNT(*) FROM stores WHERE id=:id', [':id' => $off[0]]) === 1, 'Empty instance: store committed, no job');

// --- istanza malformata: eccezione, rollback congiunto della risorsa
foreach (['xyz', strtoupper($instance), $instance . "\n", substr($instance, 1)] as $bad) {
    $id = null;
    refuses(fn() => $withInstance($bad, fn() => $inTx(function () use ($db, $store, $merchantA, $managerA, &$id) {
        $id = $store($merchantA, 'W4a negozio istanza malformata');
        return Outbox::enqueueStore($db, $id, $merchantA, $managerA);
    })), yii\base\InvalidConfigException::class, 'invalid_provision_instance', 'Malformed instance refused (' . strlen($bad) . ' chars)');
    check((int) scalar('SELECT COUNT(*) FROM stores WHERE id=:id', [':id' => $id]) === 0, 'Store rolled back with the malformed instance');
}

// --- accodamento, replay idempotente, scope
$s2 = $inTx(function () use ($db, $store, $merchantA, $managerA) {
    $id = $store($merchantA, 'W4a negozio accodato');
    check(Outbox::enqueueStore($db, $id, $merchantA, $managerA) === true, 'Store job enqueued');
    return $id;
});
check($jobs('store_link', $s2) === [['kind' => 'store_link', 'instance' => $instance, 'merchant_id' => $merchantA, 'entity_id' => $s2, 'generation' => 1,
    'state' => 'pending', 'attempts' => 0, 'next_attempt_at' => 0]], 'Job: store_link generation 1 pending, due now, for the store merchant');
check((int) scalar('SELECT COUNT(*) FROM btcpay_provisioning_jobs WHERE entity_id=:e', [':e' => $s2]) === 1, 'Exactly one job for the new store');
$replay = $inTx(function () use ($db, $s2, $merchantA, $managerA) {
    $db->createCommand()->update('stores', ['description' => 'W4a negozio replay'], ['id' => $s2])->execute();
    return Outbox::enqueueStore($db, $s2, $merchantA, $managerA);
});
check($replay === true && count($jobs('store_link', $s2)) === 1 && scalar('SELECT description FROM stores WHERE id=:id', [':id' => $s2]) === 'W4a negozio replay',
    'Replay: duplicate 1062 absorbed, no second job, the caller transaction still commits');
refuses(fn() => $inTx(fn() => Outbox::enqueueStore($db, $s2, $merchantB, $managerA)), InvalidArgumentException::class, 'scope_mismatch', 'Store of another merchant refused');
refuses(fn() => $inTx(fn() => Outbox::enqueueStore($db, 999999999, $merchantA, $managerA)), InvalidArgumentException::class, 'scope_mismatch', 'Missing store refused');
$before = $total();
refuses(fn() => $inTx(function () use ($db, $store, $merchantA, $managerA) {
    $id = $store($merchantA, 'W4a negozio annullato');
    Outbox::enqueueStore($db, $id, $merchantA, $managerA);
}, true), DomainException::class, 'caller_failed', 'Caller fails after enqueue');
check($total() === $before && (int) scalar("SELECT COUNT(*) FROM stores WHERE description='W4a negozio annullato'") === 0, 'Joint rollback: neither store nor job remain');
$fk = null;
try { $inTx(fn() => Outbox::enqueueStore($db, $s1, $merchantA, 999999999)); } catch (yii\db\IntegrityException $e) { $fk = (int) ($e->errorInfo[1] ?? 0); }
check($fk === 1452, 'Non-duplicate integrity error (unknown actor) is not swallowed');
check($jobs('store_link', $s1) === [], 'No job for the unknown actor');

// --- terminali: via PosManagement (stessa transazione), scope terminale/negozio/merchant
$app->user->setIdentity(app\models\Users::findOne($managerA));
$scope = Scope::current();
$publicId = PosManagement::createTerminal($scope, $s2, 'W4a cassa');
$terminal = (int) scalar('SELECT id FROM pos_terminals WHERE public_id=:p', [':p' => $publicId]);
check($jobs('pos_link', $terminal) === [['kind' => 'pos_link', 'instance' => $instance, 'merchant_id' => $merchantA, 'entity_id' => $terminal, 'generation' => 1,
    'state' => 'pending', 'attempts' => 0, 'next_attempt_at' => 0]], 'Terminal creation enqueues pos_link in the same transaction');
$off = $withInstance('', fn() => PosManagement::createTerminal($scope, $s2, 'W4a cassa spenta'));
$offId = (int) scalar('SELECT id FROM pos_terminals WHERE public_id=:p', [':p' => $off]);
check($offId > 0 && $jobs('pos_link', $offId) === [], 'Feature off: terminal created, no job');
$names = (int) scalar("SELECT COUNT(*) FROM pos_terminals WHERE name='W4a cassa malformata'");
$code = null;
try { $withInstance('bad', fn() => PosManagement::createTerminal($scope, $s2, 'W4a cassa malformata')); } catch (Failure $e) { $code = $e->getMessage(); }
check($code !== null && (int) scalar("SELECT COUNT(*) FROM pos_terminals WHERE name='W4a cassa malformata'") === $names, 'Malformed instance: terminal creation fails and rolls back');
$otherStore = $store($merchantA, 'W4a altro negozio');
refuses(fn() => $inTx(fn() => Outbox::enqueueTerminal($db, $terminal, $otherStore, $merchantA, $managerA)), InvalidArgumentException::class, 'scope_mismatch', 'Terminal with another store refused');
refuses(fn() => $inTx(fn() => Outbox::enqueueTerminal($db, $terminal, $s2, $merchantB, $managerA)), InvalidArgumentException::class, 'scope_mismatch', 'Terminal with another merchant refused');
check($inTx(fn() => Outbox::enqueueTerminal($db, $terminal, $s2, $merchantA, $managerA)) === true && count($jobs('pos_link', $terminal)) === 1, 'Terminal replay: still one job');

// --- HTTP reale: creazione negozio dal gestore accoda il lavoro (istanza dal container web)
$jar = tempnam(sys_get_temp_dir(), 'napay-w4a-');
$app->cache->flush();
$page = web('/site/login');
check(web('/site/login', ['_csrf' => csrf($page), 'LoginForm' => ['email' => 'merchant@example.invalid', 'password' => 'fixture-password-only']])['status'] === 302, 'Manager logged in');
$page = web('/stores/create');
$created = web('/stores/create', ['_csrf' => csrf($page), 'Stores' => ['description' => 'W4a negozio via HTTP']]);
$httpStore = (int) scalar("SELECT id FROM stores WHERE description='W4a negozio via HTTP'");
// requested_by non è leggibile dal web (matrice WEB_READ): qui si verificano scope e unicità.
$httpJobs = $jobs('store_link', $httpStore);
check($created['status'] === 302 && $httpStore > 0 && count($httpJobs) === 1 && $httpJobs[0]['merchant_id'] === $merchantA && $httpJobs[0]['instance'] === $instance,
    'HTTP store creation enqueues store_link for the manager merchant with the web instance');
@unlink($jar);

// --- sorgente: nessuna rete, credenziale o scrittura nascosta
$source = implode('', array_map(static fn($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
    token_get_all(file_get_contents($root . '/components/btcpay/provisioning/ProvisioningOutbox.php'))));
check(!preg_match('/curl_|file_get_contents|fsockopen|http_|BTCPAY_URL|api[_-]?key|password|IGNORE|ON DUPLICATE|UPDATE |DELETE /i', $source), 'Outbox source: no HTTP, credentials, UPDATE or INSERT IGNORE');
echo "PASS $checks BTCPay provisioning outbox checks\n";
