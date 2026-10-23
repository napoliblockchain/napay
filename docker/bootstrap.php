<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
try {
    require __DIR__ . '/bootstrap-common.php';
    env_required('COOKIE_VALIDATION_KEY');
    env_required('SECRET_HASH_KEY');
    env_required('ADMIN_EMAIL');
    $config = napayBootstrapConfig();
    $timeout = filter_var(env('NAPAY_BOOTSTRAP_SECONDS', '1800'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 86400]]);
    if ($timeout === false) throw new RuntimeException('Invalid bootstrap budget.');
    $db = app\components\db\V2Bootstrap::connect($config['dsn'], $config['user'], $config['password'], $config['timeout']);
    $client = ['socket' => '/run/napay-mysql/mysqld.sock', 'user' => $config['user'], 'password' => $config['password']];
    $result = app\components\db\V2Bootstrap::run($db, dirname(__DIR__), '/import', $client, $timeout);
    fwrite(STDOUT, 'Napay bootstrap: ' . $result . ".\n");
} catch (Throwable $error) {
    // Never log PDO exceptions, SQL fragments, credentials or dataset contents.
    fwrite(STDERR, "Napay bootstrap failed; application remains blocked. Check dataset, schema, dependencies and persistent state.\n");
    exit(1);
}
