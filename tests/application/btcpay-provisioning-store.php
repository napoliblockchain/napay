<?php
// SIP-022 B1 W1: ProvisioningStore su DB usa-e-getta con l'utente worker ristretto (matrice B1b).
// stdin: password dell'utente worker (prima riga). Chiavi KEK e segreti di prova casuali, mai stampati.
if (PHP_SAPI !== 'cli') exit(1);
ini_set('zend.exception_ignore_args', '0');
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@app', $root);
use app\components\btcpay\provisioning\ProvisioningStore;

$password = trim((string) fgets(STDIN));
if (!preg_match('/^[0-9a-f]{48}$/D', $password)) exit(1);
$connectWorker = static function () use ($password): yii\db\Connection {
    $connection = new yii\db\Connection(['dsn' => env('DB_DSN'), 'username' => 'napay_b1b_worker', 'password' => $password, 'charset' => 'utf8mb4']);
    $connection->open();
    return $connection;
};
$instance = hash('sha256', 'https://btcpay.regtest.invalid');
$keyring = ['k1' => str_repeat("\x01", 32), 'k2' => str_repeat("\x02", 32)];
// Processi figli per le gare: connessione worker propria, partenza su segnale.
if (in_array($argv[1] ?? '', ['--ensure', '--promote'], true)) {
    try {
        $store = new ProvisioningStore($connectWorker(), $instance, $keyring, 'k1');
        $account = $store->account((int) $argv[2]);
        fwrite(STDOUT, "ready\n"); fread(STDIN, 1);
        $result = $argv[1] === '--ensure' ? $store->ensureAccount((int) $argv[2])['id'] : ($store->promoteKey($account, (int) $argv[3]) ? 'ok' : 'no');
        fwrite(STDOUT, $result . "\n");
        exit(0);
    } catch (Throwable $e) { exit(2); }
}
$app = new yii\console\Application(require $root . '/config/console.php');
$db = $app->db;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);
$worker = $connectWorker();
$checks = 0;
function check($condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException($label); ++$checks; echo 'PASS: ' . $label . "\n"; }
function refuses(callable $fn, string $class, string $code, string $label): void {
    $ok = false;
    try { $fn(); } catch (Throwable $e) { $ok = $e instanceof $class && $e->getMessage() === $code; }
    check($ok, $label);
}
function race(array $args, string $password): array {
    $children = [];
    foreach ($args as $arg) {
        $process = proc_open(array_merge([PHP_BINARY, __FILE__], $arg), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes);
        fwrite($pipes[0], $password . "\n"); fflush($pipes[0]);
        stream_set_timeout($pipes[1], 10);
        if (fgets($pipes[1]) !== "ready\n") throw new RuntimeException('Race fixture did not start.');
        $children[] = [$process, $pipes];
    }
    foreach ($children as [, $pipes]) { fwrite($pipes[0], 'x'); fclose($pipes[0]); }
    $out = [];
    foreach ($children as [$process, $pipes]) { $out[] = trim((string) fgets($pipes[1])); fclose($pipes[1]); $out[] = 'exit' . proc_close($process); }
    return $out;
}
$store = new ProvisioningStore($worker, $instance, $keyring, 'k1');
$other = new ProvisioningStore($worker, hash('sha256', 'https://btcpay.other.invalid'), $keyring, 'k1');
check($worker->createCommand('SELECT CURRENT_USER()')->queryScalar() === 'napay_b1b_worker@%', 'Runs as the restricted worker user');

// --- fixture Napay (admin): due merchant, negozi, terminali
$merchants = array_column($db->createCommand("SELECT description,id FROM merchants WHERE description IN ('Runtime company merchant','Runtime company other')")->queryAll(), 'id', 'description');
$a = (int) $merchants['Runtime company merchant']; $b = (int) $merchants['Runtime company other'];
$base = ['email' => 'w1@example.invalid', 'phone' => '', 'mobile' => '', 'addressStreet' => '', 'addressNumberHouse' => '', 'addressCity' => '', 'addressZip' => '', 'addressProvince' => '', 'addressCountry' => ''];
$storeOf = static function (int $merchant, string $name) use ($db, $base): int { $db->createCommand()->insert('stores', ['merchant_id' => $merchant, 'description' => $name] + $base)->execute(); return (int) $db->lastInsertID; };
$sA = $storeOf($a, 'W1 A1'); $sA2 = $storeOf($a, 'W1 A2'); $sB = $storeOf($b, 'W1 B1');
$terminal = static function (int $merchant, int $storeId) use ($db): int {
    $t = new app\models\PosTerminals(['merchant_id' => $merchant, 'store_id' => $storeId, 'name' => 'W1 cassa']);
    if (!$t->save()) throw new RuntimeException('Terminal fixture failed.');
    return (int) $t->id;
};
$tA = $terminal($a, $sA); $tA2 = $terminal($a, $sA2);

