<?php
// Dedicated console configuration: load this before config/env.php, never the web/console params.
try {
    if (is_file(dirname(__DIR__) . '/.env') || is_link(dirname(__DIR__) . '/.env')
        || getenv('NAPAY_DB_ROLE') !== 'worker' || getenv('NAPAY_DB_ROLE_MODE') !== 'true'
        || getenv('NAPAY_DB_PASSWORD_FILE') !== '/run/secrets/napay_db_worker_password') throw new \RuntimeException();
    foreach (['DB_PASSWORD', 'NAPAY_WEB_DB_PASSWORD', 'NAPAY_MYSQL_ROOT_SECRET_FILE', 'NAPAY_DB_MIGRATOR_SECRET_FILE',
        'SECRET_HASH_KEY', 'COOKIE_VALIDATION_KEY'] as $forbidden) {
        $value = getenv($forbidden);
        if (($value !== false && $value !== '') || (isset($_ENV[$forbidden]) && $_ENV[$forbidden] !== '')) throw new \RuntimeException();
    }
    foreach (['mysql_root_password', 'napay_db_migrator_password'] as $secret) {
        if (file_exists('/run/secrets/' . $secret) || is_link('/run/secrets/' . $secret)) throw new \RuntimeException();
    }
    require_once __DIR__ . '/env.php';
    if (YII_DEBUG || env_bool('APP_DEBUG', false) || !preg_match('/^napay_[a-z0-9_]{1,26}$/D', env_required('DB_USERNAME'))) throw new \RuntimeException();
    $db = require __DIR__ . '/db.php';
    $runtime = env('NAPAY_WORKER_RUNTIME_PATH', '/tmp/napay-btcpay-worker');
    if (!is_string($runtime) || !str_starts_with($runtime, '/') || strpbrk($runtime, "\r\n\0") !== false) throw new \RuntimeException();
    return [
        'id' => 'napay-btcpay-worker',
        'basePath' => dirname(__DIR__),
        'runtimePath' => $runtime,
        'controllerNamespace' => 'app\\commands\\btcpay',
        'bootstrap' => ['log'],
        'components' => [
            'db' => $db,
            'cache' => ['class' => 'yii\\caching\\FileCache'],
            'log' => ['targets' => [['class' => 'yii\\log\\FileTarget', 'levels' => ['error', 'warning'], 'logVars' => []]]],
        ],
        'params' => ['defaultTimeZone' => 'UTC', 'localTimeZone' => 'Europe/Rome'],
    ];
} catch (\Throwable $error) {
    throw new \RuntimeException('invalid_btcpay_worker_environment');
}
