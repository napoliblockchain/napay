<?php

$params = require __DIR__ . '/params.php';
$db = require __DIR__ . '/db.php';

$config = [
    'id' => 'basic',
    'name' => 'Napay',
    'language' => 'it-IT', // Specifies which language the application is targeted to
    'basePath' => dirname(__DIR__),
    // registro attività automatico (components/audit, come in Aura)
    'bootstrap' => ['log', \app\components\audit\AuditBootstrap::class],
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
            // IP reale del client (registro attività) solo dai proxy fidati, es. il gateway: TRUSTED_PROXIES=CIDR,CIDR
            'trustedHosts' => array_fill_keys(array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))), ['X-Forwarded-For', 'X-Forwarded-Proto']),
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
            // registra CSRF non valido e 403 nel registro di sicurezza
            'class' => \app\components\audit\ErrorHandler::class,
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
                // link esterno per pagare la quota (come il vecchio loginquota): login, poi pagina Paga quota
                'quota' => 'pay-fee/index',
                // API server-to-server dei server POS (SIP-021): tutti i metodi qui, la action accetta solo POST (405)
                'api/pos/v1/sessions' => 'posapi/sessions/create',
                // Regola per le API v1
                'api/v1/<controller:\w+>/<action:\w+>' => 'api/v1/<controller>/<action>',
            ],
        ],
    ],
    'modules' => [
        'posapi' => ['class' => \app\modules\posapi\Module::class],
    ],
    'container' => [
        'definitions' => [
            // paginazione uguale in tutti gli elenchi (le griglie creano yii\widgets\LinkPager)
            \yii\widgets\LinkPager::class => \app\components\web\LinkPager::class,
            // accessi negati registrati in tutti i controller
            \yii\filters\AccessControl::class => \app\components\audit\AuditedAccessControl::class,
        ],
    ],
    'params' => $params,
];

return $config;
