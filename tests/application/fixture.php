<?php
// Add fake identities only to the explicitly named disposable HTTP test DB.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/docker/bootstrap-common.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
$config = napayBootstrapConfig();
$db = app\components\db\V2Bootstrap::connect($config['dsn'], $config['user'], $config['password']);
app\components\db\V2Bootstrap::ready($db, dirname(__DIR__, 2));
foreach ([40, 60] as $level) {
    $db->prepare('INSERT INTO privileges (description,level,codice_ruolo) VALUES (?,?,?)')
        ->execute(['Runtime fixture', $level, 'FIXTURE_' . $level]);
}
foreach (['admin' => 50, 'user' => 0, 'other' => 0, 'disabled' => 0, 'blocked' => 0, 'merchant' => 30, 'operator' => 40, 'higher' => 60] as $role => $level) {
    $privilege = app\components\db\V2Bootstrap::rows($db, 'SELECT id FROM privileges WHERE level=?', [$level])[0]['id'];
    $statement = $db->prepare('INSERT INTO users (username,email,password,first_name,last_name,oauth_provider,oauth_uid,authKey,accessToken,picture,privilege_id,is_merchant,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $statement->execute(['runtime-' . $role, $role . '@example.invalid', password_hash('fixture-password-only', PASSWORD_BCRYPT), $role === 'user' ? 'Runtime <svg onload="window.pwned=true">' : 'Runtime', $role === 'other' ? '=1+1' : 'Fixture', 'fixture', $role, 'fixture-' . $role, 'fixture-' . $role, '/favicon.ico', $privilege, 0, $role === 'disabled' ? 0 : ($role === 'blocked' ? 2 : 1)]);
}
echo "PASS: disposable HTTP identities created after verified v2 restore\n";
foreach (['merchant', 'other'] as $role) {
    $owner = app\components\db\V2Bootstrap::rows($db, 'SELECT id FROM users WHERE username=?', ['runtime-' . $role])[0]['id'];
    $db->prepare('INSERT INTO merchants (user_id,description,email,vatNumber,phone,mobile,addressStreet,addressNumberHouse,addressCity,addressZip,addressProvince,addressCountry) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$owner, 'Runtime company ' . $role, $role . '@example.invalid', '12345678901', '123', '456', 'Fixture street', '1', 'Napoli', '80100', 'NA', 'IT']);
}
echo "PASS: disposable merchant companies created\n";
