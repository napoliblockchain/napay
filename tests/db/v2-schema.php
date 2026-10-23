<?php
// CLI integration fixture only: never read application .env or operational data.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('YII_DEBUG', false);
define('YII_ENV', 'test');
define('YII_ENABLE_ERROR_HANDLER', false);
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__, 2);
$vendor = getenv('NAPAY_TEST_VENDOR') ?: $root . '/vendor';
$dsn = getenv('NAPAY_TEST_DB_DSN') ?: '';
if (getenv('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture'
    || !preg_match('/^mysql:host=mysql;dbname=napay_test_[a-z0-9_]+$/D', $dsn)
    || !is_file($vendor . '/yiisoft/yii2/Yii.php')) {
    fwrite(STDERR, "An isolated test database and compatible test vendor are required.\n");
    exit(1);
}
$loader = require $vendor . '/autoload.php';
$loader->addPsr4('app\\', $root . '/', true);
require $vendor . '/yiisoft/yii2/Yii.php';
$app = new yii\console\Application([
    'id' => 'napay-schema-fixture', 'basePath' => $root, 'vendorPath' => $vendor,
    'runtimePath' => sys_get_temp_dir() . '/napay-schema-runtime',
    'components' => ['db' => [
        'class' => yii\db\Connection::class, 'dsn' => $dsn,
        'username' => getenv('NAPAY_TEST_DB_USER'), 'password' => getenv('NAPAY_TEST_DB_PASSWORD'),
        'charset' => 'utf8mb4',
    ]],
]);
$db = $app->db;
if ($db->schema->getTableNames() !== []) throw new RuntimeException('Fixture database must be empty.');
$checks = 0;
function check($condition, $message) {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function migrate() {
    global $app, $root;
    $controller = new yii\console\controllers\MigrateController('migrate', $app, [
        'migrationPath' => $root . '/migrations', 'interactive' => false, 'color' => false,
    ]);
    ob_start();
    try { return $controller->runAction('up'); }
    finally { ob_end_clean(); }
}
function rejected(callable $operation) {
    try { $operation(); } catch (DomainException $e) { return true; }
    return false;
}
function businessSnapshot() {
    global $db;
    $result = [];
    foreach (['users', 'privileges', 'merchants', 'user_consensus', 'settings', 'logs', 'stores', 'storesettings', 'webhooks', 'np_comuni_italiani'] as $table) {
        $rows = (new yii\db\Query())->from($table)->orderBy('id')->all($db);
        if ($table === 'users') foreach ($rows as &$row) unset($row['activationCode']);
        unset($row);
        $result[$table] = $rows;
    }
    return hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));
}

check(migrate() === 0, 'Fresh database migration succeeds');
$db->schema->refresh();
check($db->schema->getTableSchema('users')->columns['activationCode']->allowNull, 'Fresh activationCode is nullable');
check($db->schema->getTableSchema('auth') !== null, 'Fresh auth schema exists');
check((new yii\db\Query())->from('auth')->count('*', $db) == 0, 'No inferred authentication links');
check(migrate() === 0, 'Fresh migration repeated safely');

$db->createCommand()->insert('users', [
    'id' => 7, 'username' => 'fixture-user', 'email' => 'fixture@example.invalid',
    'password' => password_hash('fixture-password-only', PASSWORD_BCRYPT),
    'first_name' => 'Fixture', 'last_name' => "Unicode\u{1F680}",
    'oauth_provider' => 'fixture', 'oauth_uid' => 'fixture-uid',
    'authKey' => 'fixture-auth-key', 'accessToken' => 'fixture-access-token',
    'picture' => '/fixture.png', 'privilege_id' => 2, 'is_merchant' => 0, 'is_active' => 1,
])->execute();
$db->createCommand()->update('settings', ['value' => 'fixture-preserved-secret'], ['code' => 'webhookSecret'])->execute();
$db->createCommand()->insert('merchants', [
    'id' => 9, 'user_id' => 7, 'description' => 'Fixture merchant',
    'email' => 'merchant@example.invalid', 'vatNumber' => 'FIXTURE',
    'phone' => '000', 'mobile' => '000', 'addressStreet' => 'Fixture street',
    'addressNumberHouse' => '1', 'addressCity' => 'Fixture city', 'addressZip' => '00000',
    'addressProvince' => 'XX', 'addressCountry' => 'IT',
])->execute();
$db->createCommand()->insert('user_consensus', [
    'user_id' => 7, 'consenso_statuto' => 1, 'consenso_privacy' => 1,
    'consenso_condizioni' => 1, 'consenso_condizioni_pos' => null, 'consenso_marketing' => 0,
    'timestamp_statuto' => 1700000001, 'timestamp_privacy' => 1700000002,
    'timestamp_condizioni' => 1700000003, 'timestamp_condizioni_pos' => null,
    'timestamp_marketing' => 1700000004,
])->execute();

// Reproduce the known pre-upgrade v2 schema and historical migration name.
$db->createCommand()->dropTable('auth')->execute();
$db->createCommand()->dropColumn('users', 'activationCode')->execute();
$db->createCommand()->delete('migration', ['like', 'version', 'm261023_'])->execute();
$canonical = 'm231113_093348_create_users_table';
$historical = app\components\db\V2UsersSchema::HISTORICAL_MIGRATION;
$db->createCommand()->update('migration', ['version' => $historical], ['version' => $canonical])->execute();
$db->schema->refresh();
$before = businessSnapshot();
$nextId = $db->createCommand("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'")->queryScalar();
app\components\db\V2UsersSchema::assertHistoricalAlias($db);
check(true, 'Known historical v2 shape is accepted');

