<?php
// SIP-022 B1b: schema, vincoli e privilegi del provisioning BTCPay, solo sul DB usa-e-getta del runner.
// stdin: password degli utenti usa-e-getta web e worker (una per riga), mai negli argomenti.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
$app = new yii\console\Application(require $root . '/config/console.php');
$db = $app->db;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);
[$webPassword, $workerPassword] = array_map('trim', [fgets(STDIN), fgets(STDIN)]);
if (!preg_match('/^[0-9a-f]{48}$/D', $webPassword) || !preg_match('/^[0-9a-f]{48}$/D', $workerPassword)) exit(1);
require $root . '/migrations/m261113_120000_create_btcpay_provisioning_schema.php';
use app\components\btcpay\provisioning\CredentialEnvelope;
use app\components\identity\AccountEligibility;

$checks = 0;
function check($value, string $label): void {
    global $checks;
    if (!$value) throw new RuntimeException($label);
    ++$checks; echo 'PASS: ' . $label . "\n";
}
/** Solo l'errore MySQL atteso conta come rifiuto; qualunque altro errore fa fallire la suite. */
function rejects(callable $operation, array $codes, string $label): void {
    $code = 0;
    try { $operation(); } catch (yii\db\Exception $e) { $code = (int) ($e->errorInfo[1] ?? 0); if (!in_array($code, $codes, true)) throw $e; }
    check(in_array($code, $codes, true), $label);
}
function refused(callable $run): bool {
    try { $run(); } catch (RuntimeException $e) { return str_contains($e->getMessage(), 'incompatible'); }
    return false;
}
const DENIED = [1142]; const COLUMN_DENIED = [1143]; const CHECK = [3819]; const DUPLICATE = [1062]; const NO_PARENT = [1452]; const HAS_CHILD = [1451];
$tables = ['btcpay_merchant_accounts', 'btcpay_merchant_credentials', 'btcpay_store_links', 'btcpay_pos_links', 'btcpay_provisioning_jobs'];
$run = static function () use ($db): void { (new m261113_120000_create_btcpay_provisioning_schema(['db' => $db, 'compact' => true]))->up(); };
$shape = static function () use ($db, $tables): array {
    $result = [];
    // Un ALTER legittimo fa riscrivere a MySQL gli introducer dei letterali nei CHECK: non sono una differenza di schema.
    foreach (array_merge($tables, ['pos_terminals', 'stores']) as $table) $result[$table] = preg_replace("/_(?:utf8mb4|ascii)'/", "'", $db->createCommand('SHOW CREATE TABLE ' . $table)->queryOne());
    return $result;
};

