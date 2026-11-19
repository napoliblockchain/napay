<?php
// SIP-030 passo 5: negozi v1 sul ponte BTCPay (BridgePosApps). DB usa e getta, HTTP Greenfield simulato (nessuna rete).
if (PHP_SAPI !== 'cli') exit(1);
ini_set('zend.exception_ignore_args', '0');
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';

use app\components\btcpay\BridgePosApps;
use app\components\btcpay\StoreWebhookSecret;
use app\components\btcpay\provisioning\ProvisioningHttpException as Http;
use app\components\pos\btc\BtcPayments;

$app = new yii\console\Application(require $root . '/config/console.php');
$db = $app->db;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);
$checks = 0;
function check($condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException($label); ++$checks; echo 'PASS: ' . $label . "\n"; }
function scalar(string $sql, array $p = []) { global $db; return $db->createCommand($sql, $p)->queryScalar(); }
function link_row(int $terminal): array { global $db; return $db->createCommand('SELECT * FROM btcpay_bridge_pos_links WHERE terminal_id=:t', [':t' => $terminal])->queryOne() ?: []; }

/** Client finto: coda di risposte/eccezioni, registra ogni chiamata (metodo, percorso, corpo, chiave). */
final class FakeHttp
{
    public array $calls = []; public array $queue = [];
    public function key(): Closure { return function (string $m, string $p, ?array $b, string $k) {
        $this->calls[] = [$m, $p, $b, $k];
        $reply = array_shift($this->queue) ?? throw new RuntimeException('Unexpected HTTP call ' . $m . ' ' . $p);
        if ($reply instanceof Throwable) throw $reply;
        return $reply;
    }; }
}
$r = static fn(int $status, $json = null, ?string $retry = null): array => ['status' => $status, 'retry_after' => $retry, 'json' => $json];

// --- fixture: negozi v1 (store BTCPay del ponte), uno archiviato, uno duplicato, uno del provisioning; terminali
$merchant = (int) scalar("SELECT id FROM merchants WHERE description='Runtime company merchant'");
$now = time();
$vpub = 'vpub5Zf9o1xg1TVJN3pte4sDD73BMq71dLFTZPgqvBxe872nMEiDtoBo99udYHBuxP3wDbhie79PmTtL8GxYR5ana9FgxdKTp13TvV3kU6UaHrP';
$store = function (string $name, ?string $bps, bool $archived = false) use ($db, $merchant, $vpub, $now): int {
    $db->createCommand()->insert('stores', ['merchant_id' => $merchant, 'description' => $name, 'email' => 'fixture@example.invalid', 'phone' => '', 'mobile' => '',
        'addressStreet' => '', 'addressNumberHouse' => '', 'addressCity' => '', 'addressZip' => '', 'addressProvince' => '', 'addressCountry' => '',
        'archived_at' => $archived ? $now : null])->execute();
    $id = (int) $db->lastInsertID;
    $db->createCommand()->insert('storesettings', ['store_id' => $id, 'bps_storeid' => $bps, 'defaultCurrency' => 'EUR', 'speedPolicy' => 'MediumSpeed',
        'requiresRefundEmail' => 1, 'derivationScheme' => $vpub, 'receipt' => json_encode(['enabled' => 1])])->execute();
    return $id;
};
$terminal = function (int $storeId, string $name, int $enabled = 1) use ($db, $merchant, $now): int {
    $b = random_bytes(16); $b[6] = chr(ord($b[6]) & 0x0f | 0x40); $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
    $db->createCommand()->insert('pos_terminals', ['public_id' => vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4)), 'merchant_id' => $merchant,
        'store_id' => $storeId, 'name' => $name, 'is_enabled' => $enabled, 'created_at' => $now, 'updated_at' => $now])->execute();
    return (int) $db->lastInsertID;
};
$sV1 = $store('Bridge v1', ' V1StoreAAA ');
$sOld = $store('Bridge v1 archiviato', 'V1StoreOLD', true);
$sDup = $store('Bridge v1 doppio', 'V1StoreAAA');
$sNew = $store('Bridge v2', null);
$t1 = $terminal($sV1, 'Cassa ponte 1'); $tOff = $terminal($sV1, 'Cassa ponte spenta', 0); $tOld = $terminal($sOld, 'Cassa archiviata'); $tNew = $terminal($sNew, 'Cassa v2');
$t = $now;
$clock = static function () use (&$t): int { return $t; };
$instance = bin2hex(random_bytes(32));
$key = 'BridgeKey' . bin2hex(random_bytes(12));
$master = bin2hex(random_bytes(32));
$hook = 'https://napay.example.invalid/api/btcpay/v1/store-webhook';
$http = new FakeHttp();
$bridge = new BridgePosApps($db, $http->key(), $key, $instance, $hook, $master, $clock);