// --- input e costruzione
refuses(fn() => new ProvisioningStore($worker, 'not-hex', $keyring, 'k1'), InvalidArgumentException::class, 'invalid_instance', 'Instance must be a SHA-256 hex');
refuses(fn() => new ProvisioningStore($worker, $instance, $keyring, 'k9'), InvalidArgumentException::class, 'unknown_kek_version', 'Encryption KEK must exist in the keyring');
refuses(fn() => $store->ensureAccount(0), InvalidArgumentException::class, 'invalid_id', 'Merchant id must be positive');

// --- account: idempotenza, gara, CAS
$account = $store->ensureAccount($a);
check($account['state'] === 'pending' && $account['version'] === 0 && preg_match('/^[0-9a-f]{32}$/', $account['ref'])
    && $account['technical_email'] === 'm-' . $account['ref'] . '@btcpay-merchants.jamprojects.it', 'Account created pending with random ref and technical e-mail');
check($store->ensureAccount($a) === $account, 'ensureAccount is idempotent');
check($other->account($a) === null, 'Accounts are per instance');
$out = race([['--ensure', (string) $b], ['--ensure', (string) $b]], $password);
$accountB = $store->account($b);
check($out[1] === 'exit0' && $out[3] === 'exit0' && $out[0] === (string) $accountB['id'] && $out[2] === (string) $accountB['id']
    && (int) $db->createCommand('SELECT COUNT(*) FROM btcpay_merchant_accounts WHERE merchant_id=:m', [':m' => $b])->queryScalar() === 1, 'Concurrent ensureAccount on two connections: one row, same id');
$moved = $store->updateAccount($account, ['state' => 'user_requested']);
check($moved['state'] === 'user_requested' && $moved['version'] === 1, 'CAS update bumps version');
check($store->updateAccount($account, ['state' => 'failed']) === null && $store->account($a)['state'] === 'user_requested', 'Stale version rejected, row unchanged');
refuses(fn() => $store->updateAccount($moved, ['state' => 'active']), InvalidArgumentException::class, 'invalid_state', 'Unknown account state refused');
refuses(fn() => $store->updateAccount($moved, ['ref' => 'x']), InvalidArgumentException::class, 'invalid_change', 'Only state, remote id, generations and error code can change');
refuses(fn() => $store->updateAccount($moved, ['last_error_code' => 'HTTP 500 boom']), InvalidArgumentException::class, 'invalid_error_code', 'Free-text error refused');
refuses(fn() => $other->updateAccount($moved, ['state' => 'failed']), InvalidArgumentException::class, 'foreign_instance', 'Account of another instance refused');
$account = $store->updateAccount($moved, ['state' => 'user_created', 'remote_user_id' => 'remote-user-1']);
check($account['remote_user_id'] === 'remote-user-1', 'Remote user id recorded');

// --- password tecnica: una volta, cifrata, mai riscritta
$secret = 'pw-' . bin2hex(random_bytes(16));
check($store->storePassword($account, $secret) && $store->password($account) === $secret, 'Technical password stored and decrypted');
check(!$store->storePassword($account, 'pw-other-' . bin2hex(random_bytes(8))) && $store->password($account) === $secret, 'Retry never rewrites the technical password');
$row = $db->createCommand("SELECT * FROM btcpay_merchant_credentials WHERE account_id=:a AND kind='onboarding_password'", [':a' => $account['id']])->queryOne();
check($row['state'] === 'current' && strlen($row['secret_nonce']) === 24 && strlen($row['wrapped_dek']) === 48 && !str_contains($row['ciphertext'], $secret) && $row['kek_version'] === 'k1', 'Stored only as an envelope (binary, no plaintext)');
$tampered = $row['ciphertext']; $tampered[0] = chr(ord($tampered[0]) ^ 1);
$db->createCommand()->update('btcpay_merchant_credentials', ['ciphertext' => $tampered], ['account_id' => $account['id'], 'kind' => 'onboarding_password'])->execute();
refuses(fn() => $store->password($account), UnexpectedValueException::class, 'decryption_failed', 'Tampered ciphertext refused');
$db->createCommand()->update('btcpay_merchant_credentials', ['ciphertext' => $row['ciphertext']], ['account_id' => $account['id'], 'kind' => 'onboarding_password'])->execute();
check($store->password($account) === $secret, 'Restored envelope decrypts again');
// Busta copiata su un altro account: il contesto (account/merchant) non coincide.
$copy = $row; $copy['account_id'] = $accountB['id']; $copy['merchant_id'] = $b; unset($copy['current_marker']);
$db->createCommand()->insert('btcpay_merchant_credentials', $copy)->execute();
refuses(fn() => $store->password($accountB), UnexpectedValueException::class, 'decryption_failed', 'Envelope copied to another account refused (cross-context)');