// --- Migration: applicata dal bootstrap, nessun dato, ripresa identica, deriva rifiutata ---
foreach ($tables as $table) check((int) $db->createCommand('SELECT COUNT(*) FROM ' . $table)->queryScalar() === 0, 'No backfill: ' . $table);
$before = $shape();
$run();
check($before === $shape(), 'Exact replay changes nothing (B1 tables, pos_terminals, stores)');
$drifts = [
    'Extra payload column' => ['ALTER TABLE btcpay_provisioning_jobs ADD COLUMN payload TEXT NULL', 'ALTER TABLE btcpay_provisioning_jobs DROP COLUMN payload'],
    'BINARY nonce (padding)' => ['ALTER TABLE btcpay_merchant_credentials MODIFY secret_nonce BINARY(24) NULL', 'ALTER TABLE btcpay_merchant_credentials MODIFY secret_nonce VARBINARY(24) NULL'],
    'Changed generated current marker' => ["ALTER TABLE btcpay_merchant_credentials MODIFY current_marker TINYINT GENERATED ALWAYS AS (CASE WHEN state = 'verified' THEN 1 END) STORED NULL", "ALTER TABLE btcpay_merchant_credentials MODIFY current_marker TINYINT GENERATED ALWAYS AS (CASE WHEN state = 'current' THEN 1 END) STORED NULL"],
    'Unenforced CHECK' => ['ALTER TABLE btcpay_store_links ALTER CHECK `ck-btcpay-stores-synced` NOT ENFORCED', 'ALTER TABLE btcpay_store_links ALTER CHECK `ck-btcpay-stores-synced` ENFORCED'],
    'Other e-mail domain' => ["ALTER TABLE btcpay_merchant_accounts DROP CHECK `ck-btcpay-accounts-email`, ADD CONSTRAINT `ck-btcpay-accounts-email` CHECK (technical_email = CONCAT('m-', ref, '@example.invalid'))", "ALTER TABLE btcpay_merchant_accounts DROP CHECK `ck-btcpay-accounts-email`, ADD CONSTRAINT `ck-btcpay-accounts-email` CHECK (technical_email = CONCAT('m-', ref, '@btcpay-merchants.jamprojects.it'))"],
    'Case-changed KEK pattern' => ["ALTER TABLE btcpay_merchant_credentials DROP CHECK `ck-btcpay-credentials-kek`, ADD CONSTRAINT `ck-btcpay-credentials-kek` CHECK (kek_version IS NULL OR REGEXP_LIKE(kek_version, '^[a-za-z0-9_-]{1,64}$', 'c'))", "ALTER TABLE btcpay_merchant_credentials DROP CHECK `ck-btcpay-credentials-kek`, ADD CONSTRAINT `ck-btcpay-credentials-kek` CHECK (kek_version IS NULL OR REGEXP_LIKE(kek_version, '^[A-Za-z0-9_-]{1,64}$', 'c'))"],
    'Missing FK' => ['ALTER TABLE btcpay_provisioning_jobs DROP FOREIGN KEY `fk-btcpay-jobs-requester`', 'ALTER TABLE btcpay_provisioning_jobs ADD CONSTRAINT `fk-btcpay-jobs-requester` FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT'],
    'Extra index' => ['CREATE INDEX b1_extra ON btcpay_pos_links (state)', 'DROP INDEX b1_extra ON btcpay_pos_links'],
];
foreach ($drifts as $label => [$break, $restore]) {
    $db->createCommand($break)->execute();
    $rejected = refused($run);
    $db->createCommand($restore)->execute();
    check($rejected, 'Drift refused, never repaired: ' . $label);
}
// L'indice terminali regge una FK B1: si isola con una rinomina, mai con foreign_key_checks=0.
$db->createCommand('ALTER TABLE pos_terminals RENAME INDEX `uq-btcpay-terminals-scope` TO b1_isolated')->execute();
$db->createCommand('CREATE UNIQUE INDEX `uq-btcpay-terminals-scope` ON pos_terminals (id, merchant_id, store_id)')->execute();
$rejected = refused($run);
$db->createCommand('DROP INDEX `uq-btcpay-terminals-scope` ON pos_terminals')->execute();
$db->createCommand('ALTER TABLE pos_terminals RENAME INDEX b1_isolated TO `uq-btcpay-terminals-scope`')->execute();
check($rejected, 'Reserved terminal index name with other column order refused');
// Interruzione simulata: le tabelle senza figli mancano, una esistente è alterata -> nessun DDL prima del rifiuto.
$db->createCommand('DROP TABLE btcpay_provisioning_jobs, btcpay_pos_links')->execute();
$db->createCommand('ALTER TABLE btcpay_store_links ADD COLUMN drift INT NULL')->execute();
$rejected = refused($run);
check($rejected && $db->schema->getTableSchema('btcpay_provisioning_jobs', true) === null && $db->schema->getTableSchema('btcpay_pos_links', true) === null, 'Preflight refuses before any DDL');
$db->createCommand('ALTER TABLE btcpay_store_links DROP COLUMN drift')->execute();
$run();
check($before === $shape(), 'Partial DDL resumed to the exact schema');
ob_start(); $denied = (new m261113_120000_create_btcpay_provisioning_schema(['db' => $db, 'compact' => true]))->down(); ob_end_clean();
check($denied === false, 'Destructive rollback disabled');

