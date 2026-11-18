<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    require __DIR__ . '/bootstrap-common.php';
    $config = napayBootstrapConfig();
    $db = app\components\db\V2Bootstrap::connect($config['dsn'], $config['user'], $config['password'], $config['timeout']);
    if (env_bool('NAPAY_DB_ROLE_MODE', false)) {
        app\components\db\V2Bootstrap::readyRestricted($db, dirname(__DIR__), env_required('NAPAY_DB_ROLE'));
    } else {
        app\components\db\V2Bootstrap::ready($db, dirname(__DIR__));
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Napay is not ready: database bootstrap/schema must complete successfully.\n");
    exit(1);
}