// --- chiavi API: generazioni, una sola current, revoca
$k1 = 'key-' . bin2hex(random_bytes(16)); $k2 = 'key-' . bin2hex(random_bytes(16)); $k3 = 'key-' . bin2hex(random_bytes(16));
check($store->storeKey($account, 1, $k1) && !$store->storeKey($account, 1, $k2), 'Key generation stored once');
check($store->currentKey($account) === null, 'Pending key is not current');
check($store->promoteKey($account, 1) && $store->currentKey($account) === ['generation' => 1, 'key' => $k1], 'Promoted key becomes current, fingerprint verified');
check($store->storeKey($account, 2, $k2) && $store->promoteKey($account, 2) && $store->currentKey($account)['key'] === $k2, 'Rotation: new generation current');
check($db->createCommand("SELECT state FROM btcpay_merchant_credentials WHERE account_id=:a AND kind='api_key' AND generation=1", [':a' => $account['id']])->queryScalar() === 'retiring', 'Previous key retiring until revoked');
check(!$store->revokeKey($account, 2) && $store->revokeKey($account, 1), 'Current key cannot be revoked; retiring one can');
$revoked = $db->createCommand("SELECT state,ciphertext,key_fingerprint FROM btcpay_merchant_credentials WHERE account_id=:a AND kind='api_key' AND generation=1", [':a' => $account['id']])->queryOne();
check($revoked === ['state' => 'revoked', 'ciphertext' => null, 'key_fingerprint' => null], 'Revocation clears the envelope, keeps the row');
check(!$store->promoteKey($account, 1) && !$store->promoteKey($account, 9), 'Revoked or missing generation cannot be promoted');
$db->createCommand()->update('btcpay_merchant_credentials', ['key_fingerprint' => hash('sha256', 'other')], ['account_id' => $account['id'], 'kind' => 'api_key', 'generation' => 2])->execute();
refuses(fn() => $store->currentKey($account), UnexpectedValueException::class, 'fingerprint_mismatch', 'Fingerprint mismatch detected');
$db->createCommand()->update('btcpay_merchant_credentials', ['key_fingerprint' => hash('sha256', $k2)], ['account_id' => $account['id'], 'kind' => 'api_key', 'generation' => 2])->execute();
$store->storeKey($account, 3, $k3); $store->storeKey($account, 4, 'key-' . bin2hex(random_bytes(16)));
$out = race([['--promote', (string) $a, '3'], ['--promote', (string) $a, '4']], $password);
$current = $db->createCommand("SELECT generation FROM btcpay_merchant_credentials WHERE account_id=:a AND kind='api_key' AND state='current'", [':a' => $account['id']])->queryColumn();
check($out[0] === 'ok' && $out[2] === 'ok' && count($current) === 1 && in_array((int) $current[0], [3, 4], true), 'Concurrent promotions on two connections: exactly one current');
// Busta di un'altra generazione copiata sulla corrente: il contesto (generazione) non coincide.
$cur = (int) $current[0];
$src = $db->createCommand("SELECT secret_nonce,ciphertext,dek_nonce,wrapped_dek FROM btcpay_merchant_credentials WHERE account_id=:a AND kind='api_key' AND generation=:g",
    [':a' => $account['id'], ':g' => $cur === 3 ? 4 : 3])->queryOne();
$keep = $db->createCommand("SELECT secret_nonce,ciphertext,dek_nonce,wrapped_dek FROM btcpay_merchant_credentials WHERE account_id=:a AND kind='api_key' AND generation=:g",
    [':a' => $account['id'], ':g' => $cur])->queryOne();