// --- Vincoli: tenant, istanza, credenziali, outbox ---
$merchants = array_column($db->createCommand("SELECT description,id FROM merchants WHERE description IN ('Runtime company merchant','Runtime company other')")->queryAll(), 'id', 'description');
$a = (int) $merchants['Runtime company merchant']; $b = (int) $merchants['Runtime company other'];
$admin = (int) $db->createCommand("SELECT id FROM users WHERE username='runtime-admin'")->queryScalar();
$store = ['description' => 'B1 fixture', 'email' => 'fixture@example.invalid', 'phone' => '', 'mobile' => '', 'addressStreet' => '', 'addressNumberHouse' => '', 'addressCity' => '', 'addressZip' => '', 'addressProvince' => '', 'addressCountry' => ''];
$stores = []; $terminals = [];
foreach (['a1' => $a, 'a2' => $a, 'b1' => $b] as $key => $merchant) {
    $db->createCommand()->insert('stores', ['merchant_id' => $merchant] + $store)->execute();
    $stores[$key] = (int) $db->lastInsertID;
    $terminal = new app\models\PosTerminals(['merchant_id' => $merchant, 'store_id' => $stores[$key], 'name' => 'B1 ' . $key]);
    if (!$terminal->save()) throw new RuntimeException('Terminal fixture unavailable.');
    $terminals[$key] = (int) $terminal->id;
}
$now = time();
$i1 = hash('sha256', 'https://btcpay.regtest.invalid'); $i2 = hash('sha256', 'https://btcpay.other.invalid');
$account = static function (string $instance, int $merchant, array $over = []) use ($db, $now): int {
    $ref = bin2hex(random_bytes(16));
    $db->createCommand()->insert('btcpay_merchant_accounts', array_replace(['instance' => $instance, 'merchant_id' => $merchant, 'ref' => $ref,
        'technical_email' => 'm-' . $ref . '@btcpay-merchants.jamprojects.it', 'created_at' => $now, 'updated_at' => $now], $over))->execute();
    return (int) $db->lastInsertID;
};
$accA = $account($i1, $a); $accB = $account($i1, $b); $accA2 = $account($i2, $a);
check($db->createCommand('SELECT state,version,key_generations_used FROM btcpay_merchant_accounts WHERE id=:id', [':id' => $accA])->queryOne() === ['state' => 'pending', 'version' => 0, 'key_generations_used' => 0], 'Account defaults: pending, version 0, no generations');
rejects(fn() => $account($i1, $a), DUPLICATE, 'One account per merchant and instance');
$ref = bin2hex(random_bytes(16));
foreach ([
    'Technical e-mail must match ref and domain' => ['ref' => $ref, 'technical_email' => 'm-' . $ref . '@example.invalid'],
    'Uppercase ref refused' => ['ref' => strtoupper($ref), 'technical_email' => 'm-' . strtoupper($ref) . '@btcpay-merchants.jamprojects.it'],
    'Synced account requires remote user' => ['state' => 'synced'],
    'Unknown account state refused' => ['state' => 'active'],
    'Generation counter bounded' => ['key_generations_used' => 1001],
    'Free-text error refused' => ['last_error_code' => 'HTTP 500: server said no'],
    'Negative version refused' => ['version' => -1],
] as $label => $over) rejects(fn() => $account($i2, $b, $over), CHECK, $label);
rejects(fn() => $account(strtoupper($i2), $b), CHECK, 'Instance must be lowercase SHA-256 hex');
rejects(fn() => $account($i2, 2147483647), NO_PARENT, 'Account for missing merchant refused');
$db->createCommand()->update('btcpay_merchant_accounts', ['remote_user_id' => 'remote-a', 'state' => 'synced'], ['id' => $accA])->execute();
rejects(fn() => $db->createCommand()->update('btcpay_merchant_accounts', ['remote_user_id' => 'remote-a'], ['id' => $accB])->execute(), DUPLICATE, 'Remote user maps to one account per instance');

