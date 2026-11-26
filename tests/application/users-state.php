<?php
// Verify only fixture accounts after HTTP status/mass-assignment tests.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/docker/bootstrap-common.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
$config = napayBootstrapConfig();
$db = app\components\db\V2Bootstrap::connect($config['dsn'], $config['user'], $config['password']);
$rows = app\components\db\V2Bootstrap::rows($db, 'SELECT u.*,p.level FROM users u JOIN privileges p ON p.id=u.privilege_id WHERE username=?', ['runtime-user']);
$user = $rows[0];
$checks = [
    (int) $user['is_active'] === 1,
    (int) $user['level'] === 0,
    password_verify('fixture-password-only', $user['password']),
    $user['oauth_uid'] === 'user' && $user['email'] === 'user@example.invalid',
    $user['authKey'] !== 'fixture-user' && $user['accessToken'] !== 'fixture-user',
    $user['activationCode'] === null,
];
$admin = app\components\db\V2Bootstrap::rows($db, 'SELECT is_active,authKey,accessToken FROM users WHERE username=?', ['runtime-admin'])[0];
$checks[] = $admin['is_active'] === '1' && $admin['authKey'] === 'fixture-admin' && $admin['accessToken'] === 'fixture-admin';
$log = is_file('/var/www/html/runtime/logs/app.log') ? file_get_contents('/var/www/html/runtime/logs/app.log') : '';
$checks[] = $log !== '' && str_contains($log, '[REDACTED]');
foreach (['fixture-password-only', 'fixture-user', 'fixture-admin', 'DB_PASSWORD', '$_POST', '$_SESSION', '$_COOKIE'] as $private) {
    $checks[] = !str_contains($log, $private);
}
if (in_array(false, $checks, true)) throw new RuntimeException('Fixture user state or private logging assertion failed.');
echo 'PASS ' . count($checks) . " user status/preservation/logging checks\n";