$db->createCommand()->update('btcpay_merchant_credentials', $src, ['account_id' => $account['id'], 'kind' => 'api_key', 'generation' => $cur])->execute();
refuses(fn() => $store->currentKey($account), UnexpectedValueException::class, 'decryption_failed', 'Envelope moved to another generation refused (cross-context)');
$db->createCommand()->update('btcpay_merchant_credentials', $keep, ['account_id' => $account['id'], 'kind' => 'api_key', 'generation' => $cur])->execute();
check($store->currentKey($account)['generation'] === $cur, 'Original envelope restored');
// Altra KEK nel keyring: busta cifrata con k2 si legge con lo stesso keyring.
$rotated = new ProvisioningStore($worker, $instance, $keyring, 'k2');
$acc2 = $rotated->ensureAccount($a);
check($rotated->storeKey($acc2, 5, $k3) && $db->createCommand("SELECT kek_version FROM btcpay_merchant_credentials WHERE account_id=:a AND generation=5", [':a' => $acc2['id']])->queryScalar() === 'k2', 'New envelopes use the configured KEK version');

// --- store e app POS
$link = $store->ensureStoreLink($account, $sA);
check($link['state'] === 'pending' && preg_match('/^[0-9a-f]{32}$/', $link['marker']) && $store->ensureStoreLink($account, $sA) === $link, 'Store link created pending with random marker, idempotent');
refuses(fn() => $store->ensureStoreLink($account, $sB), InvalidArgumentException::class, 'scope_mismatch', 'Store of another merchant refused by the composite FK');
refuses(fn() => $store->ensureStoreLink($accountB, $sA), InvalidArgumentException::class, 'scope_mismatch', 'Existing link of another merchant never handed over');
$synced = $store->updateStoreLink($link, ['state' => 'synced', 'remote_store_id' => 'remote-store-1']);
check($synced['state'] === 'synced' && $synced['version'] === 1 && $store->updateStoreLink($link, ['state' => 'failed']) === null, 'Store link CAS: synced with remote id, stale update rejected');
refuses(fn() => $store->updateStoreLink($synced, ['marker' => 'x']), InvalidArgumentException::class, 'invalid_change', 'Store marker cannot change');
$pos = $store->ensurePosLink($synced, $tA);
check($pos['state'] === 'pending' && $pos['store_link_id'] === $synced['id'] && $store->ensurePosLink($synced, $tA) === $pos, 'POS link created pending, idempotent');
refuses(fn() => $store->ensurePosLink($synced, $tA2), InvalidArgumentException::class, 'scope_mismatch', 'Terminal of another store refused by the composite FK');
$posSynced = $store->updatePosLink($pos, ['state' => 'synced', 'remote_app_id' => 'remote-app-1']);
check($posSynced['remote_app_id'] === 'remote-app-1' && $store->updatePosLink($pos, ['state' => 'failed']) === null, 'POS link CAS');
$failedSynced = false;
try { $store->updatePosLink($posSynced, ['remote_app_id' => null]); } catch (yii\db\Exception $e) { $failedSynced = (int) ($e->errorInfo[1] ?? 0) === 3819; }
check($failedSynced, 'Synced link without remote id refused by the DB CHECK');

// --- privilegi: nessuna DELETE neanche volendo, segreti fuori dalle trace
$denied = false;
try { $worker->createCommand('DELETE FROM btcpay_merchant_credentials WHERE 1 = 0')->execute(); } catch (yii\db\Exception $e) { $denied = (int) ($e->errorInfo[1] ?? 0) === 1142; }
check($denied, 'Worker cannot delete credentials');
$leakKey = 'key-' . bin2hex(random_bytes(16));
$trace = '';
try { $store->storeKey($account, 0, $leakKey); } catch (InvalidArgumentException $e) { $trace = $e->getTraceAsString() . print_r(array_filter($e->getTrace(), fn($f) => ($f['class'] ?? '') === ProvisioningStore::class), true); }
check($trace !== '' && !str_contains($trace, $leakKey), 'Secret arguments hidden from traces (SensitiveParameter)');
$source = file_get_contents($root . '/components/btcpay/provisioning/ProvisioningStore.php');
check(preg_match('/Yii::|error_log|\becho\b|\bprint(_r)?\b|\bsettings\b|curl_|Http/i', $source) === 0, 'No logging, legacy settings or HTTP in the store');
echo "PASS $checks BTCPay provisioning store checks\n";
