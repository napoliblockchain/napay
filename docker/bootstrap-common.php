<?php

require_once dirname(__DIR__) . '/config/env.php';
require_once dirname(__DIR__) . '/components/db/V2Bootstrap.php';

function napayBootstrapConfig(): array
{
    $dsn = env_required('DB_DSN');
    if (!str_starts_with($dsn, 'mysql:')) throw new RuntimeException('MySQL DSN required.');
    $parts = [];
    foreach (explode(';', substr($dsn, 6)) as $part) {
        if ($part === '') continue;
        $pair = explode('=', $part, 2);
        if (count($pair) !== 2 || !in_array($pair[0], ['host', 'port', 'dbname'], true) || isset($parts[$pair[0]])) throw new RuntimeException('Unsupported MySQL DSN option.');
        $parts[$pair[0]] = $pair[1];
    }
    if (!preg_match('/^[a-zA-Z0-9_.-]+$/D', $parts['host'] ?? '')
        || !preg_match('/^[a-zA-Z0-9_]+$/D', $parts['dbname'] ?? '')
        || ($parts['dbname'] !== env_required('DB_DATABASE'))) throw new RuntimeException('Database target configuration mismatch.');
    $port = filter_var($parts['port'] ?? 3306, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    if ($port !== 3306 || $parts['host'] !== 'mysql') throw new RuntimeException('Bootstrap requires the local mysql service on its internal port.');
    $timeout = filter_var(env('NAPAY_DB_WAIT_SECONDS', '120'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3600]]);
    if ($timeout === false) throw new RuntimeException('Invalid database readiness budget.');
    return ['dsn' => $dsn . ';charset=utf8mb4', 'host' => $parts['host'], 'port' => $port, 'user' => env_required('DB_USERNAME'), 'password' => env_required('DB_PASSWORD'), 'timeout' => $timeout];
}
