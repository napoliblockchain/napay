<?php

$params = require __DIR__ . '/params.php';
$db = require __DIR__ . '/db.php';

$config = [
    'id' => 'basic',
    'name' => 'Napay',
    'language' => 'it-IT', // Specifies which language the application is targeted to
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'components' => [
        'formatter' => [
            'defaultTimeZone' => $params['defaultTimeZone'],
        ],
        'view' => [
            'on beforeRender' => [\app\components\theme\NapayTheme::class, 'onBeforeRender'],
        ],
        'request' => [
            // !!! insert a secret key in the following (if it is empty) - this is required by cookie validation
            'cookieValidationKey' => env_required('COOKIE_VALIDATION_KEY'),
            'csrfCookie' => ['httpOnly' => true, 'secure' => env_bool('HTTPS_ONLY', false), 'sameSite' => 'Lax'],
        ],
        'cache' => [
            'class' => 'yii\caching\FileCache',
        ],
        'user' => [
            'identityClass' => 'app\models\Users',
            'enableAutoLogin' => true,
            'identityCookie' => ['name' => '_identity', 'httpOnly' => true, 'secure' => env_bool('HTTPS_ONLY', false), 'sameSite' => 'Lax'],
        ],
        'session' => [
            'cookieParams' => ['httpOnly' => true, 'secure' => env_bool('HTTPS_ONLY', false), 'sameSite' => 'Lax'],
        ],
        'errorHandler' => [
            'errorAction' => 'site/error',
        ],
        'mailer' => require __DIR__ . '/mailer.php',
        'mutex' => [
            'class' => \yii\mutex\FileMutex::class,
            'mutexPath' => '@runtime/password-reset-locks',
            'dirMode' => 0700,
            'fileMode' => 0600,
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class' => \app\components\logging\AuthenticationFileTarget::class,
                    'levels' => ['error', 'warning'],
                    // Request/session dumps may contain passwords, tokens or cookies.
                    'logVars' => [],
                ],
            ],
        ],
        'db' => $db,
        'assetManager' => [
            'appendTimestamp' => false, // SET true for DEVELOPING 
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                // Regola per le API v1
                'api/v1/<controller:\w+>/<action:\w+>' => 'api/v1/<controller>/<action>',
            ],
        ],
    ],
    'container' => [
        'definitions' => [
            // paginazione uguale in tutti gli elenchi (le griglie creano yii\widgets\LinkPager)
            \yii\widgets\LinkPager::class => \app\components\web\LinkPager::class,
        ],
    ],
    'params' => $params,
];

return $config;
