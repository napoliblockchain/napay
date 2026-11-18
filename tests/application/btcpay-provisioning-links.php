<?php
// SIP-022 B1 W3c: esecutore store_link/pos_link su DB usa-e-getta con l'utente worker ristretto; HTTP W2 simulato (nessuna rete).
// stdin: password del worker (prima riga). Ogni scenario usa un'istanza diversa (account, mapping e lavori separati).
if (PHP_SAPI !== 'cli') exit(1);
ini_set('zend.exception_ignore_args', '0');
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
use app\components\btcpay\provisioning\{ProvisioningHttpException as Http, ProvisioningJobs, ProvisioningLinkWorker, ProvisioningSettingsWorker, ProvisioningStore};
use app\components\btcpay\StoreSettingsPayload;
use app\components\btcpay\provisioning\steps\{PosLinkStep, StoreArchiveStep, StoreLinkStep, StoreSettingsStep, StoreWalletStep, StoreWebhookStep};

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

// --- fixture (admin): merchant A del titolare PG qualificato, merchant B, negozi e terminale
$merchant = (int) $admin->createCommand("SELECT id FROM merchants WHERE description='Runtime company merchant'")->queryScalar();
$other = (int) $admin->createCommand("SELECT id FROM merchants WHERE description='Runtime company other'")->queryScalar();
$owner = (int) $admin->createCommand('SELECT user_id FROM merchants WHERE id=:m', [':m' => $merchant])->queryScalar();
$admin->createCommand()->insert('member_profiles', ['user_id' => $owner, 'is_corporate' => 1, 'denomination' => 'W3c PG'])->execute();
$newStore = static function (int $merchantId, string $name) use ($admin): int {
    $admin->createCommand()->insert('stores', ['merchant_id' => $merchantId, 'description' => $name, 'email' => 'fixture@example.invalid', 'phone' => '', 'mobile' => '',
        'addressStreet' => '', 'addressNumberHouse' => '', 'addressCity' => '', 'addressZip' => '', 'addressProvince' => '', 'addressCountry' => ''])->execute();
    return (int) $admin->lastInsertID;
};
$storeA = $newStore($merchant, 'W3c negozio A'); $storeA2 = $newStore($merchant, 'W3c negozio A2'); $storeB = $newStore($other, 'W3c negozio B');
$terminal = new app\models\PosTerminals(['merchant_id' => $merchant, 'store_id' => $storeA, 'name' => 'W3c cassa']);
if (!$terminal->save()) exit(1);
$terminalA = (int) $terminal->id;
$terminal = new app\models\PosTerminals(['merchant_id' => $other, 'store_id' => $storeB, 'name' => 'W3c cassa B']);
if (!$terminal->save()) exit(1);
$terminalB = (int) $terminal->id;
$app->set('db', $worker);
check(app\components\identity\AccountEligibility::merchantForManager($owner) === $merchant, 'Fixture merchant qualified, read through the worker grants');

/** Client finto: coda di risposte/eccezioni/callable, registra ogni chiamata; mai dentro una transazione. */
final class FakeHttp
{
    public array $calls = []; public array $queue = [];
    public function key(): Closure { return function (string $m, string $p, ?array $b, string $k) { $this->calls[] = [$m, $p, $b, $k]; return $this->next(); }; }
    private function next(): array {
        if (Yii::$app->db->getTransaction() !== null) throw new RuntimeException('HTTP inside a database transaction');
        $reply = array_shift($this->queue) ?? throw new RuntimeException('Unexpected HTTP call');
        if ($reply instanceof Closure) $reply = $reply();
        if ($reply instanceof Throwable) throw $reply;
        return $reply;
    }
}
$r = static fn(int $status, $json = null, ?string $retry = null): array => ['status' => $status, 'retry_after' => $retry, 'json' => $json];
$t = time();
$clock = static function () use (&$t): int { return $t; };
$uuid = static function (): string { $b = random_bytes(16); $b[6] = chr(ord($b[6]) & 0x0f | 0x40); $b[8] = chr(ord($b[8]) & 0x3f | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4)); };
$keyring = ['k1' => random_bytes(32)];
$apiKey = 'k-merchant-' . bin2hex(random_bytes(12));
$enqueue = static function (string $instance, string $kind, int $entity, int $generation = 1, ?int $merchantId = null) use ($admin, $merchant, &$t): int {
    $admin->createCommand()->insert('btcpay_provisioning_jobs', ['kind' => $kind, 'instance' => $instance, 'merchant_id' => $merchantId ?? $merchant,
        'entity_id' => $entity, 'generation' => $generation, 'created_at' => $t, 'updated_at' => $t])->execute();
    return (int) $admin->lastInsertID;
};
/** Nuovo scenario: istanza, store, client finto, worker; $synced = account sincronizzato con chiave current. */
$scenario = static function (bool $synced = true) use ($worker, $clock, $uuid, $keyring, $merchant, $apiKey) {
    $instance = bin2hex(random_bytes(32));
    $http = new FakeHttp();
    $store = new ProvisioningStore($worker, $instance, $keyring, 'k1');
    $run = new ProvisioningLinkWorker($worker, new ProvisioningJobs($worker, $uuid(), 60, $clock), $store, new StoreLinkStep($http->key()), new PosLinkStep($http->key()), $instance, $clock);
    if ($synced) {
        $account = $store->updateAccount($store->ensureAccount($merchant), ['state' => 'user_created', 'remote_user_id' => 'user-w3c', 'key_generations_used' => 1]);
        $store->storeKey($account, 1, $apiKey);
        $store->verifyKey($account, 1, $store->key($account, 1)['lock_version']);
        $store->promoteKey($account, 1);
        $store->updateAccount($store->account($merchant), ['state' => 'synced']);
    }
    return [$instance, $http, $store, $run];
};
$syncStore = static function (ProvisioningStore $store, int $storeId, string $remote) use ($merchant): void {
    $link = $store->ensureStoreLink($store->account($merchant), $storeId);
    $store->updateStoreLink($link, ['state' => 'synced', 'remote_store_id' => $remote]);
};
$job = static fn(int $id): array => $admin->createCommand('SELECT state, next_attempt_at, in_flight_op, last_error_code FROM btcpay_provisioning_jobs WHERE id=:id', [':id' => $id])->queryOne();
$marker = static fn(ProvisioningStore $s, string $kind, int $entity): string => '[napay-' . ($kind === 'store_link' ? $s->storeLink($entity) : $s->posLink($entity))['marker'] . ']';

