<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
try {
    require __DIR__ . '/bootstrap-common.php';
    require_once dirname(__DIR__) . '/components/legacy/LegacyImportException.php';
    require_once dirname(__DIR__) . '/components/legacy/LegacyImporter.php';
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
    // TIP-020: import automatico dell'associazione dal DB della vecchia app, a ogni avvio finché configurato
    if (app\components\legacy\LegacyImporter::active()) {
        $dsn = env_required('LEGACY_DB_DSN');
        if (!str_starts_with($dsn, 'mysql:')) throw new app\components\legacy\LegacyImportException('Legacy MySQL DSN required.');
        $legacy = app\components\db\V2Bootstrap::connect($dsn . ';charset=utf8mb4', env_required('LEGACY_DB_USERNAME'), env_required('LEGACY_DB_PASSWORD'), $config['timeout']);
        $report = app\components\legacy\LegacyImporter::run($legacy, $db, is_dir('/legacy-files') ? '/legacy-files' : null);
        // solo conteggi: nessun dato personale nel log
        fwrite(STDOUT, 'Napay legacy import: ' . json_encode($report, JSON_THROW_ON_ERROR) . ".\n");
    }
} catch (app\components\legacy\LegacyImportException $error) {
    fwrite(STDERR, 'Napay legacy import failed: ' . $error->getMessage() . " Application remains blocked.\n");
    exit(1);
} catch (Throwable $error) {
    // Never log PDO exceptions, SQL fragments, credentials or dataset contents.
    fwrite(STDERR, "Napay bootstrap failed; application remains blocked. Check dataset, schema, dependencies and persistent state.\n");
    exit(1);
}