// --- configurazione
foreach (['BTCPAY_STORES_API_KEY', 'BTCPAY_PROVISION_INSTANCE', 'BTCPAY_PROVISION_ORIGIN', 'BTCPAY_STORE_WEBHOOK_URL', 'BTCPAY_STORE_WEBHOOK_KEY'] as $name) putenv($name);
check(BridgePosApps::fromEnv($db) === null, 'Without the bridge key the bridge is disabled');
putenv('BTCPAY_STORES_API_KEY=' . $key);
check(BridgePosApps::fromEnv($db) === null, 'Without the provisioning instance the bridge is disabled');
putenv('BTCPAY_PROVISION_ORIGIN=https://btcpay.example.invalid');
putenv('BTCPAY_PROVISION_INSTANCE=' . hash('sha256', 'https://btcpay.example.invalid'));
$refused = static function (): bool { try { BridgePosApps::fromEnv(Yii::$app->db); } catch (yii\base\InvalidConfigException $e) { return true; } return false; };
check($refused(), 'BTCPAY_URL different from the invoice origin is refused (v1 stores live on BTCPAY_URL)');
putenv('BTCPAY_PROVISION_INSTANCE=' . str_repeat('a', 64));
check($refused(), 'Instance digest not matching the origin is refused');
putenv('BTCPAY_STORES_API_KEY=short');
putenv('BTCPAY_PROVISION_INSTANCE=' . hash('sha256', 'https://btcpay.example.invalid'));
check($refused(), 'Malformed bridge key is refused');
foreach (['BTCPAY_STORES_API_KEY', 'BTCPAY_PROVISION_INSTANCE', 'BTCPAY_PROVISION_ORIGIN'] as $name) putenv($name);
$invalid = static function (callable $make): bool { try { $make(); } catch (InvalidArgumentException $e) { return true; } return false; };
check($invalid(fn() => new BridgePosApps($db, $http->key(), $key, $instance, $hook, null)), 'Webhook URL without master key is refused');
check($invalid(fn() => new BridgePosApps($db, $http->key(), $key, $instance, 'http://napay.example.invalid/hook', $master)), 'Non-HTTPS webhook URL is refused');

// --- prima passata: scoperta, webhook, app POS
$http->queue = [$r(200, []), $r(200, ['id' => 'wh-1']), $r(200, ['id' => 'app-1', 'appType' => 'PointOfSale'])];
$report = $bridge->run();
check($report['stores'] === 1 && $report['stores_conflict'] === 1 && $report['terminals'] === 1, 'Discovery: one v1 store, duplicate BTCPay store refused, one enabled terminal');
check((int) scalar('SELECT COUNT(*) FROM btcpay_bridge_stores') === 1 && scalar('SELECT remote_store_id FROM btcpay_bridge_stores WHERE store_id=:s', [':s' => $sV1]) === 'V1StoreAAA',
    'Bridge store row uses the trimmed v1 BTCPay store ID; archived and v2 stores are ignored');
check(link_row($tOff) === [] && link_row($tOld) === [] && link_row($tNew) === [], 'No POS app for disabled terminals, archived stores or v2 stores');
[$m, $p, $b, $k] = $http->calls[1];
check($http->calls[0][0] === 'GET' && $http->calls[0][1] === '/api/v1/stores/V1StoreAAA/webhooks' && $m === 'POST' && $p === '/api/v1/stores/V1StoreAAA/webhooks'
    && $b['url'] === $hook && hash_equals(StoreWebhookSecret::derive($master, $instance, $sV1), $b['secret']) && $b['authorizedEvents']['everything'] === false && $k === $key,
    'Webhook registered with the bridge key and the per-store derived secret');
check(scalar('SELECT webhook_state FROM btcpay_bridge_stores WHERE store_id=:s', [':s' => $sV1]) === 'synced' && $report['webhooks'] === 1, 'Webhook state synced');
[$m, $p, $b, $k] = $http->calls[2];
$link = link_row($t1);
check($m === 'POST' && $p === '/api/v1/stores/V1StoreAAA/apps/pos' && $k === $key && $b['currency'] === 'EUR' && $b['showCustomAmount'] === true
    && str_ends_with($b['appName'], '[napay-' . $link['marker'] . ']') && str_starts_with($b['appName'], 'Cassa ' . $t1), 'One POS app POST with marker, keypad config and store currency');