// --- percorso negozio
[$instance, $http, $store, $run] = $scenario();
check($run->runOnce() === false, 'No job: runOnce returns false');
$id = $enqueue($instance, 'store_link', $storeA);
$http->queue = [function () use (&$http, $r) { return $r(200, ['id' => 'st-1']); }];
check($run->runOnce() === true, 'Store job taken');
$link = $store->storeLink($storeA);
check($job($id)['state'] === 'done' && $link['state'] === 'synced' && $link['remote_store_id'] === 'st-1', 'Store link synced, job done');
check(count($http->calls) === 1 && $http->calls[0][0] === 'POST' && $http->calls[0][1] === '/api/v1/stores' && $http->calls[0][3] === $apiKey
    && $http->calls[0][2] === ['name' => 'Negozio ' . $storeA . ' [napay-' . $link['marker'] . ']'], 'One POST with the current merchant key and a generic name: local ID + marker');

// --- percorso POS (store già synced nella stessa istanza)
$id = $enqueue($instance, 'pos_link', $terminalA);
$http->queue = [$r(200, ['id' => 'app-1', 'appType' => 'PointOfSale'])];
$run->runOnce();
$pos = $store->posLink($terminalA);
check($job($id)['state'] === 'done' && $pos['state'] === 'synced' && $pos['remote_app_id'] === 'app-1' && $pos['store_link_id'] === $link['id'], 'POS link synced in the store link, job done');
check(end($http->calls)[1] === '/api/v1/stores/st-1/apps/pos' && end($http->calls)[2] === ['appName' => 'Cassa ' . $terminalA . ' [napay-' . $pos['marker'] . ']', 'defaultView' => 'Light', 'showCustomAmount' => true],
    'POS app created in the synced remote store with a generic name, ready for custom amounts (no currency without store settings)');

// --- dipendenze mancanti: retry_wait senza HTTP né nuovi mapping
[$instance, $http, $store, $run] = $scenario(false);
$id = $enqueue($instance, 'store_link', $storeA);
$run->runOnce();
check($job($id)['state'] === 'retry_wait' && $job($id)['last_error_code'] === 'account_not_ready' && $http->calls === [] && $store->account($merchant) === null && $store->storeLink($storeA) === null,
    'Account missing: retry_wait, no HTTP, no account or link created');
[$instance, $http, $store, $run] = $scenario();
$id = $enqueue($instance, 'pos_link', $terminalA);
$run->runOnce();
check($job($id)['state'] === 'retry_wait' && $job($id)['last_error_code'] === 'store_not_ready' && $http->calls === [] && $store->posLink($terminalA) === null, 'Store not synced: POS waits, no HTTP, no POS link');
$store->ensureStoreLink($store->account($merchant), $storeA);
$t += 3600;
$run->runOnce();
check($job($id)['last_error_code'] === 'store_not_ready' && $http->calls === [] && $store->storeLink($storeA)['state'] === 'pending', 'Pending store link stays pending, POS still waits');
$t -= 3600;

// --- scope
[$instance, $http, $store, $run] = $scenario();
$id = $enqueue($instance, 'store_link', $storeB);
$run->runOnce();
check($job($id)['state'] === 'failed' && $job($id)['last_error_code'] === 'scope_mismatch' && $http->calls === [] && $store->storeLink($storeB) === null, 'Store of another merchant: failed, no mapping, no HTTP');
$id = $enqueue($instance, 'pos_link', $terminalB);
$run->runOnce();
check($job($id)['state'] === 'failed' && $job($id)['last_error_code'] === 'scope_mismatch' && $http->calls === [] && $store->posLink($terminalB) === null, 'Terminal of another merchant: failed, no mapping, no HTTP');
$id = $enqueue($instance, 'pos_link', 999999999);
$run->runOnce();
check($job($id)['state'] === 'failed' && $job($id)['last_error_code'] === 'scope_mismatch' && $http->calls === [], 'Missing terminal: failed, no HTTP');
$id = $enqueue($instance, 'store_link', $storeA, 1, $other);
$run->runOnce();
check($job($id)['state'] === 'failed' && $job($id)['last_error_code'] === 'merchant_ineligible' && $store->storeLink($storeA) === null, 'Job for an unqualified merchant: failed before any mapping');
$admin->createCommand()->update('users', ['is_active' => 0], ['id' => $owner])->execute();
$id = $enqueue($instance, 'store_link', $storeA, 2);
$run->runOnce();
$admin->createCommand()->update('users', ['is_active' => 1], ['id' => $owner])->execute();
check($job($id)['state'] === 'failed' && $job($id)['last_error_code'] === 'merchant_ineligible' && $http->calls === [] && $store->storeLink($storeA) === null, 'Disabled owner before HTTP: failed, no mapping, no HTTP');

// --- disabilitazione / cambio di idoneità durante la rete: nessuna adozione, incertezza conservata
[$instance, $http, $store, $run] = $scenario();
$id = $enqueue($instance, 'store_link', $storeA);
$http->queue = [function () use ($admin, $owner, $r) { $admin->createCommand()->update('users', ['is_active' => 0], ['id' => $owner])->execute(); return $r(200, ['id' => 'st-late']); }];
$run->runOnce();
$admin->createCommand()->update('users', ['is_active' => 1], ['id' => $owner])->execute();
$link = $store->storeLink($storeA);
check($job($id)['state'] === 'reconcile_required' && $job($id)['in_flight_op'] === 'create_store' && $link['state'] === 'reconcile_required' && $link['remote_store_id'] === null,
    'Disabled during POST: ID not adopted, job and link in reconciliation with the marker kept');
[$instance, $http, $store, $run] = $scenario();
$syncStore($store, $storeA, 'st-2');
$id = $enqueue($instance, 'pos_link', $terminalA);
$http->queue = [function () use ($admin, $owner, $r) { $admin->createCommand()->update('member_profiles', ['is_corporate' => 0], ['user_id' => $owner])->execute(); return $r(200, ['id' => 'app-late', 'appType' => 'PointOfSale']); }];
$run->runOnce();
$admin->createCommand()->update('member_profiles', ['is_corporate' => 1], ['user_id' => $owner])->execute();
check($job($id)['state'] === 'reconcile_required' && $store->posLink($terminalA)['remote_app_id'] === null && $store->posLink($terminalA)['state'] === 'reconcile_required', 'Owner no longer PG during POST: app not adopted, reconciliation');

