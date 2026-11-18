<?php
// Explicit disposable fixture only; never use operational database credentials.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
require dirname(__DIR__, 2) . '/docker/bootstrap-common.php';
use app\components\db\V2Bootstrap as Bootstrap;
if (getenv('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_bootstrap') exit(1);
$config = napayBootstrapConfig();
$db = Bootstrap::connect($config['dsn'], $config['user'], $config['password']);
$root = dirname(__DIR__, 2);
$client = ['socket' => '/run/napay-mysql/mysqld.sock', 'user' => $config['user'], 'password' => $config['password']];
$manifest = json_decode(file_get_contents('/import/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
$checks = 0;
function check($condition, $label) {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks;
    echo 'PASS: ' . $label . "\n";
}
function rejected(callable $operation, $label) {
    try { $operation(); } catch (RuntimeException $error) { check(true, $label); return; }
    check(false, $label);
}
function resetFixture() {
    global $db;
    $db->exec('DROP DATABASE napay_test_bootstrap');
    $db->exec('CREATE DATABASE napay_test_bootstrap');
    $db->exec('USE napay_test_bootstrap');
}
function runBootstrap($path = '/import', $seconds = 120) {
    global $db, $root, $client;
    return Bootstrap::run($db, $root, $path, $client, $seconds);
}
function alteredDataset($name, callable $alter) {
    global $manifest;
    $directory = '/tmp/napay-bootstrap-' . $name;
    mkdir($directory, 0700);
    copy('/import/napay-v2.sql', $directory . '/napay-v2.sql');
    $copy = $manifest;
    $alter($directory, $copy);
    file_put_contents($directory . '/manifest.json', json_encode($copy, JSON_THROW_ON_ERROR));
    return $directory;
}
Bootstrap::ready($db, $root);
check(Bootstrap::state($db)['phase'] === 'complete', 'automatic job completed before writer readiness');
foreach ($manifest['tables'] as $table => $spec) {
    if ($table !== 'migration') check(Bootstrap::digest($db, $table, $spec['columns']) === $spec, 'original v2 content preserved: ' . $table);
}
check(Bootstrap::rows($db, "SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='activationCode'")[0]['total'] === '1', 'activationCode added on real restore');
check(Bootstrap::rows($db, 'SELECT COUNT(*) AS total FROM auth')[0]['total'] === '0', 'auth remains empty after restore');
check(runBootstrap() === 'already-complete', 'second bootstrap is a no-op');
$db->exec('SET FOREIGN_KEY_CHECKS=0');
$db->exec("INSERT INTO merchants (id,user_id,description,email,vatNumber,phone,mobile,addressStreet,addressNumberHouse,addressCity,addressZip,addressProvince,addressCountry) VALUES (99999,99999,'Fixture','fixture@example.invalid','FIXTURE','','','Fixture','','Fixture','','','')");
$db->exec('SET FOREIGN_KEY_CHECKS=1');
rejected(fn() => Bootstrap::references($db), 'source orphan references cannot pass verification');
$db->exec('DELETE FROM merchants WHERE id=99999');
$db->exec("INSERT INTO settings (description,code,value) VALUES ('Fixture only','bootstrap_fixture','new-data-after-cutover')");
check(runBootstrap() === 'already-complete', 'legitimate new application data does not trigger reimport');
check(Bootstrap::rows($db, "SELECT COUNT(*) AS total FROM settings WHERE code='bootstrap_fixture'")[0]['total'] === '1', 'post-cutover data preserved');
$corrupt = alteredDataset('corrupt', static function ($path, &$copy) { $copy['sql_sha256'] = str_repeat('0', 64); });
rejected(fn() => runBootstrap($corrupt), 'corrupt dataset rejected');
rejected(fn() => runBootstrap('/tmp/nonexistent-v2-dataset'), 'missing dataset rejected');
check(Bootstrap::state($db)['phase'] === 'complete', 'invalid artifact does not alter completed database');
$owner = Bootstrap::connect($config['dsn'], $config['user'], $config['password']);
$lock = 'napay-bootstrap-' . substr(hash('sha256', 'napay_test_bootstrap'), 0, 40);
Bootstrap::rows($owner, 'SELECT GET_LOCK(?,0) AS acquired', [$lock]);
rejected(fn() => runBootstrap(), 'concurrent bootstrap rejected by database lock');
Bootstrap::rows($owner, 'SELECT RELEASE_LOCK(?) AS released', [$lock]);
unset($owner);
resetFixture();
$db->exec('CREATE TABLE fixture_existing (id INT PRIMARY KEY)');
$db->exec('INSERT INTO fixture_existing VALUES (1)');
rejected(fn() => runBootstrap(), 'nonempty database without bootstrap identity rejected');
check(Bootstrap::state($db) === null && Bootstrap::rows($db, 'SELECT COUNT(*) AS total FROM fixture_existing')[0]['total'] === '1', 'unrecognized existing data untouched');
resetFixture();
$partial = alteredDataset('partial', static function ($path, &$copy) {
    file_put_contents($path . '/napay-v2.sql', 'CREATE TABLE fixture_partial (id INT); INVALID FIXTURE SQL;');
    $copy['sql_sha256'] = hash_file('sha256', $path . '/napay-v2.sql');
});
rejected(fn() => runBootstrap($partial), 'SQL import failure blocks bootstrap');
check(Bootstrap::state($db)['phase'] === 'failed', 'failed import persisted');
rejected(fn() => runBootstrap($partial), 'partial import is never replayed automatically');
rejected(fn() => Bootstrap::ready($db, $root), 'writer readiness fails after partial import');
resetFixture();
$slow = alteredDataset('timeout', static function ($path, &$copy) {
    file_put_contents($path . '/napay-v2.sql', 'SELECT SLEEP(5);');
    $copy['sql_sha256'] = hash_file('sha256', $path . '/napay-v2.sql');
});
$start = microtime(true);
rejected(fn() => runBootstrap($slow, 1), 'import subprocess has a bounded timeout');
check(microtime(true) - $start < 5, 'timeout does not wait for sleeping SQL to finish');
$db->exec("UPDATE _napay_bootstrap SET phase='importing' WHERE id=1");
rejected(fn() => runBootstrap($slow), 'interrupted importing state refuses automatic destructive retry');
resetFixture();
check(runBootstrap() === 'imported-and-upgraded', 'clean recovery target can restore automatically');
$db->exec("UPDATE _napay_bootstrap SET code_sha256=REPEAT('0',64) WHERE id=1");
rejected(fn() => Bootstrap::ready($db, $root), 'writer rejects schema code version mismatch');
$db->prepare('UPDATE _napay_bootstrap SET code_sha256=? WHERE id=1')->execute([Bootstrap::version($root)]);
Bootstrap::ready($db, $root);
// Upgrade of a completed database: new migrations are applied automatically, data is never reimported.
$db->exec("INSERT INTO settings (description,code,value) VALUES ('Fixture only','upgrade_fixture','kept')");
$upgradeRoot = '/tmp/napay-upgrade-root';
mkdir($upgradeRoot, 0700);
foreach (scandir($root) as $entry) {
    if (in_array($entry, ['.', '..', 'vendor', 'runtime', '.git'], true)) continue;
    exec('cp -a ' . escapeshellarg($root . '/' . $entry) . ' ' . escapeshellarg($upgradeRoot . '/'), $output, $code);
    if ($code !== 0) throw new RuntimeException('Fixture copy failed.');
}
symlink($root . '/vendor', $upgradeRoot . '/vendor');
mkdir($upgradeRoot . '/runtime', 0700);
file_put_contents($upgradeRoot . '/migrations/m991231_000000_upgrade_fixture.php', '<?php
use yii\db\Migration;
class m991231_000000_upgrade_fixture extends Migration
{
    public function safeUp() { $this->createTable("upgrade_fixture", ["id" => $this->primaryKey()]); }
    public function safeDown() { $this->dropTable("upgrade_fixture"); }
}
');
check(Bootstrap::run($db, $upgradeRoot, '/import', $client, 120) === 'upgraded', 'new migration applied on completed database');
check(in_array('upgrade_fixture', Bootstrap::tables($db), true), 'upgrade created the new table');
check(Bootstrap::rows($db, "SELECT value FROM settings WHERE code='upgrade_fixture'")[0]['value'] === 'kept', 'existing data kept by upgrade');
Bootstrap::ready($db, $upgradeRoot);
check(Bootstrap::state($db)['phase'] === 'complete', 'upgrade records new code and schema identity');
check(Bootstrap::run($db, $upgradeRoot, '/import', $client, 120) === 'already-complete', 'second start after upgrade is a no-op');
$db->exec('ALTER TABLE upgrade_fixture ADD COLUMN manual_change INT NULL');
$db->exec("UPDATE _napay_bootstrap SET code_sha256=REPEAT('0',64) WHERE id=1");
rejected(fn() => Bootstrap::run($db, $upgradeRoot, '/import', $client, 120), 'schema changed by hand blocks the upgrade');
check(Bootstrap::state($db)['phase'] === 'complete', 'refused upgrade leaves state unchanged');
$db->exec('ALTER TABLE upgrade_fixture DROP COLUMN manual_change');
foreach (['migrating' => 'interrupted', 'upgrade-failed' => 'failed'] as $phase => $label) {
    $db->prepare('UPDATE _napay_bootstrap SET phase=? WHERE id=1')->execute([$phase]);
    rejected(fn() => Bootstrap::ready($db, $upgradeRoot), "writer blocked while upgrade is $label");
    check(Bootstrap::run($db, $upgradeRoot, '/import', $client, 120) === 'upgraded', "$label upgrade retried automatically");
}
Bootstrap::ready($db, $upgradeRoot);
// back to the real code version for the next compose run (fixture table and history removed)
$db->exec('DROP TABLE upgrade_fixture');
$db->exec("DELETE FROM migration WHERE version='m991231_000000_upgrade_fixture'");
$db->exec("DELETE FROM settings WHERE code='upgrade_fixture'");
$db->prepare("UPDATE _napay_bootstrap SET code_sha256=REPEAT('0',64),schema_sha256=? WHERE id=1")->execute([Bootstrap::schemaHash($db)]);
check(runBootstrap() === 'upgraded', 'code version change without new migrations upgrades cleanly');
Bootstrap::ready($db, $root);
echo "PASS {$checks} automatic v2 bootstrap checks\n";
exit(0);
