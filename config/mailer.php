<?php

require_once __DIR__ . '/env.php';
$mode = env('MAIL_TRANSPORT', 'file');
if (!in_array($mode, ['file', 'smtp'], true)) throw new RuntimeException('Invalid MAIL_TRANSPORT.');
$config = [
    'class' => \app\components\mail\PrivateMailer::class,
    'viewPath' => '@app/mail',
    'useFileTransport' => $mode === 'file',
    'fileTransportPath' => '@runtime/mail',
    'enableMailerLogging' => false,
];
if ($mode === 'smtp') {
    $scheme = env('MAIL_SMTP_SCHEME', 'smtps');
    $port = filter_var(env('MAIL_SMTP_PORT', '465'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    $host = env_required('MAIL_SMTP_HOST');
    if (!in_array($scheme, ['smtp', 'smtps'], true) || $port === false || !preg_match('/^[a-zA-Z0-9.-]+$/D', $host)) {
        throw new RuntimeException('Invalid SMTP configuration.');
    }
    $config['transport'] = ['scheme' => $scheme, 'host' => $host, 'port' => $port,
        'username' => env_required('MAIL_SMTP_USERNAME'), 'password' => env_required('MAIL_SMTP_PASSWORD'),
        'options' => ['require_tls' => true]];
}
return $config;