// --- 429: prova di mancata esecuzione, RetryPolicy, poi nuovo POST ammesso
[$instance, $http, $store, $run] = $scenario();
$id = $enqueue($instance, 'store_link', $storeA);
$http->queue = [$r(429, null, '120')];
$run->runOnce();
check($job($id)['state'] === 'retry_wait' && $job($id)['in_flight_op'] === null && (int) $job($id)['next_attempt_at'] >= $t + 120 && $store->storeLink($storeA)['state'] === 'pending', '429: retry_wait at Retry-After, markers cleared, link pending');
check($run->runOnce() === false && count($http->calls) === 1, 'Not retried before Retry-After');
$t += 130;
$http->queue = [$r(200, ['id' => 'st-3'])];
$run->runOnce();
check($job($id)['state'] === 'done' && count($http->calls) === 2 && $store->storeLink($storeA)['remote_store_id'] === 'st-3', 'After 429 the POST is retried (proven not executed) and completes');
$t -= 130;

// --- 401/403: link bloccato, account in revisione
[$instance, $http, $store, $run] = $scenario();
$id = $enqueue($instance, 'store_link', $storeA);
$http->queue = [$r(401)];
$run->runOnce();
check($job($id)['state'] === 'failed' && $store->storeLink($storeA)['state'] === 'failed' && $store->account($merchant)['state'] === 'reconcile_required'
    && $store->account($merchant)['last_error_code'] === 'merchant_key_denied', '401: job failed, link failed, account under review');
[$instance, $http, $store, $run] = $scenario();
$syncStore($store, $storeA, 'st-4');
$id = $enqueue($instance, 'pos_link', $terminalA);
$http->queue = [$r(403)];
$run->runOnce();
check($job($id)['state'] === 'failed' && $store->posLink($terminalA)['state'] === 'failed' && $store->account($merchant)['state'] === 'reconcile_required', '403 on POS: link failed, account under review');

// --- esito ignoto, poi nuova generazione: solo GET, marker zero / uno / multipli
[$instance, $http, $store, $run] = $scenario();
$id = $enqueue($instance, 'store_link', $storeA);
$http->queue = [new Http(Http::OUTCOME_UNKNOWN)];
$run->runOnce();
check($job($id)['state'] === 'reconcile_required' && $job($id)['in_flight_op'] === 'create_store' && $store->storeLink($storeA)['state'] === 'reconcile_required', 'Unknown outcome: reconciliation, checkpoint kept');
$token = $marker($store, 'store_link', $storeA);
$g2 = $enqueue($instance, 'store_link', $storeA, 2);
$http->queue = [$r(200, [])];
$run->runOnce();
check($job($g2)['state'] === 'retry_wait' && $job($g2)['last_error_code'] === 'admin_retry_wait' && end($http->calls)[0] === 'GET' && count(array_filter($http->calls, fn($c) => $c[0] === 'POST')) === 1,
    'New generation after a recent uncertain POST: GET only; zero markers = no POST, waits for the quiet period');
$g3 = $enqueue($instance, 'store_link', $storeA, 3);
$http->queue = [$r(200, [['id' => 'st-a', 'name' => 'X ' . $token], ['id' => 'st-b', 'name' => 'Y ' . $token]])];
$run->runOnce();
check($job($g3)['last_error_code'] === 'store_ambiguous' && $store->storeLink($storeA)['remote_store_id'] === null && count(array_filter($http->calls, fn($c) => $c[0] === 'POST')) === 1, 'Multiple markers: ambiguous, nothing adopted, no POST');
$g4 = $enqueue($instance, 'store_link', $storeA, 4);
$http->queue = [$r(200, [['id' => 'st-x', 'name' => 'Other'], ['id' => 'st-ok', 'name' => 'Negozio ' . $storeA . ' ' . $token]])];
$run->runOnce();
check($job($g4)['state'] === 'done' && $store->storeLink($storeA)['state'] === 'synced' && $store->storeLink($storeA)['remote_store_id'] === 'st-ok' && count(array_filter($http->calls, fn($c) => $c[0] === 'POST')) === 1,
    'Exactly one marker: adopted through GET, still a single POST overall');

// --- SIP-022 B5 riprova dell'admin: dopo la quiete una lista completa a vuoto chiude i lavori precedenti e ricrea
[$instance, $http, $store, $run] = $scenario();
$id = $enqueue($instance, 'store_link', $storeA);
$http->queue = [new Http(Http::OUTCOME_UNKNOWN)];
$run->runOnce();
$g2 = $enqueue($instance, 'store_link', $storeA, 2);
$t += ProvisioningLinkWorker::ADMIN_RETRY_QUIET + 1;
$http->queue = [$r(200, [])];
$run->runOnce();
check($job($g2)['state'] === 'retry_wait' && $job($g2)['last_error_code'] === 'admin_retry_recreate' && $job($id)['state'] === 'failed' && $job($id)['last_error_code'] === 'superseded'
    && $job($id)['in_flight_op'] === null && $store->storeLink($storeA)['state'] === 'pending' && count(array_filter($http->calls, fn($c) => $c[0] === 'POST')) === 1,
    'Admin retry after the quiet period: empty complete lookup supersedes old jobs and reopens the link, no POST yet');
$t += 2;
$http->queue = [$r(200, ['id' => 'st-r'])];
$run->runOnce();
check($job($g2)['state'] === 'done' && $store->storeLink($storeA)['remote_store_id'] === 'st-r' && count(array_filter($http->calls, fn($c) => $c[0] === 'POST')) === 2,
    'Then exactly one new POST creates the store');
$t -= ProvisioningLinkWorker::ADMIN_RETRY_QUIET + 3;
[$instance, $http, $store, $run] = $scenario();
$id = $enqueue($instance, 'store_link', $storeA);
$http->queue = [$r(422)];
$run->runOnce();
check($job($id)['state'] === 'failed' && $store->storeLink($storeA)['state'] === 'failed', 'Rejected creation: link failed');
$token = $marker($store, 'store_link', $storeA);
$g2 = $enqueue($instance, 'store_link', $storeA, 2);
$http->queue = [$r(200, [['id' => 'st-f', 'name' => 'Negozio ' . $storeA . ' ' . $token]])];
$run->runOnce();
check($job($g2)['state'] === 'done' && $store->storeLink($storeA)['remote_store_id'] === 'st-f' && end($http->calls)[0] === 'GET' && count(array_filter($http->calls, fn($c) => $c[0] === 'POST')) === 1,
    'Admin retry on a failed link: GET first, existing store with the marker adopted');
$g1 = $enqueue($instance, 'store_link', $storeA, 3);
$run->runOnce();
check($job($g1)['state'] === 'done' && count($http->calls) === 2, 'A first-generation-like job on a synced link does nothing remote');

