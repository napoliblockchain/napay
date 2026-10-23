<?php
require_once __DIR__ . '/env.php';

return [
    'class' => 'yii\db\Connection',
    'dsn' => env_required('DB_DSN'),
    'username' => env_required('DB_USERNAME'),
    'password' => env_required('DB_PASSWORD'),
    'charset' => 'utf8',

    // Schema cache options (for production environment)
    //'enableSchemaCache' => true,
    //'schemaCacheDuration' => 60,
    //'schemaCache' => 'cache',
];
