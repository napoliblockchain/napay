<?php
// SIP-033 schema-only probes. No HTTP, BTCPay instance or shared database.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
require $root . '/migrations/m261118_100000_create_pos_btc_payments.php';
$app = new yii\console\Application(require $root . '/config/console.php');
$db = $app->db;
$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); ++$checks; echo "PASS: $label\n"; }
function rejected(callable $fn, string $label, array $codes = [3819, 1062, 1452]): void {
    $code = 0; try { $fn(); } catch (yii\db\Exception $error) { $code = (int) ($error->errorInfo[1] ?? 0); }
    check(in_array($code, $codes, true), $label);
}
function uuid(): string { $b = random_bytes(16); $b[6] = chr(ord($b[6]) & 15 | 64); $b[8] = chr(ord($b[8]) & 63 | 128); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4)); }
$now = time();
$insert = static function (string $table, array $row) use ($db): int { $db->createCommand()->insert($table, $row)->execute(); return (int) $db->lastInsertID; };
$scalar = static fn(string $sql) => $db->createCommand($sql)->queryScalar();
check($scalar('SELECT DATABASE()') === 'napay_test_runtime', 'Disposable database confirmed');
check((int) $scalar('SELECT COUNT(*) FROM pos_btc_payments') === 0, 'New BTC ledger starts empty; no imported payments');
$migration = new m261118_100000_create_pos_btc_payments(['db' => $db]);
ob_start(); try { $migration->up(); $migration->up(); } finally { ob_end_clean(); }
check(true, 'Exact migration safely replays twice');
$users = $db->createCommand("SELECT id FROM users WHERE username IN ('runtime-user','runtime-other') ORDER BY id")->queryColumn();
$merchants = $db->createCommand("SELECT id FROM merchants WHERE description LIKE 'Runtime company %' ORDER BY id")->queryColumn();
$store = ['email' => 'fixture@example.invalid', 'phone' => '', 'mobile' => '', 'addressStreet' => '', 'addressNumberHouse' => '', 'addressCity' => '', 'addressZip' => '', 'addressProvince' => '', 'addressCountry' => ''];
$stores = $terminals = $sessions = [];
foreach ($users as $i => $user) {
    $stores[$i] = $insert('stores', array_replace($store, ['merchant_id' => $merchants[$i], 'description' => 'BTC schema fixture ' . $i]));
    $terminals[$i] = $insert('pos_terminals', ['public_id' => uuid(), 'merchant_id' => $merchants[$i], 'store_id' => $stores[$i], 'name' => 'BTC fixture', 'created_at' => $now, 'updated_at' => $now]);
    $sessions[$i] = $insert('pos_operator_sessions', ['user_id' => $user, 'token_sha256' => bin2hex(random_bytes(32)), 'client_key_prefix' => 'btc-fixture', 'credential_fingerprint' => bin2hex(random_bytes(32)), 'created_at' => $now, 'expires_at' => $now + 3600]);
}
$opening = $insert('pos_terminal_openings', ['public_id' => uuid(), 'terminal_id' => $terminals[0], 'merchant_id' => $merchants[0], 'session_id' => $sessions[0], 'user_id' => $users[0], 'instance_id' => uuid(), 'generation' => 1, 'idempotency_sha256' => bin2hex(random_bytes(32)), 'request_sha256' => bin2hex(random_bytes(32)), 'opened_at' => $now, 'last_seen_at' => $now]);
$base = ['merchant_id' => $merchants[0], 'store_id' => $stores[0], 'terminal_id' => $terminals[0], 'opening_id' => $opening, 'session_id' => $sessions[0], 'user_id' => $users[0],
    'instance' => hash('sha256', 'https://btcpay.example.invalid'), 'remote_store_id' => 'StoreFixture', 'remote_app_id' => 'AppFixture', 'amount_cents' => 100, 'currency' => 'EUR', 'created_at' => $now, 'updated_at' => $now, 'expires_at' => $now + 900];