// --- POS: tipo app errato
[$instance, $http, $store, $run] = $scenario();
$syncStore($store, $storeA, 'st-5');
$id = $enqueue($instance, 'pos_link', $terminalA);
$http->queue = [$r(200, ['id' => 'app-x', 'appType' => 'Crowdfund'])];
$run->runOnce();
check($job($id)['state'] === 'reconcile_required' && $store->posLink($terminalA)['remote_app_id'] === null, 'POS POST returning another app type: not adopted, reconciliation');
$token = $marker($store, 'pos_link', $terminalA);
$g2 = $enqueue($instance, 'pos_link', $terminalA, 2);
$http->queue = [$r(200, [['id' => 'app-x', 'appType' => 'Crowdfund', 'appName' => 'Cassa ' . $token]])];
$run->runOnce();
check($job($g2)['last_error_code'] === 'admin_retry_wait' && end($http->calls)[1] === '/api/v1/stores/st-5/apps' && count(array_filter($http->calls, fn($c) => $c[0] === 'POST')) === 1,
    'Reconciliation ignores a non-PointOfSale app with the marker (no POST within the quiet period)');
$g3 = $enqueue($instance, 'pos_link', $terminalA, 3);
$http->queue = [$r(200, [['id' => 'app-x', 'appType' => 'Crowdfund', 'appName' => 'Cassa ' . $token], ['id' => 'app-ok', 'appType' => 'PointOfSale', 'appName' => 'Cassa ' . $token]])];
$run->runOnce();
check($job($g3)['state'] === 'done' && $store->posLink($terminalA)['remote_app_id'] === 'app-ok' && count(array_filter($http->calls, fn($c) => $c[0] === 'POST')) === 1, 'Single PointOfSale with the marker adopted, no second POST');

// --- lease scaduta durante l'HTTP: esito scartato, poi solo riconciliazione
[$instance, $http, $store, $run] = $scenario();
$id = $enqueue($instance, 'store_link', $storeA);
$http->queue = [function () use (&$t, $r) { $t += 61; return $r(200, ['id' => 'st-6']); }];
$run->runOnce();
check($job($id)['state'] === 'in_flight' && $store->storeLink($storeA)['remote_store_id'] === null, 'Lease expired during POST: outcome discarded');
$run->runOnce();
check($job($id)['state'] === 'reconcile_required' && count($http->calls) === 1, 'Expired in-flight job goes to reconciliation without HTTP');
$g2 = $enqueue($instance, 'store_link', $storeA, 2);
$http->queue = [$r(200, [['id' => 'st-6', 'name' => 'Negozio ' . $storeA . ' ' . $marker($store, 'store_link', $storeA)]])];
$run->runOnce();
check($job($g2)['state'] === 'done' && $http->calls[1][0] === 'GET' && $store->storeLink($storeA)['remote_store_id'] === 'st-6', 'New generation recovers the expired POST through GET only');
$t -= 61;

// --- risorsa occupata
[$instance, $http, $store, $run] = $scenario();
$id = $enqueue($instance, 'store_link', $storeA2);
$holder = $connect();
$lock = 'napay-btcpay-' . substr(hash('sha256', $instance . '|store_link|' . $storeA2), 0, 40);
check((int) $holder->createCommand('SELECT GET_LOCK(:l, 0)', [':l' => $lock])->queryScalar() === 1, 'Another connection holds the resource lock');
$run->runOnce();
check($http->calls === [] && $job($id)['state'] === 'retry_wait' && $job($id)['last_error_code'] === 'resource_busy', 'Same resource busy: no HTTP, retry later');
$holder->createCommand('SELECT RELEASE_LOCK(:l)', [':l' => $lock])->queryScalar();
check((int) $worker->createCommand('SELECT IS_USED_LOCK(:l)', [':l' => $lock])->queryScalar() === 0, 'Lock released after runOnce');

// --- connessione e trace sanificate
$app->set('db', $admin);
$refused = false;
try { $run->runOnce(); } catch (LogicException $e) { $refused = $e->getMessage() === 'worker_connection_mismatch'; }
check($refused, 'Eligibility must run on the worker connection');
$app->set('db', $worker);
$secrets = [$password, $apiKey, $keyring['k1']];
$clean = static function (Throwable $e) use ($secrets): bool {
    $dump = print_r($e->getTrace(), true);
    foreach ($e->getTrace() as $frame) foreach ($frame['args'] ?? [] as $arg) if ($arg instanceof yii\db\Connection || $arg instanceof ProvisioningStore) return false;
    foreach ($secrets as $s) if (str_contains($dump, $s) || str_contains($e->getMessage(), $s)) return false;
    return true;
};
[$instance, $http, $store, $run] = $scenario();
$id = $enqueue($instance, 'store_link', $storeA);
$http->queue = [new LogicException('transport bug with ' . $apiKey)];
$failure = null;
try { $run->runOnce(); } catch (RuntimeException $e) { $failure = $e; }
check($failure !== null && $failure->getMessage() === 'provisioning_link_worker_failed' && $failure->getPrevious() === null && $clean($failure), 'Unexpected failure: sanitized exception, no previous, no secrets or connection in the trace');
$bad = null;
try { new ProvisioningLinkWorker($worker, new ProvisioningJobs($worker, $uuid()), $store, new StoreLinkStep($http->key()), new PosLinkStep($http->key()), 'bad'); }
catch (InvalidArgumentException $e) { $bad = $e; }
check($bad !== null && $clean($bad), 'Constructor trace hides connection, store and keys');
// ===== W3e: riconciliazione GET-only (reconcileOnce), mai POST
$jobFull = static fn(int $id): array => $admin->createCommand('SELECT state, next_attempt_at, in_flight_op, last_error_code, lease_owner FROM btcpay_provisioning_jobs WHERE id=:id', [':id' => $id])->queryOne();
$posts = static fn(FakeHttp $h): int => count(array_filter($h->calls, fn($c) => $c[0] === 'POST'));
/** Scenario con un POST store dall'esito ignoto: lavoro e mapping in riconciliazione con il marker. */
$uncertainStore = static function () use ($scenario, $enqueue, $storeA) {
    [$instance, $http, $store, $run] = $scenario();
    $id = $enqueue($instance, 'store_link', $storeA);
    $http->queue = [new Http(Http::OUTCOME_UNKNOWN)];
    $run->runOnce();
    return [$instance, $http, $store, $run, $id];
};
[$instance, $http, $store, $run, $id] = $uncertainStore();
check($run->runOnce() === false, 'runOnce never takes a reconcile_required job');
$http->queue = [$r(200, [['id' => 'st-r1', 'name' => 'Negozio ' . $storeA . ' ' . $marker($store, 'store_link', $storeA)]])];
check($run->reconcileOnce() === true, 'reconcileOnce takes the uncertain store job');
check($jobFull($id)['state'] === 'done' && $jobFull($id)['in_flight_op'] === null && $store->storeLink($storeA)['state'] === 'synced' && $store->storeLink($storeA)['remote_store_id'] === 'st-r1'
    && end($http->calls)[0] === 'GET' && $posts($http) === 1, 'Single marker adopted through GET: link synced, job done, no new POST');