check($link['state'] === 'synced' && $link['remote_app_id'] === 'app-1' && $link['currency'] === 'EUR' && $report['created'] === 1, 'POS app link synced');
check(count($http->calls) === 3, 'Exactly three remote calls in the first pass');
$http->calls = [];
$report = $bridge->run();
check($http->calls === [] && $report['created'] + $report['terminals'] + $report['stores'] === 0, 'Second pass is a no-op without remote calls');

// --- target(): il pagamento BTC del terminale usa store e app del ponte
$target = new ReflectionMethod(BtcPayments::class, 'target');
$pay = $target->invoke(null, $db, $t1, $merchant, $instance);
check($pay === ['remote_store_id' => 'V1StoreAAA', 'remote_app_id' => 'app-1', 'currency' => 'EUR'], 'BTC payment target of a v1 terminal = bridge store and app');
check($target->invoke(null, $db, $t1, $merchant, str_repeat('b', 64)) === null, 'Other BTCPay instance: no target');
$db->createCommand()->update('storesettings', ['bps_storeid' => 'V1StoreZZZ'], ['store_id' => $sV1])->execute();
check($target->invoke(null, $db, $t1, $merchant, $instance) === null, 'Store pointing to a different BTCPay store than the bridge row: no target');
$db->createCommand()->update('storesettings', ['bps_storeid' => 'V1StoreAAA', 'derivationScheme' => null], ['store_id' => $sV1])->execute();
check($target->invoke(null, $db, $t1, $merchant, $instance) === null, 'v1 store without xpub: no target');
$db->createCommand()->update('storesettings', ['derivationScheme' => $vpub], ['store_id' => $sV1])->execute();
check($target->invoke(null, $db, $tNew, $merchant, $instance) === null, 'v2 store without provisioning links: still no target');

// --- esito ignoto: solo GET, nuovo POST con lo stesso marker solo dopo QUIET
$t2 = $terminal($sV1, 'Cassa ponte 2');
$http->queue = [new Http(Http::OUTCOME_UNKNOWN)];
$bridge->run();
$link = link_row($t2);
check($link['state'] === 'reconcile_required' && $link['remote_app_id'] === null && count($http->calls) === 1, 'Unknown POST outcome: reconciliation, no app adopted');
check($target->invoke(null, $db, $t2, $merchant, $instance) === null, 'Terminal without a synced app has no BTC target');
$http->calls = [];
$bridge->run();
check($http->calls === [], 'Reconciliation waits for its retry time');
$t = (int) $link['next_attempt_at'];
$http->queue = [$r(200, [['id' => 'other', 'appType' => 'PointOfSale', 'appName' => 'Cassa ' . $t2]])];
$bridge->run();
check(count($http->calls) === 1 && $http->calls[0][0] === 'GET' && $http->calls[0][1] === '/api/v1/stores/V1StoreAAA/apps' && link_row($t2)['state'] === 'reconcile_required',
    'Marker not found within the quiet period: still GET-only');
$t = $now + BridgePosApps::QUIET + 3600; $http->calls = [];
$http->queue = [$r(200, [])];
$bridge->run();
check(count($http->calls) === 1 && link_row($t2)['state'] === 'pending' && link_row($t2)['marker'] === $link['marker'], 'Marker still absent after the quiet period: back to pending with the same marker');
$http->queue = [$r(200, ['id' => 'app-2', 'appType' => 'PointOfSale'])];
$bridge->run();
check(str_contains($http->calls[1][2]['appName'], $link['marker']) && link_row($t2)['remote_app_id'] === 'app-2', 'New single POST with the same marker, app synced');

// --- adozione dopo esito ignoto
$t3 = $terminal($sV1, 'Cassa ponte 3');
$http->calls = []; $http->queue = [$r(503)];
$bridge->run();
$link = link_row($t3);
check($link['state'] === 'reconcile_required', '5xx on POST: reconciliation');
$t = (int) $link['next_attempt_at'];
$http->queue = [$r(200, [['id' => 'app-3', 'appType' => 'PointOfSale', 'appName' => 'Cassa ' . $t3 . ' [napay-' . $link['marker'] . ']'],
    ['id' => 'fake', 'appType' => 'Crowdfund', 'appName' => 'x [napay-' . $link['marker'] . ']']])];
$report = $bridge->run();
check(link_row($t3)['remote_app_id'] === 'app-3' && $report['adopted'] === 1 && count($http->calls) === 2, 'Late app adopted by marker (only PointOfSale), no second POST');

