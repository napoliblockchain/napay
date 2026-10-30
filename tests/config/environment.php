<?php
// No vendor, network, production environment or database is needed.
$root = dirname(__DIR__, 2);
$fixture = sys_get_temp_dir() . '/napay-env-' . bin2hex(random_bytes(6));
mkdir($fixture . '/config', 0700, true);
foreach (['env.php', 'db.php', 'params.php', 'web.php', 'console.php', 'version.php', 'mailer.php'] as $name) {
    copy($root . '/config/' . $name, $fixture . '/config/' . $name);
}
file_put_contents($fixture . '/config/secrets.php', '<?php throw new RuntimeException("Legacy configuration executed");');
$values = [
    'DB_DSN' => 'mysql:host=fixture;dbname=fixture', 'DB_USERNAME' => 'fixture',
    'DB_PASSWORD' => 'fixture-password', 'ADMIN_EMAIL' => 'test@example.invalid',
    'SECRET_HASH_KEY' => 'fixture-key-only-do-not-use', 'COOKIE_VALIDATION_KEY' => 'fixture-cookie-only-do-not-use',
];
$program = <<<'CHILD'
try {
    // proc_open omits empty environment values; restore them explicitly for negative tests.
    foreach (json_decode($argv[2], true) as $emptyKey) putenv($emptyKey . '=');
    $_ENV['DB_USERNAME'] = 'stale-superglobal';
    $db = require $argv[1] . '/config/db.php';
    // Yii normally derives this constant after loading the bootstrap.
    define('YII_ENV_DEV', YII_ENV === 'dev');
    $params = require $argv[1] . '/config/params.php';
    $web = require $argv[1] . '/config/web.php';
    $console = require $argv[1] . '/config/console.php';
    echo json_encode([
        'username' => $db['username'], 'passwordMatch' => $db['password'] === 'literal#${HOME}=$()!',
        'environment' => YII_ENV, 'debug' => YII_DEBUG,
        'cookieConfigured' => $web['components']['request']['cookieValidationKey'] !== '',
        'cookieSecurity' => [
            $web['components']['request']['csrfCookie'],
            $web['components']['user']['identityCookie'],
            $web['components']['session']['cookieParams'],
        ],
        'sameDb' => $console['components']['db'] === $db,
        'developerToolsAbsent' => !isset($web['modules']['debug']) && !isset($web['modules']['gii'])
            && !isset($console['modules']['debug']) && !isset($console['modules']['gii'])
            && !array_intersect(['debug', 'gii'], array_merge($web['bootstrap'], $console['bootstrap'])),
        'fileMail' => $web['components']['mailer']['useFileTransport'],
        'tls' => $web['components']['mailer']['transport']['options']['require_tls'] ?? null,
    ]);
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
CHILD;
$checks = 0;
function runCase($environment, $file = '') {
    global $fixture, $program;
    file_put_contents($fixture . '/.env', $file);
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $program, $fixture, json_encode(array_keys(array_filter($environment, function ($value) { return $value === ''; })))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) throw new RuntimeException('Cannot start test subprocess');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || $errors !== '') throw new RuntimeException('Unexpected subprocess failure');
    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}