$kek = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
$credential = static function (int $accountId, int $merchant, string $instance, string $kind, int $generation, string $state, array $over = []) use ($db, $now, $kek): void {
    $context = ['account_id' => $accountId, 'merchant_id' => $merchant, 'instance' => $instance, 'kind' => $kind, 'generation' => $generation];
    $e = CredentialEnvelope::encrypt('fixture-secret-' . $generation, $context, 'k1', $kek);
    $row = ['account_id' => $accountId, 'instance' => $instance, 'merchant_id' => $merchant, 'kind' => $kind, 'generation' => $generation, 'state' => $state,
        'envelope_version' => $e['version'], 'kek_version' => $e['kek_version'], 'secret_nonce' => base64_decode($e['secret_nonce']), 'ciphertext' => base64_decode($e['ciphertext']),
        'dek_nonce' => base64_decode($e['dek_nonce']), 'wrapped_dek' => base64_decode($e['wrapped_dek']),
        'key_fingerprint' => $kind === 'api_key' ? hash('sha256', 'fixture-key-' . $generation) : null, 'created_at' => $now, 'updated_at' => $now];
    $db->createCommand()->insert('btcpay_merchant_credentials', array_replace($row, $over))->execute();
};
$credential($accA, $a, $i1, 'api_key', 1, 'current');
$stored = $db->createCommand("SELECT * FROM btcpay_merchant_credentials WHERE account_id=:a AND kind='api_key' AND generation=1", [':a' => $accA])->queryOne();
$envelope = ['version' => (int) $stored['envelope_version'], 'kek_version' => $stored['kek_version']];
foreach (['secret_nonce', 'ciphertext', 'dek_nonce', 'wrapped_dek'] as $field) $envelope[$field] = base64_encode($stored[$field]);
check(CredentialEnvelope::decrypt($envelope, ['account_id' => $accA, 'merchant_id' => $a, 'instance' => $i1, 'kind' => 'api_key', 'generation' => 1], ['k1' => $kek]) === 'fixture-secret-1', 'B1a envelope stored in binary columns and decrypted unchanged');
rejects(fn() => $credential($accA, $a, $i1, 'api_key', 2, 'current'), DUPLICATE, 'Second current key for the same account refused');
$credential($accA, $a, $i1, 'api_key', 2, 'pending'); $credential($accA, $a, $i1, 'api_key', 3, 'retiring'); $credential($accA, $a, $i1, 'api_key', 4, 'verified');
$credential($accA, $a, $i1, 'onboarding_password', 1, 'current');
check((int) $db->createCommand('SELECT COUNT(*) FROM btcpay_merchant_credentials WHERE account_id=:a', [':a' => $accA])->queryScalar() === 5, 'Pending, verified and retiring coexist with one current per kind');
rejects(fn() => $db->createCommand()->update('btcpay_merchant_credentials', ['state' => 'current'], ['account_id' => $accA, 'kind' => 'api_key', 'generation' => 2])->execute(), DUPLICATE, 'Promotion to current blocked while another is current');
$db->createCommand()->update('btcpay_merchant_credentials', ['state' => 'revoked', 'envelope_version' => null, 'kek_version' => null, 'secret_nonce' => null, 'ciphertext' => null, 'dek_nonce' => null, 'wrapped_dek' => null, 'key_fingerprint' => null], ['account_id' => $accA, 'kind' => 'api_key', 'generation' => 3])->execute();
check($db->createCommand("SELECT state,ciphertext,wrapped_dek,key_fingerprint FROM btcpay_merchant_credentials WHERE account_id=:a AND kind='api_key' AND generation=3", [':a' => $accA])->queryOne() === ['state' => 'revoked', 'ciphertext' => null, 'wrapped_dek' => null, 'key_fingerprint' => null], 'Revocation clears the envelope and keeps the generation row');
rejects(fn() => $credential($accA, $a, $i1, 'api_key', 5, 'revoked'), CHECK, 'Revoked row with envelope refused');
foreach ([
    'Incomplete envelope refused' => ['ciphertext' => null],
    'Missing wrapped DEK refused' => ['wrapped_dek' => null],
    'Short secret nonce refused (no padding)' => ['secret_nonce' => random_bytes(23)],
    'Short DEK nonce refused' => ['dek_nonce' => random_bytes(23)],
    'Short wrapped DEK refused' => ['wrapped_dek' => random_bytes(47)],
    'Ciphertext without tag refused' => ['ciphertext' => random_bytes(16)],
    'Unknown envelope version refused' => ['envelope_version' => 2],
    'Invalid KEK version refused' => ['kek_version' => 'k 1'],
    'API key without fingerprint refused' => ['key_fingerprint' => null],
    'Malformed fingerprint refused' => ['key_fingerprint' => str_repeat('G', 64)],
    'Unknown credential state refused' => ['state' => 'active'],
    'Generation starts at 1' => ['generation' => 0],
] as $label => $over) rejects(fn() => $credential($accA, $a, $i1, 'api_key', 6, 'pending', $over), CHECK, $label);
rejects(fn() => $credential($accA, $a, $i1, 'api_key', 6, 'pending', ['ciphertext' => random_bytes(16401)]), [1406, 3819], 'Oversized ciphertext refused');
rejects(fn() => $credential($accA, $a, $i1, 'onboarding_password', 2, 'pending', ['key_fingerprint' => hash('sha256', 'x')]), CHECK, 'Password row cannot carry a key fingerprint');
rejects(fn() => $credential($accA, $a, $i1, 'api_key', 8, 'pending', ['kind' => 'webhook_secret', 'key_fingerprint' => null]), CHECK, 'Unknown credential kind refused');
rejects(fn() => $credential($accA, $b, $i1, 'api_key', 7, 'pending'), NO_PARENT, 'Credential cannot claim another merchant');
rejects(fn() => $credential($accA, $a, $i2, 'api_key', 7, 'pending'), NO_PARENT, 'Credential cannot claim another instance');

