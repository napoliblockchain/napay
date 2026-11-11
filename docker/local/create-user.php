<?php
// Explicitly local-only provisioning: no changes to existing identities.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap-common.php';
if (env('NAPAY_LOCAL_BROWSER') !== 'isolated-copy'
    || env('DB_DATABASE') !== 'napay_browser_local'
    || env('MAIL_TRANSPORT') !== 'file'
    || env('WEBAPP_LINK') !== 'https://napay.local') exit(1);
$config = napayBootstrapConfig();
$db = app\components\db\V2Bootstrap::connect($config['dsn'], $config['user'], $config['password']);
app\components\db\V2Bootstrap::ready($db, dirname(__DIR__, 2));
$password = trim(stream_get_contents(STDIN));
if (!preg_match('/^[a-f0-9]{48}$/D', $password)) exit(1);
$query = $db->prepare('SELECT password,oauth_provider,is_active FROM users WHERE username=?');
$query->execute(['browser-admin']);
$existing = $query->fetch(PDO::FETCH_ASSOC);
if ($existing) {
    if ($existing['oauth_provider'] !== 'SELF' || (int) $existing['is_active'] !== 1
        || !password_verify($password, $existing['password'])) exit(1);
    echo "Local browser identity already present; no changes.\n";
    exit;
}
$role = $db->query('SELECT id FROM privileges WHERE level=50 ORDER BY id LIMIT 1')->fetchColumn();
if (!$role) throw new RuntimeException('Existing administrator role required.');
$query = $db->prepare('INSERT INTO users (username,email,password,first_name,last_name,oauth_provider,oauth_uid,authKey,accessToken,picture,privilege_id,is_merchant,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
$query->execute(['browser-admin', 'browser-admin@example.invalid', password_hash($password, PASSWORD_BCRYPT),
    'Browser', 'Local test', 'SELF', bin2hex(random_bytes(16)), bin2hex(random_bytes(16)),
    bin2hex(random_bytes(30)), '/favicon.ico', $role, 0, 1]);
echo "Local browser identity created; historical identities unchanged.\n";
