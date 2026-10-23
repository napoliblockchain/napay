<?php
// CLI fixture renderer: isolated SQLite DB, no operational credentials or payment APIs.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('YII_DEBUG', false);
define('YII_ENV', 'test');
define('YII_ENABLE_ERROR_HANDLER', false);
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__, 2);
$vendor = getenv('NAPAY_TEST_VENDOR') ?: $root . '/vendor';
if (!is_file($vendor . '/yiisoft/yii2/Yii.php')) {
    fwrite(STDERR, "Install project dependencies or set NAPAY_TEST_VENDOR to a compatible isolated test vendor directory.\n");
    exit(1);
}
$loader = require $vendor . '/autoload.php';
$loader->addPsr4('app\\', $root . '/', true);
require $vendor . '/yiisoft/yii2/Yii.php';
$output = getenv('NAPAY_UI_OUTPUT') ?: sys_get_temp_dir() . '/napay-ui-pilot';
foreach ([$output, $output . '/assets', $output . '/runtime'] as $directory) {
    if (!is_dir($directory)) mkdir($directory, 0700, true);
}
$checks = 0;
function check($condition, $message) {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function makeApp($role) {
    global $root, $vendor, $output;
    $app = new yii\web\Application([
        'id' => 'napay-ui-fixture', 'name' => 'Napay', 'basePath' => $root, 'vendorPath' => $vendor,
        'runtimePath' => $output . '/runtime', 'language' => 'en-US',
        'aliases' => ['@webroot' => $root . '/web', '@web' => '', '@bower' => '@vendor/bower-asset', '@npm' => '@vendor/npm-asset'],
        'components' => [
            'request' => ['cookieValidationKey' => 'fixture-only', 'scriptFile' => $root . '/web/index.php', 'scriptUrl' => '/index.php', 'hostInfo' => 'http://127.0.0.1:18129'],
            'user' => ['identityClass' => app\models\Users::class, 'enableSession' => false, 'enableAutoLogin' => false],
            'session' => ['class' => yii\web\Session::class, 'savePath' => sys_get_temp_dir()],
            'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
            'assetManager' => ['basePath' => $output . '/assets', 'baseUrl' => '/assets'],
            'view' => ['on beforeRender' => [app\components\theme\NapayTheme::class, 'onBeforeRender']],
            'urlManager' => ['enablePrettyUrl' => true, 'showScriptName' => false],
        ],
        'params' => ['bsVersion' => '5.x', 'icon-framework' => 'fa', 'webapp_link' => 'https://napay.local', 'webapp_society' => 'Napay', 'version' => '2.0'],
    ]);
    $app->db->createCommand('CREATE TABLE users (id INTEGER PRIMARY KEY, privilege_id INTEGER, first_name TEXT, last_name TEXT, picture TEXT, authKey TEXT, username TEXT)')->execute();
    $app->db->createCommand('CREATE TABLE privileges (id INTEGER PRIMARY KEY, level INTEGER)')->execute();
    $app->db->createCommand()->insert('privileges', ['id' => 1, 'level' => $role])->execute();
    $app->db->createCommand()->insert('users', ['id' => 1, 'privilege_id' => 1, 'first_name' => 'Ada <svg onload="window.pwned=true">', 'last_name' => 'Test', 'picture' => '/bundles/site/images/logo.png', 'authKey' => 'fixture', 'username' => 'fixture'])->execute();
    $app->user->switchIdentity(app\models\Users::findOne(1));
    $controller = new yii\web\Controller('site', $app);
    $controller->action = new yii\base\Action('index', $controller);
    $app->controller = $controller;
    return $app;
}
foreach (['admin' => 50, 'merchant' => 30, 'user' => 0] as $name => $role) {
    $app = makeApp($role);
    $html = $app->controller->render('index');
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $menuLinks = $xpath->query('//*[@id="napay-sidebar-menu"]//ul/li/a');
    check($menuLinks->length === ($role === 50 ? 9 : ($role === 30 ? 5 : 1)), 'Original role menu items preserved for ' . $name . ': got ' . $menuLinks->length);
    check(substr_count($html, 'card card-sm h-100') === ($role === 50 ? 4 : ($role === 30 ? 3 : 2)), 'Dashboard permissions preserved for ' . $name);
    check(substr_count($html, '/tabler/css/tabler.min.css') === 1, 'One Tabler CSS for ' . $name);
    check(substr_count($html, '/tabler/js/bootstrap.bundle.min.js') === 1, 'One Bootstrap JS for ' . $name);
    check(!str_contains($html, 'adminlte.min.css') && !str_contains($html, '/css/adminlte.css') && !str_contains($html, 'bootstrap.css'), 'No legacy framework CSS for ' . $name);
    check(!str_contains($html, 'use.fontawesome.com'), 'Icons resolve to local assets');
    check(!str_contains($html, '<svg onload='), 'Profile name is HTML encoded');
    check($xpath->query('//a[@href="/site/logout" and @data-method="post"]')->length === 1, 'Logout keeps POST contract');
    check($xpath->query('//meta[@name="csrf-token"]')->length === 1, 'CSRF meta preserved');
    file_put_contents($output . '/' . $name . '.html', $html);
}
$app = makeApp(50);
$view = $app->view;
// Simulate the content view's beforeRender event, before any widgets register assets.
app\components\theme\NapayTheme::onBeforeRender(new yii\base\ViewEvent());
ob_start();
$form = yii\bootstrap5\ActiveForm::begin(['id' => 'pilot-form', 'action' => '/fixture-submit']);
$model = new yii\base\DynamicModel(['description' => 'Bozza POS']);
$model->addRule(['description'], 'required');
echo $form->field($model, 'description')->textInput(['id' => 'pilot-description']);
echo kartik\select2\Select2::widget(['name' => 'network', 'value' => 'ETH', 'data' => ['ETH' => 'Ethereum', 'POA' => 'POA'], 'options' => ['id' => 'pilot-network']]);
echo kartik\date\DatePicker::widget(['name' => 'date', 'options' => ['id' => 'pilot-date'], 'value' => '2026-10-23', 'pluginOptions' => ['format' => 'yyyy-mm-dd', 'autoclose' => true]]);
echo yii\helpers\Html::submitButton('Salva', ['class' => 'btn btn-primary mt-3']);
yii\bootstrap5\ActiveForm::end();
yii\bootstrap5\Modal::begin(['id' => 'pilot-modal', 'title' => 'Dettagli POS', 'toggleButton' => ['label' => 'Apri dettagli', 'id' => 'pilot-open-modal', 'class' => 'btn btn-outline-primary mt-3']]);
echo '<p>Contenuto di prova, nessuna transazione.</p>';
yii\bootstrap5\Modal::end();
$formHtml = ob_get_clean();
$profileSettings = $view->renderFile($root . '/views/users/_details-settings.php', ['model' => $app->user->identity]);
$html = $view->renderFile($root . '/views/layouts/main.php', ['content' => '<section class="card"><div class="card-body">' . $formHtml . $profileSettings . '</div></section>']);
check(substr_count($html, '/tabler/js/bootstrap.bundle.min.js') === 1, 'Widgets and layout share one Bootstrap JS');
check(!str_contains($html, 'sidebar-collapse.js'), 'Profile uses new sidebar renderer, no legacy reload handler');
file_put_contents($output . '/form.html', $html);
$menu = app\widgets\TablerSidebarMenu::widget(['items' => [
    ['label' => '<script>bad</script>', 'url' => ['site/index']],
    ['label' => 'Hidden', 'url' => ['secret/index'], 'visible' => false],
]]);
check(str_contains($menu, '&lt;script&gt;bad&lt;/script&gt;') && !str_contains($menu, 'secret/index'), 'Menu labels encoded and hidden items omitted');
// Only validated cookie values from Yii may supply the legacy sidebar default.
$cookies = $app->request->cookies;
$cookies->readOnly = false;
foreach (['invalid' => false, '{"body":"sidebar-collapse"}' => true, '{"body":""><script>bad</script>"}' => false] as $raw => $expected) {
    $cookies->add(new yii\web\Cookie(['name' => 'collapsed', 'value' => $raw]));
    check(app\components\theme\NapayTheme::legacySidebarFolded() === $expected, 'Legacy cookie is allowlisted');
}
// Standalone login registration must retain its original asset graph.
$app = makeApp(50);
$app->controller->layout = 'login';
$app->view->renderFile($root . '/views/site/index.php');
$asset = $app->assetManager->getBundle(app\assets\AppAsset::class, false);
check(get_class($asset) === app\assets\AppAsset::class, 'Login retains original AppAsset');
check(!isset($app->assetManager->bundles['yii\\bootstrap5\\BootstrapPluginAsset']), 'Login does not register Tabler plugin');
echo "PASS {$checks} Yii layout checks; fixture HTML written to {$output}\n";