check($run->reconcileOnce() === false, 'No reconciliation left: false');

[$instance, $http, $store, $run, $id] = $uncertainStore();
$http->queue = [$r(200, [])];
$run->reconcileOnce();
$j = $jobFull($id);
check($j['state'] === 'reconcile_required' && $j['in_flight_op'] === 'create_store' && (int) $j['next_attempt_at'] > $t && $j['lease_owner'] === null && $posts($http) === 1,
    'Zero markers: GET rescheduled with backoff, marker kept, no POST');
check($run->reconcileOnce() === false, 'Rescheduled GET not taken before its time');
$t += 4000;
$http->queue = [$r(200, [['id' => 'st-a', 'name' => 'A ' . $marker($store, 'store_link', $storeA)], ['id' => 'st-b', 'name' => 'B ' . $marker($store, 'store_link', $storeA)]])];
$run->reconcileOnce();
check($jobFull($id)['state'] === 'deferred' && $jobFull($id)['last_error_code'] === 'store_ambiguous' && $store->storeLink($storeA)['remote_store_id'] === null, 'Multiple markers: deferred to the admin, nothing adopted');
$t += 90000;
check($run->reconcileOnce() === false && $posts($http) === 1, 'Deferred reconciliation never claimed automatically');
$t -= 94000;

[$instance, $http, $store, $run, $id] = $uncertainStore();
$http->queue = [$r(429, null, '300')];
$run->reconcileOnce();
check($jobFull($id)['state'] === 'reconcile_required' && (int) $jobFull($id)['next_attempt_at'] >= $t + 300 && $jobFull($id)['last_error_code'] === 'rate_limited', '429 on GET: next GET at Retry-After');

[$instance, $http, $store, $run, $id] = $uncertainStore();
$http->queue = [$r(401)];
$run->reconcileOnce();
check($jobFull($id)['state'] === 'deferred' && $store->storeLink($storeA)['state'] === 'failed' && $store->account($merchant)['state'] === 'reconcile_required', '401 on GET: link failed, account under review, job deferred');

// POS: solo PointOfSale con il marker
[$instance, $http, $store, $run] = $scenario();
$syncStore($store, $storeA, 'st-r2');
$id = $enqueue($instance, 'pos_link', $terminalA);
$http->queue = [new Http(Http::OUTCOME_UNKNOWN)];
$run->runOnce();
$token = $marker($store, 'pos_link', $terminalA);
$http->queue = [$r(200, [['id' => 'app-c', 'appType' => 'Crowdfund', 'appName' => 'Cassa ' . $token]])];
$run->reconcileOnce();
check($jobFull($id)['state'] === 'reconcile_required' && $jobFull($id)['last_error_code'] === 'app_not_found' && $store->posLink($terminalA)['remote_app_id'] === null, 'POS reconciliation ignores another app type with the marker');
$t += 4000;
$http->queue = [$r(200, [['id' => 'app-c', 'appType' => 'Crowdfund', 'appName' => 'Cassa ' . $token], ['id' => 'app-p', 'appType' => 'PointOfSale', 'appName' => 'Cassa ' . $token]])];
$run->reconcileOnce();
check($jobFull($id)['state'] === 'done' && $store->posLink($terminalA)['remote_app_id'] === 'app-p' && end($http->calls)[1] === '/api/v1/stores/st-r2/apps' && $posts($http) === 1, 'Single PointOfSale adopted by GET only');
$t -= 4000;

// Idoneità: prima e durante la GET
[$instance, $http, $store, $run, $id] = $uncertainStore();
$admin->createCommand()->update('users', ['is_active' => 0], ['id' => $owner])->execute();
$calls = count($http->calls);
$run->reconcileOnce();
$admin->createCommand()->update('users', ['is_active' => 1], ['id' => $owner])->execute();
check($jobFull($id)['state'] === 'deferred' && $jobFull($id)['last_error_code'] === 'merchant_ineligible' && count($http->calls) === $calls && $jobFull($id)['in_flight_op'] === 'create_store',
    'Disabled owner before GET: no call, deferred, marker kept');
[$instance, $http, $store, $run, $id] = $uncertainStore();
$http->queue = [function () use ($admin, $owner, $r, $store, $storeA, $marker) {
    $admin->createCommand()->update('users', ['is_active' => 0], ['id' => $owner])->execute();
    return $r(200, [['id' => 'st-late', 'name' => 'Negozio ' . $storeA . ' ' . $marker($store, 'store_link', $storeA)]]);
}];
$run->reconcileOnce();
$admin->createCommand()->update('users', ['is_active' => 1], ['id' => $owner])->execute();
check($jobFull($id)['state'] === 'deferred' && $store->storeLink($storeA)['remote_store_id'] === null && $store->storeLink($storeA)['state'] === 'reconcile_required', 'Disabled during GET: ID not adopted, deferred');

// Lease scaduta durante la GET, risorsa occupata, dipendenza e mapping mancanti
[$instance, $http, $store, $run, $id] = $uncertainStore();
$http->queue = [function () use (&$t, $r, $store, $storeA, $marker) { $t += 61; return $r(200, [['id' => 'st-x', 'name' => 'Negozio ' . $storeA . ' ' . $marker($store, 'store_link', $storeA)]]); }];
$run->reconcileOnce();
check($jobFull($id)['state'] === 'reconcile_required' && $store->storeLink($storeA)['remote_store_id'] === null, 'Lease expired during GET: late result has no effect');
$t -= 61;
[$instance, $http, $store, $run, $id] = $uncertainStore();
$holder = $connect();
$lock = 'napay-btcpay-' . substr(hash('sha256', $instance . '|store_link|' . $storeA), 0, 40);
$holder->createCommand('SELECT GET_LOCK(:l, 0)', [':l' => $lock])->queryScalar();
$calls = count($http->calls);
$run->reconcileOnce();
$holder->createCommand('SELECT RELEASE_LOCK(:l)', [':l' => $lock])->queryScalar();
check($jobFull($id)['state'] === 'reconcile_required' && $jobFull($id)['last_error_code'] === 'resource_busy' && count($http->calls) === $calls, 'Resource busy: reconciliation retried later, no call');
[$instance, $http, $store, $run, $id] = $uncertainStore();
$store->updateAccount($store->account($merchant), ['state' => 'reconcile_required']);
$calls = count($http->calls);
$run->reconcileOnce();
check($jobFull($id)['state'] === 'reconcile_required' && $jobFull($id)['last_error_code'] === 'account_not_ready' && count($http->calls) === $calls, 'Account not synced: GET postponed, no call');
[$instance, $http, $store, $run] = $scenario();
$admin->createCommand()->insert('btcpay_provisioning_jobs', ['kind' => 'store_link', 'instance' => $instance, 'merchant_id' => $merchant, 'entity_id' => $storeA2,
    'state' => 'reconcile_required', 'created_at' => $t, 'updated_at' => $t])->execute();