$db->createCommand()->update('migration', ['version' => 'unrecognized_users_migration'], ['version' => $historical])->execute();
check(rejected(fn() => app\components\db\V2UsersSchema::assertHistoricalAlias($db)), 'Unknown migration history rejected');
$db->createCommand()->update('migration', ['version' => $historical], ['version' => 'unrecognized_users_migration'])->execute();
$db->createCommand()->alterColumn('users', 'username', 'varchar(254) NOT NULL')->execute();
check(rejected(fn() => app\components\db\V2UsersSchema::assertHistoricalAlias($db)), 'Wrong field length rejected');
check(migrate() !== 0, 'Migration refuses incompatible restored schema');
check(!(new yii\db\Query())->from('migration')->where(['version' => $canonical])->exists($db), 'Failed alias does not change history');
check($db->schema->getTableSchema('auth', true) === null, 'Failed alias does not create later tables');
$db->createCommand()->alterColumn('users', 'username', 'varchar(255) NOT NULL')->execute();
$db->createCommand()->addColumn('users', 'unrecognized_field', 'int NULL')->execute();
check(rejected(fn() => app\components\db\V2UsersSchema::assertHistoricalAlias($db)), 'Unexpected columns rejected');
$db->createCommand()->dropColumn('users', 'unrecognized_field')->execute();
$db->createCommand('ALTER TABLE users COLLATE utf8mb4_general_ci')->execute();
check(rejected(fn() => app\components\db\V2UsersSchema::assertHistoricalAlias($db)), 'Different table collation rejected');
$db->createCommand('ALTER TABLE users COLLATE utf8mb4_unicode_ci')->execute();
$db->createCommand()->dropForeignKey('fk-user-privilege_id', 'users')->execute();
check(rejected(fn() => app\components\db\V2UsersSchema::assertHistoricalAlias($db)), 'Missing privileges FK rejected');
$db->createCommand()->addForeignKey('fk-user-privilege_id', 'users', 'privilege_id', 'privileges', 'id', 'RESTRICT', 'RESTRICT')->execute();
check(rejected(fn() => app\components\db\V2UsersSchema::assertHistoricalAlias($db)), 'Changed FK cascade rules rejected');
$db->createCommand()->dropForeignKey('fk-user-privilege_id', 'users')->execute();
$db->createCommand()->addForeignKey('fk-user-privilege_id', 'users', 'privilege_id', 'privileges', 'id', 'CASCADE', 'CASCADE')->execute();
check(migrate() === 0, 'Historical v2 migration and additive upgrades succeed');
check(businessSnapshot() === $before, 'Existing data, Unicode, roles, hashes and keys unchanged');
check($db->createCommand("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'")->queryScalar() === $nextId, 'Existing auto-increment identity preserved');
check((new yii\db\Query())->from('migration')->where(['version' => $historical])->exists($db), 'Original migration record preserved');
check((new yii\db\Query())->from('migration')->where(['version' => $canonical])->exists($db), 'Verified canonical compatibility recorded');
check($db->schema->getTableSchema('users', true)->columns['activationCode']->allowNull, 'Restored activationCode is nullable');
$historyCount = (new yii\db\Query())->from('migration')->count('*', $db);
check(migrate() === 0, 'Historical upgrade second execution succeeds');
check((new yii\db\Query())->from('migration')->count('*', $db) === $historyCount, 'No duplicate history on retry');
check(businessSnapshot() === $before, 'Second execution preserves all application data');

// Resume after additive DDL succeeded but before Yii recorded its history entry.
$db->createCommand()->delete('migration', ['version' => 'm261023_120000_add_activation_code_to_users'])->execute();
check(migrate() === 0, 'ActivationCode DDL-before-history interruption is recoverable');
check(businessSnapshot() === $before, 'Interrupted activation upgrade does not change data');
$db->createCommand()->delete('migration', ['version' => 'm261023_120000_add_activation_code_to_users'])->execute();
$db->createCommand()->alterColumn('users', 'activationCode', 'varchar(255) NULL')->execute();
check(migrate() !== 0, 'Incompatible existing activationCode fails closed');
$db->createCommand()->alterColumn('users', 'activationCode', 'varchar(256) NULL')->execute();
check(migrate() === 0, 'Approved activationCode shape can resume');

$db->createCommand()->insert('auth', ['user_id' => 7, 'source' => 'fixture', 'source_id' => 'Identity-A'])->execute();
$db->createCommand()->insert('auth', ['user_id' => 7, 'source' => 'fixture', 'source_id' => 'identity-a'])->execute();
check((new yii\db\Query())->from('auth')->count('*', $db) == 2, 'Provider identifiers remain case sensitive');
$db->createCommand()->insert('auth', ['user_id' => 7, 'source' => 'fixture', 'source_id' => 'Identity-A '])->execute();
check((new yii\db\Query())->from('auth')->count('*', $db) == 3, 'Provider identifiers preserve trailing spaces');
check(count(app\models\Users::findOne(7)->auths) === 3, 'Existing Users to Auth relation works');
try {
    $db->createCommand()->insert('auth', ['user_id' => 7, 'source' => 'fixture', 'source_id' => 'Identity-A'])->execute();
    check(false, 'Duplicate provider identity must fail');
} catch (yii\db\IntegrityException $e) { check(true, 'Duplicate provider identity rejected'); }
try {
    $db->createCommand()->insert('auth', ['user_id' => 99999, 'source' => 'fixture', 'source_id' => 'orphan'])->execute();
    check(false, 'Orphan auth identity must fail');
} catch (yii\db\IntegrityException $e) { check(true, 'Orphan auth identity rejected'); }
check(password_verify('fixture-password-only', (new yii\db\Query())->select('password')->from('users')->where(['id' => 7])->scalar($db)), 'Preserved bcrypt remains verifiable');
echo "PASS {$checks} v2 identity schema checks\n";