$link = static function (string $table, array $row) use ($db, $now): int {
    $db->createCommand()->insert($table, $row + ['marker' => bin2hex(random_bytes(16)), 'created_at' => $now, 'updated_at' => $now])->execute();
    return (int) $db->lastInsertID;
};
$storeLink = fn(array $over = []) => $link('btcpay_store_links', array_replace(['instance' => $i1, 'account_id' => $accA, 'merchant_id' => $a, 'store_id' => $stores['a1']], $over));
$sa = $storeLink();
rejects(fn() => $storeLink(['store_id' => $stores['b1']]), NO_PARENT, 'Store of another merchant refused');
rejects(fn() => $storeLink(['merchant_id' => $b, 'store_id' => $stores['b1']]), NO_PARENT, 'Account of another merchant refused for store link');
rejects(fn() => $storeLink(['instance' => $i2, 'store_id' => $stores['a2']]), NO_PARENT, 'Account of another instance refused for store link');
rejects(fn() => $storeLink(), DUPLICATE, 'One BTCPay store per Napay store and instance');
rejects(fn() => $storeLink(['store_id' => $stores['a2'], 'state' => 'synced']), CHECK, 'Synced store link requires remote store');
rejects(fn() => $storeLink(['store_id' => $stores['a2'], 'marker' => str_repeat('Z', 32)]), CHECK, 'Malformed store marker refused');
$storeLink(['instance' => $i2, 'account_id' => $accA2]);
check((int) $db->createCommand('SELECT COUNT(DISTINCT instance) FROM btcpay_store_links WHERE store_id=:s', [':s' => $stores['a1']])->queryScalar() === 2, 'Same Napay store mapped separately on another instance');
$db->createCommand()->update('btcpay_store_links', ['remote_store_id' => 'remote-store-a', 'state' => 'synced'], ['id' => $sa])->execute();
$sa2 = $storeLink(['store_id' => $stores['a2']]);
rejects(fn() => $db->createCommand()->update('btcpay_store_links', ['remote_store_id' => 'remote-store-a'], ['id' => $sa2])->execute(), DUPLICATE, 'Remote store maps to one store link per instance');
$sb = $link('btcpay_store_links', ['instance' => $i1, 'account_id' => $accB, 'merchant_id' => $b, 'store_id' => $stores['b1']]);
$posLink = fn(array $over = []) => $link('btcpay_pos_links', array_replace(['instance' => $i1, 'store_link_id' => $sa, 'merchant_id' => $a, 'store_id' => $stores['a1'], 'terminal_id' => $terminals['a1']], $over));
$pa = $posLink();
rejects(fn() => $posLink(['terminal_id' => $terminals['a2']]), NO_PARENT, 'Terminal of another store refused');
rejects(fn() => $posLink(['terminal_id' => $terminals['b1']]), NO_PARENT, 'Terminal of another merchant refused');
rejects(fn() => $posLink(['store_link_id' => $sb, 'terminal_id' => $terminals['b1']]), NO_PARENT, 'Store link of another merchant refused');
rejects(fn() => $posLink(['instance' => $i2]), NO_PARENT, 'Store link of another instance refused');
rejects(fn() => $posLink(), DUPLICATE, 'One BTCPay app per terminal and instance');
rejects(fn() => $posLink(['store_link_id' => $sa2, 'store_id' => $stores['a2'], 'terminal_id' => $terminals['a2'], 'state' => 'synced']), CHECK, 'Synced POS link requires remote app');
foreach ([['btcpay_merchant_accounts', ['id' => $accA]], ['btcpay_store_links', ['id' => $sa]], ['stores', ['id' => $stores['a1']]], ['pos_terminals', ['id' => $terminals['a1']]], ['merchants', ['id' => $a]]] as [$table, $where]) {
    rejects(fn() => $db->createCommand()->delete($table, $where)->execute(), HAS_CHILD, 'Referenced parent deletion blocked: ' . $table);
}
rejects(fn() => $db->createCommand()->update('pos_terminals', ['store_id' => $stores['a2']], ['id' => $terminals['a1']])->execute(), HAS_CHILD, 'Mapped terminal cannot move to another store');

