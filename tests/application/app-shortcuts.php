<?php

use app\widgets\AppShortcuts;
use yii\web\IdentityInterface;

if (PHP_SAPI !== 'cli') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require $root . '/vendor/yiisoft/yii2/Yii.php';

$app = new yii\web\Application([
    'id' => 'app-shortcuts-test',
    'basePath' => $root,
    'language' => 'it-IT',
    'components' => [
        'user' => ['identityClass' => IdentityInterface::class, 'enableSession' => false],
        'i18n' => ['translations' => ['app' => ['class' => yii\i18n\PhpMessageSource::class]]],
    ],
    'params' => ['walletAppUrl' => 'https://wallet.local', 'posAppUrl' => 'https://pos.localhost:30443/'],
]);

$checks = 0;
function check(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
}

check(AppShortcuts::widget() === '', 'Guest receives no authenticated menu');
$public = AppShortcuts::widget(['publicPage' => true]);
check(str_contains($public, 'data-napay-app-shortcuts="public"'), 'Public login links render for guests');
check(substr_count($public, 'rel="noopener noreferrer"') === 2, 'Both public links open securely');
check(substr_count($public, '<svg ') === 2, 'Public icons do not depend on dashboard assets');
check(!str_contains($public, 'data-bs-toggle="dropdown"'), 'Public page has no authenticated menu');

$app->user->setIdentity(new class implements IdentityInterface {
    public static function findIdentity($id) { return null; }
    public static function findIdentityByAccessToken($token, $type = null) { return null; }
    public function getId() { return 1; }
    public function getAuthKey() { return null; }
    public function validateAuthKey($authKey) { return false; }
});

foreach ([false, true] as $publicPage) {
    $html = $publicPage ? $public : AppShortcuts::widget();
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR);
    $xpath = new DOMXPath($doc);
    check($xpath->query('//a')->length === 2, 'Both configured links appear');
    foreach ($app->params as $url) {
        check($xpath->query('//a[@href="' . $url . '" and @target="_blank" and @rel="noopener noreferrer"]')->length === 1,
            'Configured URL opens securely in a new tab');
    }
    check(str_contains($html, 'Apri Wallet TTS') && str_contains($html, 'Apri POS'), 'Italian labels');
    check($publicPage ? str_contains($html, 'col-12 col-sm-6') : str_contains($html, 'data-bs-toggle="dropdown"'), 'Expected menu or responsive grid');
}

foreach (['', 'javascript:alert(1)', '//wallet.local', 'http://wallet.local', 'https://user@wallet.local',
    'https://wallet.local?token=fixture', 'https://wallet.local/#test', 'https://wallet.local/path',
    'https://wallet.local:65536', "https://wallet.local\n", 'https://wallet.local"'] as $url) {
    $app->params = ['walletAppUrl' => $url];
    check(AppShortcuts::widget() === '' && AppShortcuts::widget(['publicPage' => true]) === '', 'Invalid URL omitted');
}
$app->params = ['walletAppUrl' => 'https://wallet.local', 'posAppUrl' => ''];
check(substr_count(AppShortcuts::widget(), '<a ') === 1, 'One configured app remains visible');
$app->params = [];
check(AppShortcuts::widget() === '' && AppShortcuts::widget(['publicPage' => true]) === '', 'No empty section for missing settings');

echo "PASS $checks app shortcut checks\n";
