<?php
// B1d: actual GRANT/REVOKE enforcement, isolated DB only. Never emit PDO diagnostics or passwords.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
require $root . '/components/db/DatabaseRoles.php';
require_once $root . '/components/btcpay/StoreSettingsPayload.php';
use app\components\db\DatabaseRoles;
$checks = 0; $stage = 'connect'; $admin = null;
$users = ['napay_role_web', 'napay_role_migrator', 'napay_role_worker'];
function check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); ++$checks; echo "PASS: $label\n"; }
function denied(PDO $db, string $sql, string $label): void {
    $code = 0;
    try { $db->query($sql)->fetchAll(); } catch (PDOException $e) { $code = (int) ($e->errorInfo[1] ?? 0); }
    check(in_array($code, [1044, 1142, 1143, 1227], true), $label);
}
function grants(PDO $admin, string $user): array { $rows = $admin->query("SHOW GRANTS FOR '$user'@'%'")->fetchAll(PDO::FETCH_COLUMN); sort($rows); return $rows; }
function rejected(callable $fn, string $label): void { $refused = false; try { $fn(); } catch (RuntimeException|InvalidArgumentException|LogicException $e) { $refused = true; } check($refused, $label); }
try {
    $password = trim(file_get_contents('/run/secrets/db-role-test-root'));
    $admin = new PDO('mysql:unix_socket=/run/napay-mysql/mysqld.sock;dbname=napay_test_runtime;charset=utf8mb4', 'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]);
    unset($password);
    check($admin->query('SELECT DATABASE()')->fetchColumn() === 'napay_test_runtime', 'Disposable database confirmed');
    $stage = 'create disposable roles'; $connections = []; $passwords = [];
    foreach ($users as $user) {
        $password = bin2hex(random_bytes(24));
        $admin->exec("CREATE USER '$user'@'%' IDENTIFIED BY " . $admin->quote($password));
        $passwords[$user] = $password;
        unset($password);
    }
    // Simulate the old image's broad application grant, then actually restrict it.
    $admin->exec("GRANT ALL PRIVILEGES ON napay_test_runtime.* TO 'napay_role_web'@'%'");
    $apply = static fn() => DatabaseRoles::apply($admin, 'napay_test_runtime', ...$users);
    $stage = 'enforce roles'; $apply();
    $before = array_map(static fn($u) => grants($admin, $u), $users); $apply();
    check($before === array_map(static fn($u) => grants($admin, $u), $users), 'Role enforcement is idempotent');
    foreach ($users as $user) $connections[] = new PDO(env_required('DB_DSN') . ';charset=utf8mb4', $user, $passwords[$user], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    unset($passwords);
    [$web, $migrator, $worker] = $connections;
    foreach ($users as $n => $user) {
        $safe = true;
        foreach (grants($admin, $user) as $grant) {
            $safe = $safe && !str_contains($grant, 'WITH GRANT OPTION');
            if ($n !== 1 && !str_starts_with($grant, 'GRANT USAGE ON *.* TO ')) $safe = $safe && !str_contains($grant, '.* TO ');
        }
        check($safe, 'No grant option, no schema-wide application grant: ' . $user);
    }
    $stage = 'web boundary';
    foreach (['users', 'merchants', 'stores', 'pos_terminals', 'pos_operators', 'pos_store_operators', 'pos_operator_invitations', 'membership_payments', 'logs'] as $table) {
        $web->query("SELECT * FROM $table WHERE 1=0")->fetchAll(); check(true, 'Web reads application table: ' . $table);
    }
    foreach (['SELECT account_id FROM btcpay_merchant_credentials', 'SELECT COUNT(*) FROM btcpay_merchant_credentials', 'SHOW CREATE TABLE btcpay_merchant_credentials',
        'DELETE FROM btcpay_merchant_credentials WHERE 1=0', 'UPDATE btcpay_merchant_credentials SET lock_version=1 WHERE 1=0'] as $n => $sql) denied($web, $sql, 'Web credential boundary ' . $n);
    foreach (['migration', '_napay_bootstrap'] as $table) {
        $web->query("SELECT * FROM $table")->fetchAll(); check(true, 'Web reads bootstrap evidence: ' . $table);
        denied($web, "DELETE FROM $table WHERE 1=0", 'Web cannot change bootstrap evidence: ' . $table);
    }
    $web->query('SELECT id,instance,state FROM btcpay_merchant_accounts')->fetchAll(); check(true, 'Web reads approved state columns');
    denied($web, 'SELECT technical_email FROM btcpay_merchant_accounts', 'Web cannot read technical identity');
    denied($web, 'SELECT lease_owner FROM btcpay_provisioning_jobs', 'Web cannot read worker lease');
    denied($web, 'UPDATE btcpay_provisioning_jobs SET state=\'done\' WHERE 1=0', 'Web cannot advance worker state');
    denied($web, 'INSERT INTO btcpay_provisioning_jobs (state) VALUES (\'done\')', 'Web cannot inject outbox state');
    $merchant = (int) $admin->query('SELECT id FROM merchants LIMIT 1')->fetchColumn();
    $web->beginTransaction();
    $insert = $web->prepare('INSERT INTO btcpay_provisioning_jobs (kind, instance, merchant_id, entity_id, generation, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?)');
    $insert->execute(['merchant_account', str_repeat('a', 64), $merchant, $merchant, time(), time()]);
    $job = (int) $web->lastInsertId();
    check($web->query('SELECT state FROM btcpay_provisioning_jobs WHERE id=' . $job)->fetchColumn() === 'pending', 'Web enqueues only allowed columns with pending default');
    $web->rollBack();
    check((int) $admin->query('SELECT COUNT(*) FROM btcpay_provisioning_jobs WHERE id=' . $job)->fetchColumn() === 0, 'Outbox participates in caller transaction');
    foreach ([$web, $worker] as $n => $db) {
        denied($db, 'CREATE TABLE role_forbidden (id INT)', 'No application DDL: ' . $n);
        denied($db, 'GRANT SELECT ON users TO CURRENT_USER()', 'No application privilege escalation: ' . $n);
    }
    $stage = 'worker and migrator';
    $worker->query('SELECT * FROM btcpay_merchant_credentials')->fetchAll(); check(true, 'Worker reads encrypted credential records');
    $worker->exec('UPDATE btcpay_merchant_credentials SET lock_version=lock_version WHERE 1=0'); check(true, 'Worker updates credential records');
    denied($worker, 'DELETE FROM btcpay_provisioning_jobs WHERE 1=0', 'Worker cannot delete jobs');
    denied($worker, 'UPDATE users SET is_active=0 WHERE 1=0', 'Worker cannot change source identities');
    denied($worker, 'SELECT password FROM users', 'Worker cannot read password hashes');
    denied($worker, 'SELECT email FROM stores', 'Worker cannot read unneeded store details');
    $worker->query('SELECT id,merchant_id FROM stores')->fetchAll(); check(true, 'Worker reads minimum tenant identifiers');
    // SIP-022 passo 3: nome del negozio e impostazioni dello store BTCPay, non ID remoti né dati di derivazione
    $worker->query('SELECT description FROM stores')->fetchAll();
    $worker->query('SELECT ' . implode(',', app\components\btcpay\StoreSettingsPayload::COLUMNS) . ' FROM storesettings')->fetchAll();
    check(true, 'Worker reads the shop name and the BTCPay store settings it applies');
    denied($worker, 'SELECT bps_storeid FROM storesettings', 'Worker cannot read the v1 BTCPay store ID');
    denied($worker, 'SELECT derivationScheme FROM storesettings', 'Worker cannot read derivation data');
    denied($worker, 'UPDATE storesettings SET defaultCurrency=defaultCurrency WHERE 1=0', 'Worker cannot change store settings');
    denied($worker, 'UPDATE logs SET description=\'x\' WHERE 1=0', 'Worker cannot rewrite audit history');
    // Exercise the application's actual Yii schema lookup, not only explicit INSERT SQL.
    define('YII_ENABLE_ERROR_HANDLER', false);
    require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
    $config = require $root . '/config/console.php';
    $config['components']['log']['targets'] = [];
    $config['components']['db'] = new yii\db\Connection(['dsn' => env_required('DB_DSN'), 'pdo' => $worker]);
    $app = new yii\console\Application($config);
    check(app\components\Log::save('btcpay-provision', 'role-fixture', 'Database role fixture') === true, 'Worker writes through actual Log::save Yii schema lookup');
    check((int) $admin->query("SELECT COUNT(*) FROM logs WHERE controller='btcpay-provision' AND action='role-fixture' AND description='Database role fixture'")->fetchColumn() === 1, 'Worker audit entry actually persisted');
    $migrator->exec('CREATE TABLE role_owned (id INT PRIMARY KEY)');
    $migrator->exec('DROP TABLE role_owned'); check(true, 'Migrator alone owns schema DDL');
    $migrator->query('SELECT * FROM btcpay_merchant_credentials')->fetchAll(); check(true, 'Migrator can verify complete schema');
    $stage = 'preflight and scope';
    $admin->exec('CREATE TABLE role_unknown (id INT PRIMARY KEY)'); $apply();
    denied($web, 'SELECT * FROM role_unknown', 'New unknown tables are denied by default'); $admin->exec('DROP TABLE role_unknown');
    $before = grants($admin, $users[0]);
    rejected(static fn() => DatabaseRoles::apply($admin, 'napay_test_runtime', $users[0], $users[0], $users[2]), 'Duplicate account assignment refused');
    rejected(static fn() => DatabaseRoles::apply($admin, 'napay_test_runtime', "napay_bad'", $users[1], $users[2]), 'Malformed account refused');
    rejected(static fn() => DatabaseRoles::apply($admin, 'wrong_database', ...$users), 'Wrong database refused');
    $admin->beginTransaction(); rejected($apply, 'Caller transaction refused'); $admin->rollBack();
    $admin->exec('CREATE DATABASE napay_roles_foreign');
    $admin->exec("GRANT SELECT ON napay_roles_foreign.* TO 'napay_role_worker'@'%'");
    rejected($apply, 'Foreign database grant refused before revocation');
    check(grants($admin, $users[0]) === $before, 'Failed preflight leaves web grants unchanged');
    $admin->exec("REVOKE SELECT ON napay_roles_foreign.* FROM 'napay_role_worker'@'%'");
    $admin->exec("GRANT PROCESS ON *.* TO 'napay_role_worker'@'%'");
    rejected($apply, 'Global privilege refused before revocation');
    check(grants($admin, $users[0]) === $before, 'Global privilege rejection leaves web grants unchanged');
    $admin->exec("REVOKE PROCESS ON *.* FROM 'napay_role_worker'@'%'");
    $apply();
    $admin->exec("ALTER USER 'napay_role_worker'@'%' ACCOUNT LOCK");
    rejected($apply, 'Locked role account refused before revocation');
    check(grants($admin, $users[0]) === $before, 'Locked account leaves web grants unchanged');
    $admin->exec("ALTER USER 'napay_role_worker'@'%' ACCOUNT UNLOCK");
    $admin->exec('ALTER TABLE member_profiles RENAME COLUMN denomination TO role_hidden_denomination');
    rejected($apply, 'Missing approved column refused before revocation');
    check(grants($admin, $users[0]) === $before, 'Missing column leaves web grants unchanged');
    $admin->exec('ALTER TABLE member_profiles RENAME COLUMN role_hidden_denomination TO denomination');
    $apply();
    // Underscores in schema-level GRANT must not authorize similarly named databases.
    $admin->exec('CREATE DATABASE napayXtestXruntime');
    denied($migrator, 'SELECT * FROM napayXtestXruntime.nonexistent', 'Migrator database underscores are literal, not wildcards');
    check(grants($admin, $users[0]) === $before, 'Valid policy still replays after rejected inputs');
    echo "PASS $checks database role enforcement checks\n";
} catch (Throwable $error) {
    fwrite(STDERR, "FAIL database roles at $stage (diagnostics suppressed)\n"); $failed = true;
} finally {
    if ($admin instanceof PDO) {
        foreach ($users as $user) try { $admin->exec("DROP USER IF EXISTS '$user'@'%'"); } catch (Throwable $ignored) { }
        foreach (['napay_roles_foreign', 'napayXtestXruntime'] as $database) try { $admin->exec("DROP DATABASE IF EXISTS `$database`"); } catch (Throwable $ignored) { }
    }
}
exit(empty($failed) ? 0 : 1);
