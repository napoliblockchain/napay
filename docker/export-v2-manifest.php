<?php

// Run against the offline source copy, never against the legacy napay schema.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
try {
    require dirname(__DIR__) . '/components/db/V2Bootstrap.php';
    $db = app\components\db\V2Bootstrap::connect(getenv('NAPAY_SOURCE_DSN') ?: '', getenv('NAPAY_SOURCE_USER') ?: 'root', getenv('NAPAY_SOURCE_PASSWORD') ?: '', 10);
    $manifest = app\components\db\V2Bootstrap::manifest($db, '/export/napay-v2.sql');
    $file = fopen('/export/manifest.json', 'x');
    if ($file === false) throw new RuntimeException('Manifest already exists.');
    fwrite($file, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    fclose($file);
    echo "V2 manifest exported; no application data printed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "V2 manifest export failed; source/data profile requires review.\n");
    exit(1);
}