$missing = (int) $admin->lastInsertID;
$run->reconcileOnce();
check($jobFull($missing)['state'] === 'deferred' && $jobFull($missing)['last_error_code'] === 'link_missing' && $store->storeLink($storeA2) === null && $http->calls === [],
    'Reconciliation without a mapping: deferred, mapping never created, no call');
$failure = null;
[$instance, $http, $store, $run, $id] = $uncertainStore();
$http->queue = [new LogicException('transport bug with ' . $apiKey)];
try { $run->reconcileOnce(); } catch (RuntimeException $e) { $failure = $e; }
check($failure !== null && $failure->getMessage() === 'provisioning_link_worker_failed' && $failure->getPrevious() === null && $clean($failure), 'reconcileOnce failure sanitized like runOnce');
// --- SIP-022 passo 3: store_update, impostazioni dello store con la chiave del merchant (PUT idempotente)
$long = app\components\btcpay\provisioning\steps\StoreLinkStep::name(str_repeat('Pizzeria Napoletana ', 5), str_repeat('a', 32));
check(mb_strlen($long) === 50 && str_ends_with($long, ' [napay-' . str_repeat('a', 32) . ']'), 'Creation name never exceeds the 50 characters BTCPay accepts, marker intact');
$admin->createCommand()->insert('storesettings', ['store_id' => $storeA, 'defaultCurrency' => 'EUR', 'invoiceExpiration' => 900, 'speedPolicy' => 'MediumSpeed',
    'requiresRefundEmail' => 1, 'receipt' => '{"enabled":1,"showQR":1,"showPayments":0}', 'defaultPaymentMethod' => 'BTC', 'htmlTitle' => 'W3c',
    'monitoringExpiration' => 3600, 'showRecommendedFee' => 1, 'website' => 'www.negozio.example', 'customLogo' => 'https://napay.example.invalid/logo.png'])->execute();
$storeName = (string) $admin->createCommand('SELECT description FROM stores WHERE id=:s', [':s' => $storeA])->queryScalar();
[$instance, $http, $store, $run] = $scenario();
$settings = new ProvisioningSettingsWorker($worker, new ProvisioningJobs($worker, $uuid(), 60, $clock), $store, new StoreSettingsStep($http->key()), $instance, $clock);
check($settings->runOnce() === false, 'Settings worker idle without store_update jobs');
$su = $enqueue($instance, 'store_update', $storeA);
check($settings->runOnce() === true && $job($su)['state'] === 'retry_wait' && $job($su)['last_error_code'] === 'store_not_ready' && count($http->calls) === 0,
    'Settings wait for the store link (no call)');
$syncStore($store, $storeA, 'st-set');
$t += 3600;
$http->queue = [$r(200, ['id' => 'st-set']), $r(200, StoreSettingsStep::RATES)];
$settings->runOnce();
[$method, $path, $body, $key] = $http->calls[0];
[$rm, $rp, $rb, $rk] = $http->calls[1];
check($job($su)['state'] === 'done' && $method === 'PUT' && $path === '/api/v1/stores/st-set' && $key === $apiKey
    && $body === ['name' => $storeName, 'defaultCurrency' => 'EUR'] + StoreSettingsPayload::FIXED
        + ['website' => 'https://www.negozio.example', 'logoUrl' => 'https://napay.example.invalid/logo.png']
    && $body['monitoringExpiration'] === 86400 && $body['showRecommendedFee'] === false && $body['htmlTitle'] === 'Attesa pagamento in cryptovaluta...',
    'One PUT with the merchant key: shop name, currency, website and logo from Napay, the same standard values for every store (old stored values ignored)');
check(count($http->calls) === 2 && $rm === 'PUT' && $rp === '/api/v1/stores/st-set/rates/configuration/primary' && $rk === $apiKey
    && $rb === ['isCustomScript' => false, 'preferredSource' => 'kraken', 'spread' => '0'], 'SIP-033: then the store rates (fixed source, never a custom script) with the merchant key');
$sr = $enqueue($instance, 'store_update', $storeA, 3);
$http->queue = [$r(200, ['id' => 'st-set']), $r(503)];
$settings->runOnce();
check($job($sr)['state'] === 'retry_wait' && $job($sr)['last_error_code'] === 'rates_update_unavailable', 'Rates 5xx: whole idempotent job retried later');
$t += 3600;
$http->queue = [$r(200, ['id' => 'st-set']), $r(400, ['code' => 'generic-error'])];
$settings->runOnce();
check($job($sr)['state'] === 'failed' && $job($sr)['last_error_code'] === 'rates_rejected', 'Rate source refused by BTCPay: failed with a code for the admin');
$su2 = $enqueue($instance, 'store_update', $storeA, 4);
$http->queue = [$r(502)];
$settings->runOnce();
check($job($su2)['state'] === 'retry_wait' && $job($su2)['last_error_code'] === 'store_update_unavailable', '5xx: retried later, never reconciliation (idempotent PUT)');
$t += 3600;
$http->queue = [$r(401)];
$settings->runOnce();
check($job($su2)['state'] === 'failed' && $job($su2)['last_error_code'] === 'merchant_key_denied' && $store->account($merchant)['state'] === 'reconcile_required',
    '401: job failed and account under review (admin retry = new key)');
$calls = count($http->calls);
$su3 = $enqueue($instance, 'store_update', $storeB);
$settings->runOnce();
check($job($su3)['state'] === 'failed' && $job($su3)['last_error_code'] === 'scope_mismatch' && count($http->calls) === $calls, 'Store of another merchant: failed, no call');
$t -= 10800;
// --- SIP-022 passo 3b: store_archive (negozio eliminato): app POS rimosse, store rinominato, mapping chiusi
[$instance, $http, $store, $run] = $scenario();
$archiver = new ProvisioningSettingsWorker($worker, new ProvisioningJobs($worker, $uuid(), 60, $clock), $store, new StoreSettingsStep($http->key()), $instance, $clock,
    archiveStep: new StoreArchiveStep($http->key()));
