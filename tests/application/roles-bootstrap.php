<?php
// Real role overlay only; disposable DB; no SQL diagnostics, secret values or production data emitted.
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__, 2) . '/docker/bootstrap-common.php';
require_once dirname(__DIR__, 2) . '/components/db/DatabaseSecret.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime' || env('NAPAY_DB_ROLE') !== 'bootstrap') exit(1);
use app\components\db\DatabaseSecret;
use app\components\db\V2Bootstrap;
$root = dirname(__DIR__, 2); $checks = 0; $stage = 'connect';
function check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException('check_failed'); ++$checks; echo "PASS: $label\n"; }
function rejected(callable $fn, string $label): void { $refused = false; try { $fn(); } catch (Throwable $e) { $refused = true; } check($refused, $label); }
try {
    check(count((new ReflectionParameter([V2Bootstrap::class, 'connect'], 'password'))->getAttributes(SensitiveParameter::class)) === 1, 'Database connection password hidden from exception traces');
    check(count((new ReflectionParameter([V2Bootstrap::class, 'run'], 'client'))->getAttributes(SensitiveParameter::class)) === 1, 'Restore client credentials hidden from exception traces');
    $config = napayBootstrapConfig();
    $admin = V2Bootstrap::connect('mysql:unix_socket=/run/napay-mysql/mysqld.sock;dbname=napay_test_runtime;charset=utf8mb4', 'root', DatabaseSecret::read('/run/secrets/mysql_root_password'), 10);
    $migrator = V2Bootstrap::connect($config['dsn'], $config['user'], $config['password'], 10);
    $web = V2Bootstrap::connect($config['dsn'], env_required('NAPAY_WEB_DB_USERNAME'), env_required('NAPAY_WEB_DB_PASSWORD'), 10);
    $worker = V2Bootstrap::connect($config['dsn'], env_required('NAPAY_DB_WORKER_USERNAME'), DatabaseSecret::read('/run/secrets/napay_db_worker_password'), 10);
    $stage = 'readiness';
    V2Bootstrap::ready($migrator, $root); check(true, 'Migrator verifies complete schema hash');
    V2Bootstrap::readyRestricted($web, $root, 'web'); check(true, 'Actual restricted web is ready');
    V2Bootstrap::readyRestricted($worker, $root, 'worker'); check(true, 'Actual restricted worker is ready');
    rejected(static fn() => V2Bootstrap::ready($web, $root), 'Web cannot inspect whole schema (restricted readiness is necessary)');
    rejected(static fn() => V2Bootstrap::ready($worker, $root), 'Worker cannot inspect whole schema');
    rejected(static fn() => V2Bootstrap::readyRestricted($migrator, $root, 'web'), 'Broad migrator privileges refused on restricted readiness');
    rejected(static fn() => V2Bootstrap::readyRestricted($web, $root, 'admin'), 'Unknown readiness role refused');
    $admin->exec("UPDATE _napay_bootstrap SET phase='upgrade-failed' WHERE id=1");
    rejected(static fn() => V2Bootstrap::readyRestricted($web, $root, 'web'), 'Web blocks incomplete bootstrap');
    rejected(static fn() => V2Bootstrap::readyRestricted($worker, $root, 'worker'), 'Worker blocks incomplete bootstrap');
    $admin->exec("UPDATE _napay_bootstrap SET phase='complete' WHERE id=1");
    $stage = 'permissions';
    foreach (['web' => $web, 'worker' => $worker] as $role => $db) {
        foreach (['UPDATE _napay_bootstrap SET phase=\'failed\' WHERE id=0', 'DELETE FROM migration WHERE 1=0', 'CREATE TABLE role_escape (id INT)'] as $n => $sql) {
            rejected(static fn() => $db->exec($sql), 'Restricted service cannot mutate marker/history/schema: ' . $role . '-' . $n);
        }
    }
    rejected(static fn() => $web->query('SELECT COUNT(*) FROM btcpay_merchant_credentials')->fetchColumn(), 'Actual web has no credential table access');
    $worker->query('SELECT account_id,kind FROM btcpay_merchant_credentials')->fetchAll(); check(true, 'Worker alone reads encrypted credentials');
    rejected(static fn() => $worker->query('SELECT dataset_sha256 FROM _napay_bootstrap')->fetchAll(), 'Worker marker columns are minimal');
    $stage = 'misconfigured grants';
    $user = env_required('NAPAY_WEB_DB_USERNAME');
    $admin->exec("GRANT SELECT ON napay_test_runtime.btcpay_merchant_credentials TO '$user'@'%'");
    rejected(static fn() => V2Bootstrap::readyRestricted($web, $root, 'web'), 'Credential grant makes web not ready');
    $admin->exec("REVOKE SELECT ON napay_test_runtime.btcpay_merchant_credentials FROM '$user'@'%'");
    $admin->exec("GRANT UPDATE ON napay_test_runtime._napay_bootstrap TO '$user'@'%'");
    rejected(static fn() => V2Bootstrap::readyRestricted($web, $root, 'web'), 'Writable marker makes web not ready');
    $admin->exec("REVOKE UPDATE ON napay_test_runtime._napay_bootstrap FROM '$user'@'%'");
    V2Bootstrap::readyRestricted($web, $root, 'web'); check(true, 'Web ready again after restoring minimum grants');
    $stage = 'secret validation';
    rejected(static fn() => DatabaseSecret::read('/run/secrets/roles_secret_missing'), 'Missing secret rejected');
    rejected(static fn() => DatabaseSecret::read('/run/secrets/../secrets/mysql_root_password'), 'Secret path traversal rejected');
    rejected(static fn() => DatabaseSecret::read('/run/secrets/roles_secret_weak'), 'Short secret rejected');
    rejected(static fn() => DatabaseSecret::read('/run/secrets/roles_secret_open'), 'Group/world-readable secret rejected');
    $stage = 'console log secrecy';
    define('YII_ENABLE_ERROR_HANDLER', false);
    require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
    $console = require $root . '/config/console.php';
    check(($console['components']['log']['targets'][0]['logVars'] ?? null) === [], 'Privileged console does not dump environment variables');
    $app = new yii\console\Application($console);
    Yii::error('Database role logging fixture.', 'roles-test');
    Yii::getLogger()->flush(true);
    $log = file_get_contents(Yii::getAlias('@runtime/logs/app.log'));
    $clean = is_string($log) && str_contains($log, 'Database role logging fixture.');
    foreach ([env_required('NAPAY_WEB_DB_PASSWORD'), env_required('DB_PASSWORD'), DatabaseSecret::read('/run/secrets/napay_db_worker_password'), DatabaseSecret::read('/run/secrets/mysql_root_password')] as $secret) $clean = $clean && !str_contains($log, $secret);
    check($clean, 'Console error log contains no web, migrator, worker or root password');
    echo "PASS $checks role bootstrap/readiness checks\n";
} catch (Throwable $error) { fwrite(STDERR, "FAIL role bootstrap at $stage (diagnostics suppressed)\n"); exit(1); }
