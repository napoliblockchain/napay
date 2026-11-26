<?php
// Server-side encrypted-ID tests, only disposable fixture records, no HTTP bypass.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/docker/bootstrap-common.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
$root = dirname(__DIR__, 2);
$config = napayBootstrapConfig();
$app = new yii\web\Application([
    'id' => 'napay-users-access-fixture', 'basePath' => $root,
    'runtimePath' => sys_get_temp_dir() . '/napay-access-runtime',
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => $config['dsn'], 'username' => $config['user'], 'password' => $config['password']],
        'request' => ['cookieValidationKey' => 'fixture-only', 'scriptFile' => $root . '/web/index.php', 'scriptUrl' => '/index.php', 'hostInfo' => 'http://fixture.invalid'],
        'user' => ['identityClass' => app\models\Users::class, 'enableSession' => false],
        'session' => ['class' => yii\web\Session::class, 'savePath' => sys_get_temp_dir()],
    ],
]);
class FixtureUsersController extends app\controllers\UsersController {
    public function record($id) { return $this->findModel($id); }
}
$controller = new FixtureUsersController('users', $app);
$checks = 0;
function check($condition, $label) {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks;
}
function identity($role) {
    return app\models\Users::findByUsername('runtime-' . $role);
}
function rejected(callable $callback, $type, $label) {
    try { $callback(); } catch (Throwable $error) { check($error instanceof $type, $label); return; }
    check(false, $label);
}
function record($role) {
    global $controller;
    return $controller->record(app\components\Crypt::encrypt((string) identity($role)->id));
}
foreach (['user', 'merchant'] as $role) {
    $app->user->switchIdentity(identity($role));
    check(record($role)->id === identity($role)->id, 'Own profile readable');
    rejected(fn() => record('other'), yii\web\ForbiddenHttpException::class, 'Other basic profile denied');
    rejected(fn() => record('admin'), yii\web\ForbiddenHttpException::class, 'Admin profile denied');
}
$app->user->switchIdentity(identity('admin'));
check(record('user')->id === identity('user')->id, 'Admin reads lower role');
check(record('admin')->id === identity('admin')->id, 'Admin reads own profile');
rejected(fn() => record('higher'), yii\web\ForbiddenHttpException::class, 'Admin cannot access higher role');
$app->user->switchIdentity(identity('operator'));
check(record('user')->id === identity('user')->id, 'Existing level-40 management boundary preserved');
rejected(fn() => record('admin'), yii\web\ForbiddenHttpException::class, 'Level-40 cannot access higher admin');
foreach (['', 'not-valid!', str_repeat('A', 1025), base64_encode('invalid cipher')] as $token) {
    rejected(fn() => $controller->record($token), yii\web\NotFoundHttpException::class, 'Malformed encrypted identifier rejected');
}
foreach (['0', '-1', '2147483648', '1 OR 1=1', '999999'] as $plain) {
    rejected(fn() => $controller->record(app\components\Crypt::encrypt($plain)), yii\web\NotFoundHttpException::class, 'Invalid or missing numeric ID rejected');
}
foreach (['disabled', 'blocked'] as $role) {
    $user = identity($role);
    check(!$user->validateStatus(), 'Non-active status denied');
    check(app\models\Users::findIdentity($user->id) === null, 'Non-active session identity denied');
    check(app\models\Users::findIdentityByAccessToken($user->accessToken) === null, 'Non-active API identity denied');
}
$sessionId = $app->session->getId();
$app->session->close();
$target = new app\components\logging\AuthenticationFileTarget(['logVars' => []]);
foreach (['yii\\web\\User::renewAuthStatus', 'yii\\web\\User::getIdentityAndDurationFromCookie'] as $category) {
    $line = $target->formatMessage(['Invalid session auth key attempted for user 7 from 127.0.0.1: fixture-private-key', yii\log\Logger::LEVEL_WARNING, $category, microtime(true), []]);
    check(!str_contains($line, 'fixture-private-key') && str_contains($line, '[REDACTED]'), 'Rejected-identity audit does not expose credentials');
    check($sessionId !== '' && !str_contains($line, $sessionId), 'Audit prefix does not expose session ID');
}
echo "PASS {$checks} users record-level checks\n";
