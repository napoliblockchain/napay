<?php
// Compose model contains passwords: stdin only; emit public paths/metadata, never the model.
if (PHP_SAPI !== 'cli') exit(1);
$stage = 'json';
try {
    $config = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $web = $config['services']['web'] ?? [];
    $bootstrap = $config['services']['bootstrap'] ?? [];
    $worker = $config['services']['worker-db-check'] ?? [];
    $w = $web['environment'] ?? []; $b = $bootstrap['environment'] ?? []; $k = $worker['environment'] ?? [];
    $stage = 'roles';
    foreach ([$w, $b, $k] as $env) if (($env['NAPAY_DB_ROLE_MODE'] ?? '') !== 'true') throw new RuntimeException();
    if (($w['NAPAY_DB_ROLE'] ?? '') !== 'web' || ($b['NAPAY_DB_ROLE'] ?? '') !== 'bootstrap' || ($k['NAPAY_DB_ROLE'] ?? '') !== 'worker'
        || ($w['NAPAY_DB_PASSWORD_FILE'] ?? null) !== '' || !empty($web['secrets']) || isset($w['NAPAY_WEB_DB_PASSWORD'])
        || ($b['DB_PASSWORD'] ?? null) !== '' || isset($k['DB_PASSWORD'])
        || ($b['NAPAY_WEB_DB_USERNAME'] ?? null) !== ($w['DB_USERNAME'] ?? null)
        || ($b['NAPAY_WEB_DB_PASSWORD'] ?? null) !== ($w['DB_PASSWORD'] ?? null)) throw new RuntimeException();
    $stage = 'identities';
    $users = [$w['DB_USERNAME'] ?? '', $b['DB_USERNAME'] ?? '', $k['DB_USERNAME'] ?? ''];
    if (count(array_unique($users)) !== 3) throw new RuntimeException();
    foreach ($users as $user) if (!is_string($user) || !preg_match('/^napay_[a-z0-9_]{1,26}$/D', $user)) throw new RuntimeException();
    foreach ([$b, $k] as $env) if (($env['DB_DATABASE'] ?? null) !== ($w['DB_DATABASE'] ?? null) || ($env['DB_DSN'] ?? null) !== ($w['DB_DSN'] ?? null)) throw new RuntimeException();
    $stage = 'secrets';
    $secretNames = static function (array $service): array { $names = array_column($service['secrets'] ?? [], 'source'); sort($names); return $names; };
    if ($secretNames($bootstrap) !== ['mysql_root_password', 'napay_db_migrator_password', 'napay_db_worker_password'] || $secretNames($worker) !== ['napay_db_worker_password']) throw new RuntimeException();
    $stage = 'dependencies';
    if (($web['depends_on']['bootstrap']['condition'] ?? '') !== 'service_completed_successfully' || ($bootstrap['depends_on']['mysql']['condition'] ?? '') !== 'service_healthy') throw new RuntimeException();
    $imports = array_values(array_filter($bootstrap['volumes'] ?? [], static fn($v) => ($v['target'] ?? '') === '/import'));
    if (count($imports) !== 1 || ($imports[0]['read_only'] ?? false) !== true) throw new RuntimeException();
    $stage = 'metadata';
    $values = [
        $config['secrets']['mysql_root_password']['file'] ?? null, $imports[0]['source'],
        $config['volumes']['mysql_data']['name'] ?? null, $bootstrap['user'] ?? null,
        $b['NAPAY_STACK_WAIT_SECONDS'] ?? '3600',
        $config['secrets']['napay_db_migrator_password']['file'] ?? null,
        $config['secrets']['napay_db_worker_password']['file'] ?? null,
    ];
    foreach ($values as $value) if (!is_string($value) || $value === '' || strpbrk($value, "\r\n") !== false) throw new RuntimeException();
    echo implode("\n", $values), "\n";
} catch (Throwable $error) { fwrite(STDERR, "Invalid role-separated Compose configuration ($stage).\n"); exit(1); }