$job = static function (array $over = []) use ($db, $now, $i1, $a): int {
    $db->createCommand()->insert('btcpay_provisioning_jobs', array_replace(['kind' => 'merchant_account', 'instance' => $i1, 'merchant_id' => $a, 'entity_id' => $a, 'created_at' => $now, 'updated_at' => $now], $over))->execute();
    return (int) $db->lastInsertID;
};
$j = $job();
check($db->createCommand('SELECT state,generation,attempts,next_attempt_at,version FROM btcpay_provisioning_jobs WHERE id=:id', [':id' => $j])->queryOne() === ['state' => 'pending', 'generation' => 1, 'attempts' => 0, 'next_attempt_at' => 0, 'version' => 0], 'Job defaults: pending generation 1, no attempts');
rejects(fn() => $job(), DUPLICATE, 'Duplicate job (kind, instance, entity, generation) refused');
$retry = $job(['generation' => 2, 'requested_by' => $admin]);
check((int) $db->createCommand('SELECT requested_by FROM btcpay_provisioning_jobs WHERE id=:id', [':id' => $retry])->queryScalar() === $admin, 'Retry is a new generation with requesting admin');
$uuid = '00000000-0000-4000-8000-000000000001';
foreach ([
    'In-flight state without operation refused' => ['state' => 'in_flight'],
    'Operation without owner refused' => ['in_flight_op' => 'create_user', 'in_flight_started_at' => $now],
    'Unknown remote operation refused' => ['in_flight_op' => 'delete_store', 'in_flight_started_at' => $now, 'in_flight_owner' => $uuid],
    'Partial lease refused' => ['lease_owner' => $uuid],
    'Malformed lease owner refused' => ['lease_owner' => 'worker', 'lease_until' => $now],
    'Unknown job kind refused' => ['kind' => 'payout'],
    'Unknown job state refused' => ['state' => 'queued'],
    'Attempts bounded' => ['attempts' => 1001],
    'Entity id must be positive' => ['entity_id' => 0],
] as $label => $over) rejects(fn() => $job(['generation' => 9] + $over), CHECK, $label);
$flight = $job(['generation' => 3, 'state' => 'in_flight', 'in_flight_op' => 'create_user', 'in_flight_started_at' => $now, 'in_flight_owner' => $uuid, 'lease_owner' => $uuid, 'lease_until' => $now + 60]);
check($db->createCommand('SELECT state FROM btcpay_provisioning_jobs WHERE id=:id', [':id' => $flight])->queryScalar() === 'in_flight', 'Complete in-flight checkpoint with lease accepted');
rejects(fn() => $job(['generation' => 9, 'requested_by' => 2147483647]), NO_PARENT, 'Requester must be an existing user');

// --- Privilegi: utenti usa-e-getta creati dal runner con la matrice proposta ---
$connect = static function (string $user, string $password): yii\db\Connection {
    $connection = new yii\db\Connection(['dsn' => env('DB_DSN'), 'username' => $user, 'password' => $password, 'charset' => 'utf8mb4']);
    $connection->open();
    return $connection;
};
$web = $connect('napay_b1b_web', $webPassword); $worker = $connect('napay_b1b_worker', $workerPassword);
// Con soli privilegi a colonna Yii insert()/update() falliscono: leggono lo schema con SHOW CREATE TABLE, negato. SQL esplicito.
$raw = static function (yii\db\Connection $connection, string $verb, string $table, array $row): int {
    $placeholders = []; $params = [];
    foreach (array_keys($row) as $n => $column) { $placeholders[] = ':p' . $n; $params[':p' . $n] = $row[$column]; }
    $columns = implode(', ', array_keys($row));
    return $connection->createCommand($verb === 'INSERT' ? "INSERT INTO $table ($columns) VALUES (" . implode(', ', $placeholders) . ')'
        : "UPDATE $table SET " . implode(', ', array_map(fn($c, $p) => "$c = $p", array_keys($row), $placeholders)) . ' WHERE 1 = 0', $params)->execute();
};
rejects(fn() => $web->createCommand()->insert('btcpay_provisioning_jobs', ['kind' => 'store_link'])->execute(), DENIED, 'Yii schema lookup refused with column-only grants (enqueue uses explicit SQL)');
unset($webPassword, $workerPassword);
foreach (['web' => $web, 'worker' => $worker] as $name => $connection) {
    $narrow = true;
    foreach ($connection->createCommand('SHOW GRANTS')->queryColumn() as $grant) {
        // Solo USAGE globale; il resto per tabella o colonna, mai sullo schema intero né con GRANT OPTION.
        $narrow = $narrow && !str_contains($grant, 'GRANT OPTION') && (preg_match('/^GRANT USAGE ON \*\.\* TO /', $grant) === 1 || preg_match('/ ON `napay_test_runtime`\.`[a-z_]+` TO /', $grant) === 1);
    }
    check($narrow, 'Only table/column privileges, no GRANT OPTION: ' . $name);
    rejects(fn() => $connection->createCommand('GRANT SELECT ON btcpay_merchant_credentials TO CURRENT_USER()')->execute(), [1044, 1142], 'Cannot grant privileges: ' . $name);
}
rejects(fn() => $web->createCommand('SELECT account_id FROM btcpay_merchant_credentials')->queryAll(), DENIED, 'Web cannot read credentials');
rejects(fn() => $web->createCommand('SELECT COUNT(*) FROM btcpay_merchant_credentials')->queryScalar(), DENIED, 'Web cannot even count credentials');
rejects(fn() => $raw($web, 'INSERT', 'btcpay_merchant_credentials', ['account_id' => $accA]), DENIED, 'Web cannot write credentials');
check(count($web->createCommand('SELECT id,instance,merchant_id,state,last_error_code,updated_at FROM btcpay_merchant_accounts')->queryAll()) === 3, 'Web reads account state columns');
rejects(fn() => $web->createCommand('SELECT technical_email FROM btcpay_merchant_accounts')->queryAll(), COLUMN_DENIED, 'Web cannot read technical e-mail');
rejects(fn() => $web->createCommand('SELECT remote_user_id FROM btcpay_merchant_accounts')->queryAll(), COLUMN_DENIED, 'Web cannot read remote user id');
rejects(fn() => $web->createCommand('SELECT marker FROM btcpay_store_links')->queryAll(), COLUMN_DENIED, 'Web cannot read store markers');
rejects(fn() => $web->createCommand('SELECT remote_app_id FROM btcpay_pos_links')->queryAll(), COLUMN_DENIED, 'Web cannot read remote app ids');
rejects(fn() => $web->createCommand('SELECT lease_owner FROM btcpay_provisioning_jobs')->queryAll(), COLUMN_DENIED, 'Web cannot read job leases');
check(count($web->createCommand('SELECT id,instance,merchant_id,store_id,state,last_error_code,updated_at FROM btcpay_store_links')->queryAll()) === 4
    && count($web->createCommand('SELECT id,instance,merchant_id,store_id,terminal_id,state,last_error_code,updated_at FROM btcpay_pos_links')->queryAll()) === 1, 'Web reads link state columns');