$syncStore($store, $storeA, 'st-arc');
$posLink = $store->ensurePosLink($store->storeLink($storeA), $terminalA);
$store->updatePosLink($posLink, ['state' => 'synced', 'remote_app_id' => 'app-arc']);
$arc = $enqueue($instance, 'store_archive', $storeA);
$http->queue = [$r(500)];
$archiver->runOnce();
check($job($arc)['state'] === 'retry_wait' && $job($arc)['last_error_code'] === 'app_delete_unavailable' && $store->posLink($terminalA)['state'] === 'synced', 'App removal 5xx: retried later, link still open');
$t += 3600;
$http->queue = [$r(404), $r(200, ['id' => 'st-arc'])];
$archiver->runOnce();
[$delete, $put] = array_slice($http->calls, -2);
check($job($arc)['state'] === 'done' && $delete[0] === 'DELETE' && $delete[1] === '/api/v1/apps/app-arc' && $put[0] === 'PUT' && $put[1] === '/api/v1/stores/st-arc'
    && str_ends_with($put[2]['name'], ' - archiviato') && mb_strlen($put[2]['name']) <= 50, 'Archive: POS app removed (404 = already gone), store renamed as archived, never deleted');
check($store->storeLink($storeA)['state'] === 'suspended' && $store->storeLink($storeA)['last_error_code'] === 'store_archived' && $store->storeLink($storeA)['remote_store_id'] === 'st-arc'
    && $store->posLink($terminalA)['state'] === 'suspended' && $store->posLink($terminalA)['last_error_code'] === 'store_archived', 'Mappings closed, remote IDs kept for history');
$calls = count($http->calls);
$after = $enqueue($instance, 'store_update', $storeA, 50);
$again = $enqueue($instance, 'store_archive', $storeA, 2);
$archiver->runOnce(); $archiver->runOnce();
check($job($after)['state'] === 'failed' && $job($after)['last_error_code'] === 'store_archived' && $job($again)['state'] === 'done' && count($http->calls) === $calls,
    'After the archive: settings refused and a repeated archive does nothing remote');
$never = $enqueue($instance, 'store_archive', $storeA2);
$archiver->runOnce();
check($job($never)['state'] === 'done' && count($http->calls) === $calls, 'Archive of a store never linked to BTCPay: done without calls');
$admin->createCommand()->update('stores', ['archived_at' => $t], ['id' => $storeA2])->execute();
$link2 = $enqueue($instance, 'store_link', $storeA2);
$run->runOnce();
check($job($link2)['state'] === 'failed' && $job($link2)['last_error_code'] === 'store_archived' && count($http->calls) === $calls, 'Deleted store: no BTCPay store is ever created for it');
$t -= 3600;
// --- SIP-022 xpub: store_wallet imposta o rimuove il metodo on-chain con la xpub salvata in Napay
[$instance, $http, $store, $run] = $scenario();
$wallets = new ProvisioningSettingsWorker($worker, new ProvisioningJobs($worker, $uuid(), 60, $clock), $store, new StoreSettingsStep($http->key()), $instance, $clock,
    walletStep: new StoreWalletStep($http->key()));
$syncStore($store, $storeB2 = $newStore($merchant, 'W3c negozio xpub'), 'st-wal');
$admin->createCommand()->insert('storesettings', ['store_id' => $storeB2, 'defaultCurrency' => 'EUR', 'derivationScheme' => 'vpub-test-key'])->execute();
$w1 = $enqueue($instance, 'store_wallet', $storeB2);
$http->queue = [$r(200, ['enabled' => true])];
$wallets->runOnce();
[$m, $p, $b, $k] = end($http->calls);
check($job($w1)['state'] === 'done' && $m === 'PUT' && $p === '/api/v1/stores/st-wal/payment-methods/BTC-CHAIN' && $b === ['enabled' => true, 'config' => 'vpub-test-key'] && $k === $apiKey,
    'Wallet: PUT of the on-chain payment method with the saved xpub and the merchant key');
$w2 = $enqueue($instance, 'store_wallet', $storeB2, 2);
$http->queue = [$r(422, [['path' => 'config', 'message' => 'Invalid derivation scheme vpub-test-key']])];
$wallets->runOnce();
check($job($w2)['state'] === 'failed' && $job($w2)['last_error_code'] === 'wallet_rejected', 'Key refused by BTCPay: failed with a generic code (the key never in the code)');
$admin->createCommand()->update('storesettings', ['derivationScheme' => null], ['store_id' => $storeB2])->execute();
$w3 = $enqueue($instance, 'store_wallet', $storeB2, 3);
$http->queue = [$r(404)];
$wallets->runOnce();
[$m, $p] = end($http->calls);
check($job($w3)['state'] === 'done' && $m === 'DELETE' && $p === '/api/v1/stores/st-wal/payment-methods/BTC-CHAIN', 'Wallet removed: DELETE of the payment method (404 = already absent)');
// --- SIP-022 passo 4: webhook dello store creato o aggiornato da store_update (segreto derivato per negozio)
[$instance, $http, $store, $run] = $scenario();
$master = bin2hex(random_bytes(32));
$hookUrl = 'https://napay.example.invalid/api/btcpay/v1/store-webhook';
$hooks = new ProvisioningSettingsWorker($worker, new ProvisioningJobs($worker, $uuid(), 60, $clock), $store, new StoreSettingsStep($http->key()), $instance, $clock,
    webhookStep: new StoreWebhookStep($http->key()), webhookUrl: $hookUrl, webhookMaster: $master);
$syncStore($store, $storeB2, 'st-hook');
$secret = app\components\btcpay\StoreWebhookSecret::derive($master, $instance, $storeB2);
$h1 = $enqueue($instance, 'store_update', $storeB2, 10);
$http->queue = [$r(200, ['id' => 'st-hook']), $r(200, StoreSettingsStep::RATES), $r(200, []), $r(200, ['id' => 'wh-1'])];
$hooks->runOnce();
[$get, $post] = array_slice($http->calls, -2);
check($job($h1)['state'] === 'done' && $get[0] === 'GET' && $get[1] === '/api/v1/stores/st-hook/webhooks' && $post[0] === 'POST' && $post[1] === '/api/v1/stores/st-hook/webhooks'
    && $post[2]['url'] === $hookUrl && $post[2]['secret'] === $secret && $post[2]['authorizedEvents']['everything'] === false
    && in_array('InvoiceSettled', $post[2]['authorizedEvents']['specificEvents'], true), 'Settings then webhook: created with the per-store derived secret and invoice events only');
