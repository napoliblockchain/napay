<?php
// Registration and activation against a disposable DB/file-mail only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime' || env('MAIL_TRANSPORT') !== 'file') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
$app = new yii\web\Application(require $root . '/config/web.php');
$app->request->scriptFile = $root . '/web/index.php';
$app->request->scriptUrl = '/index.php';
$app->request->hostInfo = 'http://127.0.0.1';
$app->session->open();
$checks = 0; $jar = tempnam('/tmp', 'napay-activation-');
function check($condition, $label) {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks; echo 'PASS: ' . $label . "\n";
}
function request($path, ?array $form = null, $host = null) {
    global $jar;
    $curl = curl_init('http://127.0.0.1' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($host !== null) curl_setopt($curl, CURLOPT_HTTPHEADER, ['Host: ' . $host]);
    if ($form !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form)]);
    $raw = curl_exec($curl);
    if ($raw === false) throw new RuntimeException('Activation HTTP transport failed.');
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $result = ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    curl_close($curl); return $result;
}
function csrf($page) {
    $doc = new DOMDocument(); @$doc->loadHTML($page['body'], LIBXML_NONET);
    $nodes = (new DOMXPath($doc))->query('//meta[@name="csrf-token"]/@content');
    if ($nodes->length !== 1) throw new RuntimeException('Activation CSRF unavailable.');
    return $nodes->item(0)->nodeValue;
}
function member() { return app\models\Users::findByUsername('activation-member'); }
function pathFor($user, $signature = null) {
    return '/site/activate?' . http_build_query(['id' => app\components\Crypt::sqlEncrypt((string) $user->id), 'sign' => $signature ?? $user->getActivationSignature()], '', '&', PHP_QUERY_RFC3986);
}
function snapshot($user) {
    return hash('sha256', json_encode($user->getAttributes(), JSON_THROW_ON_ERROR));
}
function pending($seconds = 0, $status = 0, $provider = 'SELF') {
    $user = member();
    app\models\Users::updateAll(['is_active' => $status, 'oauth_provider' => $provider,
        'authKey' => (string) (time() + $seconds) . '123456', 'accessToken' => Yii::$app->security->generateRandomString(60)], ['id' => $user->id]);
    return member();
}
function resend($email = 'activation@example.invalid', $host = null) {
    $page = request('/site/resend-activation', null, $host);
    return request('/site/resend-activation', ['_csrf' => csrf($page), 'ActivationResendForm' => ['email' => $email]], $host);
}
function recoveredLink($filesBefore): string {
    global $root;
    $files = array_values(array_diff(glob($root . '/runtime/mail/*.eml') ?: [], $filesBefore));
    check(count($files) === 1, 'Recovery sends only member email, no admin notification');
    $body = quoted_printable_decode(file_get_contents($files[0]));
    check(!str_contains($body, 'attacker.example.invalid'), 'Recovery mail origin ignores Host');
    check(preg_match('~https://napay\.example\.invalid/site/activate\?[^"\s<>]+~', $body, $match) === 1, 'Recovery mail contains trusted activation URL');
    $url = html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);
}
try {
    $beforeMail = glob($root . '/runtime/mail/*.eml') ?: [];
    $page = request('/site/signup', null, 'attacker.example.invalid');
    check($page['status'] === 200, 'Signup form renders');
    $form = ['_csrf' => csrf($page), 'SignupForm' => ['username' => 'activation-member', 'email' => 'activation@example.invalid',
        'first_name' => 'Activation <svg onload="window.pwned=true">', 'last_name' => 'Fixture',
        'password' => 'activation-fixture-password', 'repeat_password' => 'activation-fixture-password', 'is_merchant' => '0'],
        'UserConsensus' => ['consenso_statuto' => '1', 'consenso_privacy' => '1', 'consenso_condizioni' => '1', 'consenso_condizioni_pos' => '0', 'consenso_marketing' => '0']];
    foreach ([['password' => '', 'repeat_password' => ''], ['password' => str_repeat('x', 73), 'repeat_password' => str_repeat('x', 73)],
        ['is_merchant' => '2'], ['first_name' => ['invalid']], ['username' => ['invalid']]] as $invalid) {
        $badForm = $form;
        $badForm['SignupForm'] = array_replace($form['SignupForm'], $invalid);
        check(request('/site/signup', $badForm, 'attacker.example.invalid')['status'] === 200 && member() === null,
            'Invalid signup input cannot create user');
    }
    check(request('/site/signup', $form, 'attacker.example.invalid')['status'] === 302, 'Signup persists fixture and consent');
    $user = member();
    check($user !== null && (int) $user->is_active === 0 && $user->oauth_provider === 'SELF', 'New member starts pending');
    check(app\models\UserConsensus::find()->where(['user_id' => $user->id])->count() == 1, 'Consent record exists');
    $identity = [$user->oauth_uid, $user->password, $user->privilege_id];
    $pendingCredentials = [$user->authKey, $user->accessToken];
    $files = array_values(array_diff(glob($root . '/runtime/mail/*.eml') ?: [], $beforeMail));
    check(count($files) === 2, 'Signup captures member/admin emails locally');
    $message = '';
    foreach ($files as $file) {
        $raw = file_get_contents($file);
        check(!str_contains($raw, 'attacker.example.invalid'), 'Signup mail ignores untrusted Host');
        if (preg_match('/^To: activation@example\.invalid\r?$/m', $raw)) $message = quoted_printable_decode($raw);
    }
    check(preg_match('~https://napay\.example\.invalid/site/activate\?[^"\s<>]+~', $message, $match) === 1, 'Mail carries trusted HTTPS activation link');
    $url = html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);
    parse_str(parse_url($url, PHP_URL_QUERY), $credentials);
    $signature = $credentials['sign'];
    $view = $app->mailer->compose('newAccountUser', ['user' => $user])->getSymfonyEmail()->getHtmlBody();
    check(!str_contains($view, '<svg onload=') && str_contains($view, '&lt;svg'), 'Member email encodes user-controlled name');
    $view = $app->mailer->compose('newAccountAdmin', ['user' => $user])->getSymfonyEmail()->getHtmlBody();
    check(!str_contains($view, '<svg onload=') && str_contains($view, '&lt;svg'), 'Admin email encodes user-controlled name');
    $before = snapshot($user);
    $page = request($path);
    check($page['status'] === 200 && str_contains($page['body'], 'activation-form'), 'GET displays explicit confirmation');
    check(snapshot(member()) === $before, 'Email scanner GET cannot activate or modify account');
    check(stripos($page['headers'], 'no-store') !== false && stripos($page['headers'], 'no-referrer') !== false, 'Activation protects referrer/cache');
    $rejected = request($path, []);
    check($rejected['status'] === 400 && snapshot(member()) === $before, 'Activation POST requires CSRF');
    check(stripos($rejected['headers'], 'no-store') !== false && stripos($rejected['headers'], 'no-referrer') !== false, 'CSRF errors keep bearer privacy headers');
    $jarOld = $jar; $jar = tempnam('/tmp', 'napay-activation-'); unlink($jarOld);
    $page = request($path);
    $validCsrf = csrf($page);
    $bad = '/site/activate?' . http_build_query(['id' => $credentials['id'], 'sign' => base64_encode(str_repeat('x', 64))]);
    check(request($bad)['status'] === 400 && snapshot(member()) === $before, 'Wrong signature never deletes pending member');
    check(request($bad, ['_csrf' => $validCsrf])['status'] === 400 && snapshot(member()) === $before, 'Wrong POST signature never changes member');
    check(request($path, ['_csrf' => $validCsrf])['status'] === 302, 'Valid confirmation activates member');
    $user = member();
    check((int) $user->is_active === 1 && [$user->oauth_uid, $user->password, $user->privilege_id] === $identity, 'Activation preserves password, OAuth identity and role');
    check($user->authKey !== $pendingCredentials[0] && $user->accessToken !== $pendingCredentials[1]
        && !preg_match('/^[0-9]{16}$/D', $user->authKey), 'Activation replaces both pending credentials');
    $activeSnapshot = snapshot($user);
    check(request($path)['status'] === 400 && snapshot(member()) === $activeSnapshot, 'Repeated link cannot delete or change active account');
    check(request($bad)['status'] === 400 && snapshot(member()) === $activeSnapshot, 'Wrong link cannot delete active account');
    check(app\models\UserConsensus::find()->where(['user_id' => $user->id])->count() == 1, 'Replays preserve dependent consent data');
    $login = request('/site/login');
    check(request('/site/login', ['_csrf' => csrf($login), 'LoginForm' => ['username' => 'activation-member', 'password' => 'activation-fixture-password']])['status'] === 302, 'Activated member can log in');
    foreach ([[-172801, 0, 'SELF'], [3600, 0, 'SELF'], [0, 2, 'SELF'], [0, 0, 'external']] as [$seconds, $status, $provider]) {
        $user = pending($seconds, $status, $provider); $before = snapshot($user);
        check(request(pathFor($user))['status'] === 400 && snapshot(member()) === $before, 'Expired/future/blocked/nonlocal activation is non-destructive');
    }
    $user = pending(); $old = clone $user; $one = pathFor($user);
    app\models\Users::updateAll(['authKey' => Yii::$app->security->generateRandomString()], ['id' => $user->id]);
    check(request($one)['status'] === 400 && !$old->activate($old->getActivationSignature()), 'Disabled/replaced pending credential cannot reactivate');
    $user = pending(); $sign = $user->getActivationSignature();
    $user->accessToken = '';
    check(!$user->canActivate($sign), 'Missing secret cannot authorize activation');
    $user = pending(); $one = clone $user; $two = clone $user; $sign = $user->getActivationSignature();
    check($one->activate($sign) && !$two->activate($sign), 'Atomic activation rejects stale concurrent consumer');
    foreach (['', 'bad-cipher', app\components\Crypt::sqlEncrypt('1 OR 1=1'), app\components\Crypt::sqlEncrypt('999999'), app\components\Crypt::sqlEncrypt('-1')] as $invalid) {
        check(request('/site/activate?' . http_build_query(['id' => $invalid, 'sign' => $signature]))['status'] === 400, 'Malformed/missing identifier safely rejected');
    }
    check(request('/site/activate?id[]=1&sign[]=x')['status'] === 400, 'Array activation arguments safely rejected');
    $user = member(); $before = snapshot($user);
    check(!$user->activate() && snapshot(member()) === $before, 'Model cannot bypass activation signature');
    $page = request('/site/login');
    check(str_contains($page['body'], '/site/resend-activation'), 'Login exposes activation recovery');
    check(request('/site/resend-activation')['status'] === 200, 'Recovery form renders');
    check(request('/site/resend-activation', ['ActivationResendForm' => ['email' => 'activation@example.invalid']])['status'] === 400, 'Recovery requires CSRF');
    check(resend(['invalid'])['status'] === 200, 'Recovery rejects array email safely');
    foreach ([[0, 1, 'SELF'], [0, 2, 'SELF'], [0, 0, 'external']] as [$seconds, $status, $provider]) {
        $user = pending($seconds, $status, $provider); $before = snapshot($user);
        $mailCount = count(glob($root . '/runtime/mail/*.eml') ?: []);
        $app->cache->flush();
        check(resend()['status'] === 302 && snapshot(member()) === $before, 'Recovery never changes active/blocked/nonlocal account');
        check(count(glob($root . '/runtime/mail/*.eml') ?: []) === $mailCount, 'Ineligible account receives no recovery mail');
    }
    $app->cache->flush();
    check(resend('missing-activation@example.invalid')['status'] === 302, 'Unknown account has same generic recovery redirect');
    $user = pending(-172801); $before = snapshot($user); $expiredPath = pathFor($user);
    $expired = request($expiredPath);
    check($expired['status'] === 400 && str_contains($expired['body'], '/site/resend-activation'), 'Expired link offers safe recovery path');
    $identity = [$user->username, $user->password, $user->oauth_uid, $user->privilege_id];
    $filesBefore = glob($root . '/runtime/mail/*.eml') ?: [];
    $app->cache->flush();
    check(resend('activation@example.invalid', 'attacker.example.invalid')['status'] === 302, 'Expired local account can request fresh link');
    $freshPath = recoveredLink($filesBefore);
    $user = member();
    check([$user->username, $user->password, $user->oauth_uid, $user->privilege_id] === $identity && (int) $user->is_active === 0, 'Recovery preserves identity/password/role and stays pending');
    check(request($expiredPath)['status'] === 400, 'Recovery invalidates old signature');
    $mailCount = count(glob($root . '/runtime/mail/*.eml') ?: []); $before = snapshot($user);
    check(resend('activation@example.invalid', 'attacker.example.invalid')['status'] === 302 && snapshot(member()) === $before
        && count(glob($root . '/runtime/mail/*.eml') ?: []) === $mailCount, 'Immediate resend is throttled without credential rotation');
    $page = request($freshPath);
    check($page['status'] === 200, 'Link extracted from local mail opens confirmation');
    check(request($freshPath, ['_csrf' => csrf($page)])['status'] === 302 && (int) member()->is_active === 1, 'Recovered file-mail link activates through HTTP');
    check(request($freshPath)['status'] === 400, 'Recovered link is single use');
    $user = pending(-172801); $before = snapshot($user); $app->cache->flush();
    $app->mailer->fileTransportPath = '/dev/null/cannot-write';
    $model = new app\models\forms\ActivationResendForm(['email' => 'activation@example.invalid']);
    check(!$model->sendEmail() && snapshot(member()) === $before, 'Failed recovery delivery restores matching previous pending credentials');
    $app->mailer->fileTransportPath = '@runtime/mail';
    $duplicate = new app\models\Users(); $duplicate->setAttributes($user->getAttributes(), false);
    $duplicate->id = null; $duplicate->username = 'activation-duplicate'; $duplicate->save(false);
    $app->cache->flush();
    check(!$model->sendEmail() && snapshot(member()) === $before, 'Duplicate recovery email cannot select arbitrary account');
    $duplicate->delete();
    $app->cache->flush();
    for ($i = 0; $i < 6; ++$i) check(resend('absent-activation-' . $i . '@example.invalid')['status'] === 302, 'Rate-limited recovery keeps generic response');
    check((int) $app->cache->get('activation-ip-' . hash('sha256', '127.0.0.1')) === 5, 'Recovery IP limit caps five requests');
    $user = pending(); $disabledPath = pathFor($user);
    $oldJar = $jar; $jar = tempnam('/tmp', 'napay-activation-'); unlink($oldJar);
    $page = request('/site/login');
    check(request('/site/login', ['_csrf' => csrf($page), 'LoginForm' => ['username' => 'runtime-admin', 'password' => 'fixture-password-only']])['status'] === 302, 'Admin authenticates for pending disable');
    $list = request('/users/index'); $doc = new DOMDocument(); @$doc->loadHTML($list['body'], LIBXML_NONET);
    $link = (new DOMXPath($doc))->query('//tr[td[text()="activation-member"]]//a[contains(@href,"/users/view")]/@href')->item(0)->nodeValue;
    check(request(str_replace('/users/view', '/users/disable', $link), ['_csrf' => csrf($list)])['status'] === 302, 'Explicit disable revokes pending activation even when status already zero');
    $before = snapshot(member()); $app->cache->flush();
    check(!member()->canRequestActivation() && !$model->sendEmail() && snapshot(member()) === $before, 'Disabled pending account cannot recover activation');
    check(request($disabledPath)['status'] === 400, 'Disabled pending link cannot activate');
    // Unit failure path: the real controller commits data before file mail delivery.
    $controller = new app\controllers\SiteController('site', $app);
    $controller->action = new yii\base\Action('signup', $controller);
    $app->controller = $controller;
    $failureForm = $form;
    $failureForm['SignupForm']['username'] = 'delivery-member';
    $failureForm['SignupForm']['email'] = 'delivery@example.invalid';
    $app->request->setBodyParams($failureForm);
    $app->mailer->fileTransportPath = '/dev/null/cannot-write';
    $response = $controller->actionSignup();
    $deliveryUser = app\models\Users::findByUsername('delivery-member');
    check($response->statusCode === 302 && $deliveryUser !== null && (int) $deliveryUser->is_active === 0,
        'Registration survives local delivery failure as pending');
    check(!$app->db->getTransaction() && app\models\UserConsensus::find()->where(['user_id' => $deliveryUser->id])->count() == 1,
        'User and consent commit before delivery, no open transaction remains');
    $app->mailer->fileTransportPath = '@runtime/mail'; $app->cache->flush();
    $filesBefore = glob($root . '/runtime/mail/*.eml') ?: [];
    $recover = new app\models\forms\ActivationResendForm(['email' => 'delivery@example.invalid']);
    check($recover->sendEmail(), 'Failed initial delivery can recover through file mail');
    $deliveryPath = recoveredLink($filesBefore); $page = request($deliveryPath);
    check($page['status'] === 200 && request($deliveryPath, ['_csrf' => csrf($page)])['status'] === 302,
        'File-mail recovery link activates account after initial delivery failure');
    $log = file_get_contents($root . '/runtime/logs/app.log');
    check(!str_contains($log, $signature), 'Application log never records activation bearer');
    echo "PASS {$checks} signup/activation checks\n";
} finally { @unlink($jar); }