$webJob = ['kind' => 'store_link', 'instance' => $i1, 'merchant_id' => $a, 'entity_id' => $stores['a2'], 'generation' => 1, 'requested_by' => $admin, 'created_at' => $now, 'updated_at' => $now];
$raw($web, 'INSERT', 'btcpay_provisioning_jobs', $webJob);
check($db->createCommand("SELECT state,version,lease_owner FROM btcpay_provisioning_jobs WHERE kind='store_link' AND entity_id=:e", [':e' => $stores['a2']])->queryOne() === ['state' => 'pending', 'version' => 0, 'lease_owner' => null], 'Web enqueues on allowed columns; row starts pending, version 0, no lease');
check(count($web->createCommand('SELECT id,kind,instance,merchant_id,entity_id,generation,state,attempts,next_attempt_at,last_error_code,created_at,updated_at FROM btcpay_provisioning_jobs WHERE instance=:i AND merchant_id=:m', [':i' => $i1, ':m' => $a])->queryAll()) >= 1, 'Web reads job status by instance and merchant');
foreach (['state' => 'done', 'lease_owner' => $uuid, 'version' => 7, 'attempts' => 5, 'in_flight_op' => 'create_store'] as $column => $value) {
    rejects(fn() => $raw($web, 'INSERT', 'btcpay_provisioning_jobs', array_replace($webJob, ['generation' => 2, $column => $value])), COLUMN_DENIED, 'Web cannot set job column: ' . $column);
}
foreach ($tables as $table) {
    if ($table === 'btcpay_merchant_credentials') continue;
    rejects(fn() => $web->createCommand('UPDATE ' . $table . ' SET updated_at = updated_at')->execute(), DENIED, 'Web cannot update ' . $table);
    rejects(fn() => $web->createCommand('DELETE FROM ' . $table)->execute(), DENIED, 'Web cannot delete from ' . $table);
}
rejects(fn() => $raw($web, 'INSERT', 'btcpay_merchant_accounts', ['instance' => $i2, 'merchant_id' => $b]), DENIED, 'Web cannot create technical accounts');
// Outbox nella stessa transazione della scrittura Napay che la origina.
$enqueueStore = static function (bool $commit) use ($web, $raw, $store, $a, $i1, $now): int {
    $transaction = $web->beginTransaction();
    $web->createCommand()->insert('stores', ['merchant_id' => $a] + $store)->execute();
    $id = (int) $web->lastInsertID;
    $raw($web, 'INSERT', 'btcpay_provisioning_jobs', ['kind' => 'store_link', 'instance' => $i1, 'merchant_id' => $a, 'entity_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
    $commit ? $transaction->commit() : $transaction->rollBack();
    return $id;
};
$count = fn(int $id) => [(int) $db->createCommand('SELECT COUNT(*) FROM stores WHERE id=:id', [':id' => $id])->queryScalar(), (int) $db->createCommand("SELECT COUNT(*) FROM btcpay_provisioning_jobs WHERE kind='store_link' AND entity_id=:id", [':id' => $id])->queryScalar()];
check($count($enqueueStore(false)) === [0, 0], 'Rollback discards both the store and its job');
check($count($enqueueStore(true)) === [1, 1], 'Commit keeps both the store and its job');

foreach ($tables as $table) check(count($worker->createCommand('SELECT * FROM ' . $table)->queryAll()) >= 0, 'Worker reads ' . $table);
$worker->createCommand()->update('btcpay_provisioning_jobs', ['state' => 'running', 'lease_owner' => $uuid, 'lease_until' => $now + 60, 'version' => 1], ['id' => $j, 'version' => 0])->execute();
$worker->createCommand()->update('btcpay_merchant_credentials', ['lock_version' => 1], ['account_id' => $accA, 'kind' => 'api_key', 'generation' => 2])->execute();
$worker->createCommand()->insert('btcpay_merchant_accounts', ['instance' => $i2, 'merchant_id' => $b, 'ref' => $ref, 'technical_email' => 'm-' . $ref . '@btcpay-merchants.jamprojects.it', 'created_at' => $now, 'updated_at' => $now])->execute();
check((int) $db->createCommand('SELECT version FROM btcpay_provisioning_jobs WHERE id=:id', [':id' => $j])->queryScalar() === 1, 'Worker claims jobs and writes B1 rows');
foreach ($tables as $table) rejects(fn() => $worker->createCommand('DELETE FROM ' . $table . ' WHERE 1 = 0')->execute(), DENIED, 'Worker cannot delete from ' . $table);
foreach (['ALTER TABLE btcpay_provisioning_jobs ADD COLUMN x INT NULL', 'DROP TABLE btcpay_pos_links', 'CREATE TABLE btcpay_x (id INT PRIMARY KEY)', 'TRUNCATE TABLE btcpay_provisioning_jobs'] as $ddl) {
    rejects(fn() => $worker->createCommand($ddl)->execute(), DENIED, 'Worker DDL refused: ' . strtok($ddl, '('));
}
rejects(fn() => $raw($worker, 'UPDATE', 'merchants', ['user_id' => 1]), DENIED, 'Worker cannot write merchants');
rejects(fn() => $raw($worker, 'INSERT', 'stores', ['merchant_id' => $a] + $store), DENIED, 'Worker cannot create stores');
rejects(fn() => $raw($worker, 'UPDATE', 'pos_terminals', ['is_enabled' => 1]), DENIED, 'Worker cannot change terminals');
rejects(fn() => $worker->createCommand('SELECT password FROM users')->queryAll(), COLUMN_DENIED, 'Worker cannot read password hashes');
rejects(fn() => $worker->createCommand('SELECT description FROM stores')->queryAll(), COLUMN_DENIED, 'Worker cannot read store details');
check(count($worker->createCommand('SELECT t.id FROM pos_terminals t JOIN stores s ON s.id=t.store_id AND s.merchant_id=t.merchant_id WHERE t.id=:t AND t.merchant_id=:m', [':t' => $terminals['a1'], ':m' => $a])->queryAll()) === 1, 'Worker re-checks terminal/store/merchant ownership');
$raw($worker, 'INSERT', 'logs', ['timestamp' => $now, 'event_type' => app\components\Log::TYPE_ACTIVITY, 'controller' => 'btcpay-provision', 'action' => 'schema-test', 'description' => 'B1b privilege probe']);
check((int) $db->createCommand("SELECT COUNT(*) FROM logs WHERE description='B1b privilege probe'")->queryScalar() === 1, 'Worker writes audit log entries');
rejects(fn() => $worker->createCommand('UPDATE logs SET description = description')->execute(), DENIED, 'Worker cannot alter audit log');
// Le stesse risposte di AccountEligibility con le sole colonne concesse al worker.
$userIds = array_map('intval', $db->createCommand("SELECT id FROM users WHERE username LIKE 'runtime-%' ORDER BY id")->queryColumn());
$evaluate = static function () use ($userIds, $a, $b): array {
    $result = [];
    foreach ($userIds as $id) $result['u' . $id] = [AccountEligibility::kind($id), AccountEligibility::merchantForManager($id), AccountEligibility::isOperatorAccount($id)];
    foreach ([$a, $b] as $id) $result['m' . $id] = AccountEligibility::qualifiedMerchant($id);
    return $result;
};
$expected = $evaluate();
$app->set('db', $worker);
try { $actual = $evaluate(); $used = Yii::$app->db->createCommand('SELECT CURRENT_USER()')->queryScalar(); } finally { $app->set('db', $db); }
check($used === 'napay_b1b_worker@%' && $expected === $actual && count($expected) === count($userIds) + 2, 'AccountEligibility gives identical answers on worker column grants');

echo "PASS $checks BTCPay provisioning schema/privilege checks\n";
