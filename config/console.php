<?php

$params = require __DIR__ . '/params.php';
$db = require __DIR__ . '/db.php';

$config = [
    'id' => 'basic-console',
    'name' => 'Napay',
    // comandi del container cron che inviano mail (promemoria della quota): stessa lingua, fuso e mailer del web
    'language' => 'it-IT',
    'basePath' => dirname(__DIR__),
    'runtimePath' => env('NAPAY_CONSOLE_RUNTIME_PATH', dirname(__DIR__) . '/runtime'),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'app\commands',
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
        '@tests' => '@app/tests',
    ],
    'components' => [
        'formatter' => [
            'defaultTimeZone' => $params['defaultTimeZone'],
        ],
        'mailer' => require __DIR__ . '/mailer.php',
        'cache' => [
            'class' => 'yii\caching\FileCache',
        ],
        'log' => [
            'targets' => [
                [
                    'class' => 'yii\log\FileTarget',
                    'levels' => ['error', 'warning'],
                    // Bootstrap now carries role credentials: never dump environment/server variables.
                    'logVars' => [],
                ],
            ],
        ],
        'db' => $db,
    ],
    'params' => $params,
    /*
    'controllerMap' => [
        'fixture' => [ // Fixture generation command line.
            'class' => 'yii\faker\FixtureController',
        ],
    ],
    */
    
        'controllerMap' => [
            'migration' => [
                'class' => 'bizley\migration\controllers\MigrationController',
            ],
        ],
    
];

return $config;