// --- 429 e 403
$t4 = $terminal($sV1, 'Cassa ponte 4');
$http->calls = []; $http->queue = [$r(429, null, '120')];
$bridge->run();
$link = link_row($t4);
check($link['state'] === 'pending' && (int) $link['attempts'] === 1 && (int) $link['next_attempt_at'] >= $t + 120 && $link['last_error_code'] === 'rate_limited', '429: POST not executed, pending with Retry-After');
$t = (int) $link['next_attempt_at'];
$http->queue = [$r(403)];
$report = $bridge->run();
check(link_row($t4)['state'] === 'failed' && link_row($t4)['last_error_code'] === 'merchant_key_denied' && $report['failed'] === 1, '403: link failed, no retry');
$http->calls = [];
$t += 7200;
$bridge->run();
check($http->calls === [], 'Failed link is not retried automatically');

// --- valuta del negozio cambiata e app rimossa da BTCPay
$db->createCommand()->update('storesettings', ['defaultCurrency' => 'USD'], ['store_id' => $sV1])->execute();
$http->queue = [$r(200, ['id' => 'app-1']), $r(200, ['id' => 'app-2']), $r(404)];
$report = $bridge->run();
check($http->calls[0][0] === 'PUT' && $http->calls[0][1] === '/api/v1/apps/pos/app-1' && $http->calls[0][2]['currency'] === 'USD' && $http->calls[0][2]['appName'] === 'Cassa ' . $t1
    && link_row($t1)['currency'] === 'USD' && $report['reconfigured'] === 2, 'Currency change: full PUT of the app config');
$old = link_row($t3);
check($old['state'] === 'pending' && $old['remote_app_id'] === null && $old['last_error_code'] === 'app_missing', 'App removed from BTCPay (404): new app on the next pass');
$http->calls = []; $http->queue = [$r(200, ['id' => 'app-3b', 'appType' => 'PointOfSale'])];
$bridge->run();
check(link_row($t3)['remote_app_id'] === 'app-3b' && $http->calls[0][2]['currency'] === 'USD' && str_contains($http->calls[0][2]['appName'], $old['marker']),
    'Recreated app with the current currency');
check($target->invoke(null, $db, $t1, $merchant, $instance)['currency'] === 'USD', 'Target follows the store currency');

// --- webhook: rifiuto definitivo e configurazione tardiva
$sV1b = $store('Bridge v1 B', 'V1StoreBBB');
$http->calls = []; $http->queue = [$r(403)];
$bridge->run();
check(scalar('SELECT webhook_state FROM btcpay_bridge_stores WHERE store_id=:s', [':s' => $sV1b]) === 'failed', 'Webhook refused (403): failed, no loop');
$sV1c = $store('Bridge v1 C', 'V1StoreCCC');
$quiet = new BridgePosApps($db, (new FakeHttp())->key(), $key, $instance, null, null, $clock);
$quiet->run();
check(scalar('SELECT webhook_state FROM btcpay_bridge_stores WHERE store_id=:s', [':s' => $sV1c]) === 'disabled', 'Without webhook config the store is recorded as disabled, no calls');
$http->calls = []; $http->queue = [$r(200, []), $r(200, ['id' => 'wh-c'])];
$bridge->run();
check(scalar('SELECT webhook_state FROM btcpay_bridge_stores WHERE store_id=:s', [':s' => $sV1c]) === 'synced', 'Webhook registered once configured');

// --- lock: una sola passata alla volta
$other = new yii\db\Connection(['dsn' => $db->dsn, 'username' => $db->username, 'password' => $db->password, 'charset' => 'utf8mb4']);
$other->open();
$other->createCommand("SELECT GET_LOCK('napay-btcpay-bridge', 0)")->queryScalar();
check($bridge->run()['busy'] === 1, 'Concurrent pass refused while another holds the lock');
$other->createCommand("SELECT RELEASE_LOCK('napay-btcpay-bridge')")->queryScalar();

// --- nessun segreto nel DB o nel registro
$dump = json_encode([$db->createCommand('SELECT * FROM btcpay_bridge_stores')->queryAll(), $db->createCommand('SELECT * FROM btcpay_bridge_pos_links')->queryAll(),
    $db->createCommand('SELECT * FROM logs')->queryAll()]);
check(!str_contains($dump, $key) && !str_contains($dump, $master) && !str_contains($dump, StoreWebhookSecret::derive($master, $instance, $sV1)), 'No bridge key or webhook secret in DB rows or logs');
echo "PASS {$checks} BTCPay bridge checks\n";