$payment = static fn(array $changes = []): array => array_replace($base, ['public_id' => uuid(), 'idempotency_sha256' => bin2hex(random_bytes(32)), 'request_sha256' => bin2hex(random_bytes(32))], $changes);
$write = static fn(array $row): int => $insert('pos_btc_payments', $row);
$row = $payment(); $id = $write($row);
check($scalar("SELECT state FROM pos_btc_payments WHERE id=$id") === 'creating', 'Creation intent exists before any invoice or network call');
$write($payment()); check(true, 'Several creation intents may have a NULL invoice');
rejected(static fn() => $write($payment(['public_id' => $row['public_id']])), 'Public UUID is unique');
rejected(static fn() => $write($payment(['idempotency_sha256' => $row['idempotency_sha256']])), 'Idempotency key unique in the operator session');
$write($payment(['session_id' => $sessions[1], 'idempotency_sha256' => $row['idempotency_sha256']])); check(true, 'Separate sessions have independent idempotency namespaces');
$pending = ['state' => 'pending', 'creation_started_at' => $now, 'invoice_id' => 'InvoiceFixture']; $write($payment($pending));
rejected(static fn() => $write($payment($pending)), 'Invoice is unique per BTCPay instance');
$write($payment($pending + ['instance' => str_repeat('b', 64)])); check(true, 'Separate instances have independent invoice namespaces');
foreach ([
    ['public_id' => str_repeat('a', 36)], ['instance' => str_repeat('A', 64)], ['request_sha256' => str_repeat('A', 64)],
    ['remote_store_id' => '../store'], ['remote_app_id' => ''], ['invoice_id' => 'bad/path', 'state' => 'pending', 'creation_started_at' => $now],
    ['amount_cents' => 0], ['amount_cents' => 100001], ['currency' => 'eur'], ['currency' => 'EU'],
    ['state' => 'paid'], ['state' => 'pending'], ['invoice_id' => 'InvoiceWithoutState'],
    ['state' => 'pending', 'invoice_id' => 'MissingCheckpoint'], ['state' => 'reconcile_required'],
    ['settled_at' => $now], ['closed_at' => $now], ['confirmations' => -1], ['version' => -1],
    ['btc_address' => 'bc1qfixture00000000000000000000000000000000'], ['amount_satoshis' => 1],
    ['btc_address' => 'bc1qfixture00000000000000000000000000000000', 'amount_satoshis' => 0],
    ['btc_address' => 'bc1qfixture00000000000000000000000000000000', 'amount_satoshis' => 2100000000000001],
    ['updated_at' => $now - 1], ['expires_at' => $now], ['processing_at' => $now - 1], ['creation_started_at' => $now + 1],
    ['last_error_code' => 'remote error with raw data'],
] as $n => $changes) rejected(static fn() => $write($payment($changes)), 'Invalid BTC payment row rejected: ' . $n);
foreach (['processing', 'settled', 'expired', 'invalid', 'failed', 'reconcile_required'] as $state) {
    $changes = ['state' => $state, 'creation_started_at' => $now];
    if (in_array($state, ['processing', 'settled', 'expired', 'invalid'], true)) $changes['invoice_id'] = 'Invoice' . $state;
    if ($state === 'processing') $changes['processing_at'] = $now;
    if ($state === 'settled') $changes['settled_at'] = $now;
    if (in_array($state, ['expired', 'invalid', 'failed'], true)) $changes['closed_at'] = $now;
    $write($payment($changes)); check(true, 'Valid BTC state persisted: ' . $state);
}
// SIP-030 passo 5c: storico v1 nella stessa tabella, senza apertura/sessione/utente/app solo se chiuso
$legacy = ['opening_id' => null, 'session_id' => null, 'user_id' => null, 'remote_app_id' => null, 'creation_started_at' => $now];
$write($payment($legacy + ['state' => 'settled', 'settled_at' => $now, 'invoice_id' => 'LegacySettled'])); check(true, 'v1 settled payment without v2 opening, session, user or app persisted');
$write($payment($legacy + ['state' => 'expired', 'closed_at' => $now, 'invoice_id' => 'LegacyExpired'])); check(true, 'v1 expired payment persisted');
foreach ([
    $legacy + ['state' => 'pending', 'invoice_id' => 'LegacyPending'], $legacy + ['state' => 'processing', 'processing_at' => $now, 'invoice_id' => 'LegacyProcessing'],
    ['session_id' => null, 'state' => 'settled', 'settled_at' => $now, 'invoice_id' => 'PartialA', 'creation_started_at' => $now],
    ['user_id' => null, 'state' => 'expired', 'closed_at' => $now, 'invoice_id' => 'PartialB', 'creation_started_at' => $now],
    ['remote_app_id' => null], ['opening_id' => null, 'session_id' => null, 'user_id' => null, 'state' => 'expired', 'closed_at' => $now, 'invoice_id' => 'NoAppMissing', 'creation_started_at' => $now],
    $legacy + ['state' => 'expired', 'closed_at' => $now, 'invoice_id' => 'LegacyBadStore', 'remote_store_id' => '../x'],
] as $n => $changes) rejected(static fn() => $write($payment($changes)), 'Payment without v2 context only when closed and complete: ' . $n);
foreach ([['store_id' => $stores[1]], ['terminal_id' => $terminals[1]], ['merchant_id' => $merchants[1]], ['user_id' => 2147483647], ['session_id' => 2147483647], ['opening_id' => 2147483647]] as $n => $changes) {
    rejected(static fn() => $write($payment($changes)), 'Foreign key or composite tenant scope enforced: ' . $n, [1452]);
}
rejected(static fn() => $db->createCommand()->delete('pos_terminal_openings', ['id' => $opening])->execute(), 'History prevents deletion of its opening', [1451]);
$count = (int) $scalar('SELECT COUNT(*) FROM pos_btc_payments');
foreach ([
    ['ALTER TABLE pos_btc_payments ADD COLUMN unreviewed INT NULL', 'ALTER TABLE pos_btc_payments DROP COLUMN unreviewed'],
    ['ALTER TABLE pos_btc_payments ALTER CHECK `ck-pos-btc-payments-amount` NOT ENFORCED', 'ALTER TABLE pos_btc_payments ALTER CHECK `ck-pos-btc-payments-amount` ENFORCED'],
    ["ALTER TABLE pos_btc_payments ALTER COLUMN state SET DEFAULT 'failed'", "ALTER TABLE pos_btc_payments ALTER COLUMN state SET DEFAULT 'creating'"],
] as [$alter, $restore]) {
    $db->createCommand($alter)->execute(); $refused = false;
    $altered = $db->createCommand('SHOW CREATE TABLE pos_btc_payments')->queryOne()['Create Table'];
    ob_start(); try { $migration->up(); } catch (RuntimeException $error) { $refused = str_contains($error->getMessage(), 'incompatible'); } finally {
        ob_end_clean();
        $unchanged = $altered === $db->createCommand('SHOW CREATE TABLE pos_btc_payments')->queryOne()['Create Table'];
        $db->createCommand($restore)->execute();
    }
    check($refused && $unchanged, 'Migration refuses schema drift without repair');
}
// ALTER can rewrite equivalent charset introducers; compare the replay itself byte-for-byte.
$original = $db->createCommand('SHOW CREATE TABLE pos_btc_payments')->queryOne()['Create Table'];
ob_start(); try { $migration->up(); $down = $migration->down(); } finally { ob_end_clean(); }
check($down === false && $original === $db->createCommand('SHOW CREATE TABLE pos_btc_payments')->queryOne()['Create Table'] && $count === (int) $scalar('SELECT COUNT(*) FROM pos_btc_payments'), 'Replay preserves exact schema and payments; destructive rollback refused');
echo "PASS $checks POS BTC schema checks\n";