function check($condition, $label) {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
}
try {
    $r = runCase($values);
    check(!isset($r['error']) && $r['cookieConfigured'] && $r['sameDb'], 'Web and console load without secrets.php: ' . ($r['error'] ?? 'invalid response'));
    check($r['environment'] === 'prod' && $r['debug'] === false, 'Production and debug defaults');
    check($r['username'] === 'fixture', 'Process environment overrides stale superglobal');
    foreach (['prod', 'dev', 'test'] as $environment) {
        check(runCase($values + ['APP_ENV' => $environment])['developerToolsAbsent'], 'Developer modules absent in ' . $environment);
    }
    foreach ($r['cookieSecurity'] as $cookie) {
        check($cookie['httpOnly'] && $cookie['sameSite'] === 'Lax' && !$cookie['secure'], 'HTTP diagnostic cookie compatibility');
    }
    $secure = runCase($values + ['HTTPS_ONLY' => 'true']);
    foreach ($secure['cookieSecurity'] as $cookie) {
        check($cookie['httpOnly'] && $cookie['sameSite'] === 'Lax' && $cookie['secure'], 'HTTPS cookie protection');
    }
    check(isset(runCase($values + ['HTTPS_ONLY' => 'invalid'])['error']), 'Invalid HTTPS boolean rejected');
    $r = runCase($values, "DB_USERNAME=from-file\nAPP_DEBUG=true\n");
    check($r['username'] === 'fixture' && $r['debug'] === true, 'Existing environment wins over file');
    $r = runCase($values + ['APP_DEBUG' => 'false'], "APP_DEBUG=true\n");
    check($r['debug'] === false, 'Explicit false is preserved');
    $file = "# fixture only\n";
    foreach ($values as $key => $value) $file .= $key . '="' . $value . '"' . "\n";
    // Deliberately skip stale $_ENV for this case by supplying the actual username.
    $r = runCase(['DB_USERNAME' => 'fixture'], $file . "APP_ENV=dev\nAPP_DEBUG=1\n");
    check(!isset($r['error']) && $r['environment'] === 'dev' && $r['debug'] === true, 'Quoted .env values are loaded');
    $r = runCase(array_replace($values, ['DB_PASSWORD' => 'literal#${HOME}=$()!']));
    check($r['passwordMatch'] === true, 'Passwords are literal and never evaluated');
    foreach (['DB_DSN', 'DB_USERNAME', 'DB_PASSWORD', 'COOKIE_VALIDATION_KEY', 'SECRET_HASH_KEY', 'ADMIN_EMAIL'] as $key) {
        $r = runCase(array_replace($values, [$key => '']));
        check(isset($r['error']) && str_contains($r['error'], $key), 'Missing value must fail: ' . $key . ' (' . ($r['error'] ?? $r['username'] ?? 'none') . ')');
        check(!str_contains($r['error'], 'fixture-password'), 'Failure must not disclose credentials');
    }
    $r = runCase($values + ['APP_ENV' => 'invalid']);
    check(isset($r['error']) && str_contains($r['error'], 'APP_ENV'), 'Invalid environment rejected');
    $r = runCase($values + ['APP_DEBUG' => 'invalid']);
    check(isset($r['error']) && str_contains($r['error'], 'APP_DEBUG'), 'Invalid boolean rejected');
    $r = runCase($values, "INVALID KEY=value\n");
    check(isset($r['error']), 'Invalid .env key rejected');
    $r = runCase(array_replace($values, ['DB_PASSWORD' => '   ']));
    check(isset($r['error']), 'Whitespace-only required value rejected');
    check(runCase($values)['fileMail'] === true, 'Default mail is file only');
    check(isset(runCase($values + ['MAIL_TRANSPORT' => 'invalid'])['error']), 'Invalid mail mode rejected');
    $smtp = $values + ['MAIL_TRANSPORT' => 'smtp', 'MAIL_SMTP_HOST' => 'smtp.example.invalid', 'MAIL_SMTP_USERNAME' => 'fixture', 'MAIL_SMTP_PASSWORD' => 'fixture-smtp-password'];
    foreach (['smtp', 'smtps'] as $scheme) {
        $r = runCase($smtp + ['MAIL_SMTP_SCHEME' => $scheme]);
        check(!isset($r['error']) && !$r['fileMail'] && $r['tls'] === true, 'SMTP requires TLS for ' . $scheme);
    }
    foreach (['MAIL_SMTP_HOST', 'MAIL_SMTP_USERNAME', 'MAIL_SMTP_PASSWORD'] as $key) {
        $r = runCase(array_replace($smtp, [$key => '']));
        check(isset($r['error']) && !str_contains($r['error'], 'fixture-smtp-password'), 'Incomplete SMTP fails without credential disclosure');
    }
    foreach (['MAIL_SMTP_PORT' => '70000', 'MAIL_SMTP_SCHEME' => 'sendmail', 'MAIL_SMTP_HOST' => "bad\nhost"] as $key => $value) {
        check(isset(runCase(array_replace($smtp, [$key => $value]))['error']), 'Invalid SMTP option rejected: ' . $key);
    }
    echo "PASS {$checks} environment configuration checks\n";
} finally {
    foreach (glob($fixture . '/config/*') as $path) unlink($path);
    unlink($fixture . '/.env'); rmdir($fixture . '/config'); rmdir($fixture);
}
