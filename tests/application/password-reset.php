<?php
// Real HTTP + file mail; only fake accounts in the disposable runtime database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime' || env('MAIL_TRANSPORT') !== 'file') exit(1);
require $root . '/vendor/autoload.php';
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/yiisoft/yii2/Yii.php';
$app = new yii\web\Application(require $root . '/config/web.php');
$checks = 0; $jars = [];
function check($condition, $label) {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks;
    echo 'PASS: ' . $label . "\n";
}
function newJar() {
    global $jars;
    $jar = tempnam('/tmp', 'napay-reset-cookie-');
    $jars[] = $jar;
    return $jar;
}
function request($path, ?array $form = null, $host = null) {
    global $jar;
    $curl = curl_init('http://127.0.0.1' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15, CURLOPT_PROXY => '',
        CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar]);
    if ($host !== null) curl_setopt($curl, CURLOPT_HTTPHEADER, ['Host: ' . $host]);
    if ($form !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form)]);
    $raw = curl_exec($curl);
    if ($raw === false) throw new RuntimeException('Reset HTTP transport failed.');
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $result = ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    curl_close($curl);
    return $result;
}
function csrf($response) {
    $doc = new DOMDocument(); @$doc->loadHTML($response['body'], LIBXML_NONET);
    $nodes = (new DOMXPath($doc))->query('//meta[@name="csrf-token"]/@content');
    if ($nodes->length !== 1) throw new RuntimeException('Reset CSRF unavailable.');
    return $nodes->item(0)->nodeValue;
}
function forgot($email, $host = null) {
    $page = request('/site/forgot-password', null, $host);
    return request('/site/forgot-password', ['_csrf' => csrf($page), 'PasswordResetRequestForm' => ['email' => $email]], $host);
}
function login($password) {
    $page = request('/site/login');
    return request('/site/login', ['_csrf' => csrf($page), 'LoginForm' => ['email' => 'other@example.invalid', 'password' => $password]]);
}
function mailFiles() {
    global $root;
    return glob($root . '/runtime/mail/*.eml') ?: [];
}
function fixture() { return app\models\Users::findByUsername('runtime-other'); }
function rawTokenAt($time) {
    return Yii::$app->security->generateRandomString(48) . '_' . $time;
}
$jar = newJar();
try {
    $app->cache->flush();
    check(login('fixture-password-only')['status'] === 302, 'Pre-reset session authenticates');
    $oldJar = $jar; $jar = newJar();
    $page = request('/site/forgot-password');
    check($page['status'] === 200, 'Forgot password page renders');
    check(request('/site/forgot-password', ['PasswordResetRequestForm' => ['email' => 'other@example.invalid']])['status'] === 400, 'Forgot password requires CSRF');
    check(forgot('invalid-address')['status'] === 200, 'Invalid email rejected by form');
    check(forgot(['invalid'])['status'] === 200, 'Array email rejected without server error');
    $before = count(mailFiles());
    foreach (['missing@example.invalid', 'disabled@example.invalid', 'blocked@example.invalid'] as $email) {
        $app->cache->flush();
        check(forgot($email)['status'] === 302, 'Unknown/inactive requests use generic response');
        check(count(mailFiles()) === $before, 'Unknown/inactive request sends no mail');
    }
    $app->cache->flush();
    $response = forgot('other@example.invalid', 'attacker.example.invalid');
    check($response['status'] === 302, 'Active reset request uses same generic redirect');
    $files = mailFiles();
    check(count($files) === $before + 1, 'Reset email captured locally');
    $file = array_values(array_diff($files, []));
    usort($file, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $message = quoted_printable_decode(file_get_contents($file[0]));
    check((fileperms(dirname($file[0])) & 0777) === 0700 && (fileperms($file[0]) & 0777) === 0600, 'File mail permissions owner-only');
    check(!str_contains($message, 'attacker.example.invalid'), 'Mail ignores malicious Host header');
    check(preg_match('~https://napay\.example\.invalid/site/reset-password\?token=([A-Za-z0-9_-]+)~', $message, $match) === 1, 'Reset link uses configured trusted HTTPS origin');
    $token = $match[1];
    check(fixture()->activationCode !== $token && fixture()->activationCode === app\models\Users::passwordResetDigest($token), 'Only reset token hash stored');
    check(!str_contains($message, 'fixture-password-only'), 'Email never contains password');
    $count = count(mailFiles());
    check(forgot('other@example.invalid', 'attacker.example.invalid')['status'] === 302 && count(mailFiles()) === $count, 'Immediate repeat throttled without account disclosure');
    $jar = newJar();
    $path = '/site/reset-password?token=' . rawurlencode($token);
    $page = request($path);
    check($page['status'] === 200, 'Valid reset link renders form');
    check(stripos($page['headers'], 'Referrer-Policy: no-referrer') !== false && stripos($page['headers'], 'no-store') !== false, 'Reset response prevents referrer/cache token leakage');
    check(request($path, ['ResetPasswordForm' => ['password' => 'new-fixture-password', 'repeat_password' => 'new-fixture-password']])['status'] === 400, 'Reset submit requires CSRF');
    foreach ([['short', 'short'], ['new-fixture-password', 'different-password'], [str_repeat('x', 73), str_repeat('x', 73)], [str_repeat('é', 40), str_repeat('é', 40)]] as [$password, $repeat]) {
        check(request($path, ['_csrf' => csrf($page), 'ResetPasswordForm' => ['password' => $password, 'repeat_password' => $repeat]])['status'] === 200, 'Invalid password cannot consume token');
    }
    check(fixture()->validatePassword('fixture-password-only'), 'Invalid reset preserves original password');
    check(request($path, ['_csrf' => csrf($page), 'ResetPasswordForm' => ['password' => 'new-fixture-password', 'repeat_password' => 'new-fixture-password']])['status'] === 302, 'Valid reset succeeds');
    check(fixture()->activationCode === null, 'Successful reset consumes token');
    check(fixture()->accessToken !== 'fixture-other' && fixture()->authKey !== 'fixture-other', 'Reset revokes API and session credentials');
    check(app\models\Users::findIdentityByAccessToken('fixture-other') === null, 'Old API token cannot authenticate');
    check(request($path)['status'] === 400, 'Consumed link cannot be reused');
    $jar = $oldJar;
    check(request('/')['status'] === 302, 'Existing browser session revoked after password reset');
    $jar = newJar();
    check(login('fixture-password-only')['status'] === 200, 'Old password no longer authenticates');
    check(login('new-fixture-password')['status'] === 302, 'New password authenticates');
    check(count(mailFiles()) === $count + 1, 'Success notification captured locally');
    foreach (['', 'bad-token', str_repeat('x', 500), rawTokenAt(time() - 7200), rawTokenAt(time() + 3600)] as $invalid) {
        check(request('/site/reset-password?token=' . rawurlencode($invalid))['status'] === 400, 'Missing/malformed/expired/future reset link rejected');
    }
    $user = fixture(); $concurrent = $user->generatePasswordResetToken(); $user->save(false, ['activationCode']);
    $one = new app\models\forms\ResetPasswordForm($concurrent);
    $two = new app\models\forms\ResetPasswordForm($concurrent);
    foreach ([$one, $two] as $form) { $form->password = 'atomic-fixture-password'; $form->repeat_password = 'atomic-fixture-password'; }
    check($one->resetPassword() && !$two->resetPassword(), 'Compare-and-consume rejects stale concurrent reset');
    $user = fixture(); $old = $user->generatePasswordResetToken(); $user->save(false, ['activationCode']);
    $stale = new app\models\forms\ResetPasswordForm($old); $stale->password = 'stale-fixture-password'; $stale->repeat_password = 'stale-fixture-password';
    $new = $user->generatePasswordResetToken(); $user->save(false, ['activationCode']);
    check(!$stale->resetPassword() && app\models\Users::findByPasswordResetToken($new) !== null, 'New reset request invalidates stale submitted token');
    foreach ([time() - 7200, time() + 3600] as $time) {
        $expired = rawTokenAt($time);
        app\models\Users::updateAll(['activationCode' => app\models\Users::passwordResetDigest($expired)], ['id' => fixture()->id]);
        check(app\models\Users::findByPasswordResetToken($expired) === null, 'Stored expired/future reset hash cannot authenticate');
    }
    $user = fixture(); $blocked = $user->generatePasswordResetToken(); $user->save(false, ['activationCode']);
    $form = new app\models\forms\ResetPasswordForm($blocked); $form->password = 'blocked-fixture-password'; $form->repeat_password = 'blocked-fixture-password';
    app\models\Users::updateAll(['is_active' => 0], ['id' => $user->id]);
    check(!$form->resetPassword(), 'Concurrent account disable blocks reset');
    app\models\Users::updateAll(['is_active' => 1, 'activationCode' => null], ['id' => $user->id]);
    $app->cache->flush();
    $previous = fixture()->activationCode;
    $app->mailer->fileTransportPath = '/dev/null/cannot-write';
    $request = new app\models\forms\PasswordResetRequestForm(['email' => 'other@example.invalid']);
    check(!$request->sendEmail() && fixture()->activationCode === $previous, 'Delivery failure rolls back only matching reset hash');
    $app->mailer->fileTransportPath = '@runtime/mail';
    $app->cache->flush();
    require_once __DIR__ . '/email-legacy-fixture.php';
    napayTestLegacyEmailCollision(fixture(), function () {
        $count = count(mailFiles());
        $request = new app\models\forms\PasswordResetRequestForm(['email' => 'other@example.invalid']);
        check(!$request->sendEmail() && count(mailFiles()) === $count, 'Duplicate email cannot select an arbitrary account');
    });
    $app->cache->flush();
    for ($i = 0; $i < 6; ++$i) check(forgot('absent-' . $i . '@example.invalid')['status'] === 302, 'Throttled/unknown requests retain generic redirect');
    check((int) $app->cache->get('reset-ip-' . hash('sha256', '127.0.0.1')) === 5, 'Per-IP burst capped at five requests');
    $app->cache->flush();
    $request = new app\models\forms\PasswordResetRequestForm(['email' => 'user@example.invalid']);
    $beforeFiles = mailFiles();
    check($request->sendEmail(), 'Escaping fixture mail generated locally');
    $added = array_values(array_diff(mailFiles(), $beforeFiles));
    $html = $app->mailer->compose('resetPasswordUser', ['user' => app\models\Users::findByUsername('runtime-user'), 'resetUrl' => 'https://napay.example.invalid/site/reset-password'])->getSymfonyEmail()->getHtmlBody();
    check(!str_contains($html, '<svg onload=') && str_contains($html, '&lt;svg'), 'Email template encodes user-controlled name');
    $text = $app->mailer->compose(['html' => 'resetPasswordUser', 'text' => 'resetPasswordUser-text'], ['user' => fixture(), 'resetUrl' => 'https://napay.example.invalid/site/reset-password'])->getSymfonyEmail()->getTextBody();
    check(str_contains($text, 'https://napay.example.invalid/site/reset-password'), 'Plaintext email retains actionable trusted link');
    $jar = newJar(); $page = request('/site/login');
    check(request('/site/login', ['_csrf' => csrf($page), 'LoginForm' => ['email' => 'admin@example.invalid', 'password' => 'fixture-password-only']])['status'] === 302, 'Admin fixture authenticates for reset revocation');
    $list = request('/users/index');
    $doc = new DOMDocument(); @$doc->loadHTML($list['body'], LIBXML_NONET);
    $link = (new DOMXPath($doc))->query('//tr[td[text()="runtime-user"]]//a[contains(@href,"/users/view")]/@href')->item(0)->nodeValue;
    check(request(str_replace('/users/view', '/users/disable', $link), ['_csrf' => csrf($list)])['status'] === 302, 'Admin disable succeeds with outstanding reset');
    check(app\models\Users::findByUsername('runtime-user')->activationCode === null, 'Disable clears outstanding reset hash');
    check(request(str_replace('/users/view', '/users/enable', $link), ['_csrf' => csrf($list)])['status'] === 302, 'Admin re-enable does not resurrect reset link');
    foreach (['http://napay.example.invalid', 'https://user:password@napay.example.invalid', 'https://napay.example.invalid/?token=bad'] as $base) {
        $app->params['webapp_link'] = $base;
        $rejected = false;
        try { app\components\sendMail::applicationUrl('site/login'); }
        catch (RuntimeException $error) { $rejected = true; }
        check($rejected, 'Untrusted mail origin rejected');
    }
    $app->params['webapp_link'] = 'https://napay.example.invalid';
    $target = new app\components\logging\AuthenticationFileTarget(['logVars' => []]);
    $safe = $target->formatMessage(['smtp-private-password ' . $token, yii\log\Logger::LEVEL_ERROR, 'yii\\symfonymailer\\Mailer::sendMessage', microtime(true), []]);
    check(!str_contains($safe, 'smtp-private-password') && !str_contains($safe, $token), 'SMTP error logging omits transport secrets');
    $smtp = new app\components\mail\PrivateMailer(['useFileTransport' => false, 'transport' => [
        'scheme' => 'smtp', 'host' => 'smtp.example.invalid', 'port' => 587,
        'username' => 'fixture', 'password' => 'fixture', 'options' => ['require_tls' => true],
    ]]);
    $transport = $smtp->getTransport();
    check($transport->isTlsRequired() && $transport->getStream()->getTimeout() === 10.0, 'SMTP requires TLS and bounded timeout without making connection');
    $log = file_get_contents($root . '/runtime/logs/app.log');
    check(!str_contains($log, $token) && !str_contains($log, 'new-fixture-password'), 'Application log contains no reset bearer/password');
    echo "PASS {$checks} password reset/mail checks\n";
} finally { foreach ($jars as $file) @unlink($file); }
