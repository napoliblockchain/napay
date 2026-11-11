<?php
// Isolated v2 fixture only: every write is rolled back, no payment APIs.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
require $root . '/docker/bootstrap-common.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php';
require $root . '/vendor/yiisoft/yii2/Yii.php';
$config = napayBootstrapConfig();
$assets = sys_get_temp_dir() . '/napay-merchant-assets';
if (!is_dir($assets)) mkdir($assets, 0700, true);
$app = new yii\web\Application([
    'id' => 'napay-merchants-fixture', 'basePath' => $root,
    'runtimePath' => sys_get_temp_dir() . '/napay-merchant-runtime',
    'aliases' => ['@web' => '', '@webroot' => $root . '/web', '@bower' => '@vendor/bower-asset', '@npm' => '@vendor/npm-asset'],
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => $config['dsn'], 'username' => $config['user'], 'password' => $config['password']],
        'request' => ['cookieValidationKey' => 'fixture-only', 'scriptFile' => $root . '/web/index.php', 'scriptUrl' => '/index.php', 'hostInfo' => 'http://fixture.invalid', 'url' => '/merchants/update'],
        'user' => ['identityClass' => app\models\Users::class, 'enableSession' => false],
        'session' => ['class' => yii\web\Session::class, 'savePath' => sys_get_temp_dir()],
        'assetManager' => ['basePath' => $assets, 'baseUrl' => '/assets'],
    ],
]);
$app->session->open();
class FixtureMerchantsController extends app\controllers\MerchantsController {
    public function record($id) { return $this->findModel($id); }
    public function render($view, $params = []) {
        return $this->view->renderFile($this->getViewPath() . '/_form.php', $params, $this);
    }
}
$controller = new FixtureMerchantsController('merchants', $app);
$controller->action = new yii\base\Action('update', $controller);
$app->controller = $controller;
$checks = 0;
function check($condition, $label) {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks;
}
function rejected(callable $callback, $type, $label) {
    try { $callback(); } catch (Throwable $error) { check($error instanceof $type, $label); return; }
    check(false, $label);
}
function actor($role) {
    return app\models\Users::findByUsername('runtime-' . $role);
}
function record($model) {
    global $controller;
    return $controller->record(app\components\Crypt::encrypt((string) $model->id));
}
$transaction = $app->db->beginTransaction();
try {
    $records = [];
    foreach (['merchant', 'other'] as $role) {
        $model = app\models\Merchants::findOne(['user_id' => actor($role)->id]);
        check($model !== null, 'Merchant fixture exists');
        $records[$role] = $model;
    }
    $app->user->switchIdentity(actor('merchant'));
    check(record($records['merchant'])->id === $records['merchant']->id, 'Merchant reads own record');
    rejected(fn() => record($records['other']), yii\web\ForbiddenHttpException::class, 'Foreign merchant record denied despite valid encrypted ID');
    $foreign = app\components\Crypt::encrypt((string) $records['other']->id);
    rejected(fn() => $controller->actionUpdate($foreign), yii\web\ForbiddenHttpException::class, 'Foreign update denied');
    rejected(fn() => $controller->actionDelete($foreign), yii\web\ForbiddenHttpException::class, 'Foreign delete denied before mutation');
    foreach (['user', 'other', 'operator', 'higher'] as $role) {
        $app->user->switchIdentity(actor($role));
        rejected(fn() => record($records['other']), yii\web\ForbiddenHttpException::class, 'Existing role boundary preserved: ' . $role);
    }
    $app->user->switchIdentity(actor('admin'));
    check(record($records['merchant'])->id === $records['merchant']->id, 'Administrator reads merchant record');
    foreach (['bad!', ['1'], str_repeat('a', 1025), base64_encode('invalid'), app\components\Crypt::encrypt('-1'), app\components\Crypt::encrypt('1 OR 1=1'), app\components\Crypt::encrypt('2147483648'), app\components\Crypt::encrypt('2147483647')] as $invalid) {
        rejected(fn() => $controller->record($invalid), yii\web\NotFoundHttpException::class, 'Invalid or missing merchant ID controlled');
    }
    $own = $records['merchant'];
    $encrypted = app\components\Crypt::encrypt((string) $own->id);
    $app->request->setBodyParams([]);
    $html = $controller->actionUpdate($encrypted);
    $document = new DOMDocument(); @$document->loadHTML($html, LIBXML_NONET);
    $hidden = (new DOMXPath($document))->query('//input[@name="Merchants[user_id]"]/@value');
    check($hidden->length === 1 && $hidden->item(0)->nodeValue === (string) $own->user_id, 'Update form preserves actual owner, not administrator');
    check((new DOMXPath($document))->query('//input[@name="Merchants[vatNumber]" and @readonly]')->length === 1, 'Administrator VAT input is read-only');
    foreach (['admin', 'merchant'] as $role) {
        $app->user->switchIdentity(actor($role));
        foreach ([actor('other')->id, ['malformed']] as $owner) {
            $vat = $own->vatNumber;
            $app->request->setBodyParams(['Merchants' => ['description' => 'Edited by ' . $role, 'user_id' => $owner, 'vatNumber' => '99999999999', 'id' => $records['other']->id]]);
            $result = $controller->actionUpdate(app\components\Crypt::encrypt((string) $own->id));
            $own->refresh();
            check($result instanceof yii\web\Response && $result->statusCode === 302, 'Valid merchant details update succeeds');
            check((string) $own->user_id === (string) actor('merchant')->id, 'Posted owner substitution ignored server-side');
            check($own->description === 'Edited by ' . $role, 'Non-ownership details remain editable');
            check($own->vatNumber === $vat, 'VAT unchanged for administrator and merchant despite crafted POST');
        }
    }
    $app->request->setBodyParams([]);
    $app->request->setQueryParams(['id' => $records['other']->id]);
    $html = $controller->actionCompany();
    $document = new DOMDocument(); @$document->loadHTML($html, LIBXML_NONET);
    $hidden = (new DOMXPath($document))->query('//input[@name="Merchants[user_id]"]/@value');
    check($hidden->length === 1 && $hidden->item(0)->nodeValue === (string) actor('merchant')->id, 'Own company ignores requested foreign identifier');
    $savepoint = $app->db->beginTransaction();
    try {
        $duplicate = new app\models\Merchants($own->getAttributes(null, ['id']));
        check($duplicate->save(), 'Duplicate-company fixture validates');
        rejected(fn() => $controller->actionCompany(), yii\web\NotFoundHttpException::class, 'Multiple companies never select an arbitrary record');
    } finally {
        $savepoint->rollBack();
    }
    foreach (['admin', 'user', 'other', 'operator'] as $role) {
        $app->user->switchIdentity(actor($role));
        rejected(fn() => $controller->actionCompany(), yii\web\ForbiddenHttpException::class, 'Own company restricted to merchant role: ' . $role);
    }
    $missing = clone actor('merchant'); $missing->id = 2147483647;
    $app->user->switchIdentity($missing);
    rejected(fn() => $controller->actionCompany(), yii\web\NotFoundHttpException::class, 'Merchant without company gets controlled unavailable response');
    $app->user->switchIdentity(null);
    rejected(fn() => record($records['other']), yii\web\ForbiddenHttpException::class, 'Missing identity denied');
} finally {
    $transaction->rollBack();
    $app->session->close();
}
echo "PASS {$checks} merchant ownership checks (fixture transaction rolled back)\n";
