<?php

require_once __DIR__ . '/env.php';
$version = require __DIR__ . '/version.php';

return [
    /**
     * mailer 
     */
    'adminName' => env('ADMIN_NAME', 'Napay'),
    'adminPhone' => env('ADMIN_PHONE', ''),
    'adminEmail' => env_required('ADMIN_EMAIL'),
    'website' => env('APP_WEBSITE', ''),

    /**
     * app settings
     */
    'bsVersion' => '5.x', // this will set globally `bsVersion` to Bootstrap 5.x for all Krajee Extensions
    'defaultTimeZone' => env('DEFAULT_TIME_ZONE', 'UTC'),
    'localTimeZone' => env('LOCAL_TIME_ZONE', 'Europe/Rome'),

    'logoApplicazione' => '@web/bundles/site/images/logo.png',
    'icon-framework' => 'fa',  // Font Awesome Icon framework
    'version' => $version['version'],
    
    /**
     * Parametrizzazione società 
     */
    'webapp_society' => env('WEBAPP_SOCIETY', 'Napay'),
    'webapp_link' => env('WEBAPP_LINK', ''),

    /**
     * Set the password reset token expiration time.
     */
    'user.passwordResetTokenExpire' => 60 * 60, // 1 ora
    'user.authTimeout' => 30 * 24 * 60 * 60, // 30 giorni
    'user.passwordMinLength' => 8,

    /**
     * Set the list of usernames that we do not want to allow to users to take upon registration or profile change.
     */
    'user.spamNames' => 'admin|superadmin|creator|thecreator|username|administrator|root',

    /**
     * Set the secret for encrypt/decrypt
     * generated from: https://randomkeygen.com/
     */
    'secret_hash_key' => env_required('SECRET_HASH_KEY'),


    /**
     * Set the nonce timeout for activate user
     * 
     * 48 ore per cliccare sul link di attivazione
     */
    'nonce.timeout' => 2 * 24 * 60 * 60,
];