$h2 = $enqueue($instance, 'store_update', $storeB2, 11);
$http->queue = [$r(200, ['id' => 'st-hook']), $r(200, StoreSettingsStep::RATES), $r(200, [['id' => 'wh-1', 'url' => $hookUrl], ['id' => 'wh-x', 'url' => 'https://other.example.invalid/hook']]), $r(200, ['id' => 'wh-1'])];
$hooks->runOnce();
[$m, $p] = end($http->calls);
check($job($h2)['state'] === 'done' && $m === 'PUT' && $p === '/api/v1/stores/st-hook/webhooks/wh-1', 'Existing Napay webhook updated in place, never duplicated, other webhooks untouched');
$h3 = $enqueue($instance, 'store_update', $storeB2, 12);
$http->queue = [$r(200, ['id' => 'st-hook']), $r(200, StoreSettingsStep::RATES), $r(200, [['id' => 'wh-1', 'url' => $hookUrl], ['id' => 'wh-2', 'url' => $hookUrl]])];
$hooks->runOnce();
check($job($h3)['state'] === 'failed' && $job($h3)['last_error_code'] === 'webhook_ambiguous', 'Two Napay webhooks on the store: left to the admin');
check($secret !== app\components\btcpay\StoreWebhookSecret::derive($master, $instance, $storeA) && strlen($secret) === 64, 'Each store has its own webhook secret');
$refused = false;
try { new ProvisioningSettingsWorker($worker, new ProvisioningJobs($worker, $uuid()), $store, new StoreSettingsStep($http->key()), $instance, webhookStep: new StoreWebhookStep($http->key()), webhookUrl: 'http://plain.example.invalid/h', webhookMaster: $master); }
catch (InvalidArgumentException $e) { $refused = $e->getMessage() === 'invalid_webhook_config'; }
check($refused, 'Webhook URL must be HTTPS');
// --- SIP-033: store_update riallinea le app POS sincronizzate (PUT completo: importo libero, valuta del negozio)
[$instance, $http, $store, $run] = $scenario();
$configurer = new ProvisioningSettingsWorker($worker, new ProvisioningJobs($worker, $uuid(), 60, $clock), $store, new StoreSettingsStep($http->key()), $instance, $clock,
    posStep: new PosLinkStep($http->key()));
$syncStore($store, $storeA, 'st-cfg');
$cfgLink = $store->ensurePosLink($store->storeLink($storeA), $terminalA);
$store->updatePosLink($cfgLink, ['state' => 'synced', 'remote_app_id' => 'app-cfg']);
$c1 = $enqueue($instance, 'store_update', $storeA);
$http->queue = [$r(200, ['id' => 'st-cfg']), $r(200, StoreSettingsStep::RATES), $r(200, ['id' => 'app-cfg'])];
$configurer->runOnce();
[$putStore, , $putApp] = array_slice($http->calls, -3);
check($job($c1)['state'] === 'done' && $putStore[1] === '/api/v1/stores/st-cfg' && $putApp[0] === 'PUT' && $putApp[1] === '/api/v1/apps/pos/app-cfg' && $putApp[3] === $apiKey
    && $putApp[2] === ['appName' => 'Cassa ' . $terminalA . ' [napay-' . $cfgLink['marker'] . ']', 'defaultView' => 'Light', 'showCustomAmount' => true, 'currency' => 'EUR'],
    'Store update then each synced POS app gets the full configuration with the shop currency (merchant key)');
$c2 = $enqueue($instance, 'store_update', $storeA, 2);
$http->queue = [$r(200, ['id' => 'st-cfg']), $r(200, StoreSettingsStep::RATES), $r(503)];
$configurer->runOnce();
check($job($c2)['state'] === 'retry_wait' && $job($c2)['last_error_code'] === 'app_update_unavailable', 'App update 5xx: whole idempotent job retried later');
$t += 3600;
$http->queue = [$r(200, ['id' => 'st-cfg']), $r(200, StoreSettingsStep::RATES), $r(404)];
$configurer->runOnce();
check($job($c2)['state'] === 'failed' && $job($c2)['last_error_code'] === 'app_missing', 'App gone from BTCPay: job failed with a code for the admin');
$t -= 3600;
// --- store collegato: impostazioni e wallet in attesa dello store tornano dovuti subito
[$instance, $http, $store, $run] = $scenario();
$wake = $newStore($merchant, 'W3c negozio sveglia');
$waitSettings = $enqueue($instance, 'store_update', $wake);
$admin->createCommand()->update('btcpay_provisioning_jobs', ['state' => 'retry_wait', 'next_attempt_at' => $t + 3600, 'last_error_code' => 'store_not_ready'], ['id' => $waitSettings])->execute();
$create = $enqueue($instance, 'store_link', $wake);
$http->queue = [$r(200, ['id' => 'st-wake'])];
$run->runOnce();
check($job($create)['state'] === 'done' && (int) $job($waitSettings)['next_attempt_at'] <= $t, 'Store linked: settings waiting for it are due now');
// --- SIP-033: sito e logo accettati solo come URL http(s) assoluti senza credenziali
check(StoreSettingsPayload::url('www.negozio.example/chi-siamo', true) === 'https://www.negozio.example/chi-siamo' && StoreSettingsPayload::url('http://a.example', true) === 'http://a.example'
    && StoreSettingsPayload::url('www.negozio.example', false) === null && StoreSettingsPayload::url('javascript:alert(1)', true) === null
    && StoreSettingsPayload::url('ftp://a.example', true) === null && StoreSettingsPayload::url('https://u:p@a.example', true) === null
    && StoreSettingsPayload::url('https://a.example/x y', true) === null && StoreSettingsPayload::url(str_repeat('a', 250) . '.example', true) === null
    && StoreSettingsPayload::url(null, true) === null && StoreSettingsPayload::url('  ', true) === null, 'Website and logo: only absolute http(s) URLs, bare host becomes https, anything else omitted');
$bad = StoreSettingsPayload::build('Negozio', ['defaultCurrency' => 'eur', 'website' => 'not a site', 'customLogo' => 'logo.png']);
check($bad === ['name' => 'Negozio'] + StoreSettingsPayload::FIXED, 'Invalid currency, website or logo never reach BTCPay: standard values only');
echo "PASS $checks BTCPay provisioning link worker checks\n";
