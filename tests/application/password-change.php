<?php
// SIP-035 HTTP probes: fictitious users on disposable DB only, local file mail.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2); require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime' || env('MAIL_TRANSPORT') !== 'file') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
$app = new yii\web\Application(require $root . '/config/web.php');
$db = $app->db; $checks = 0; $jars = []; $jar = null;
register_shutdown_function(static function () use (&$jars) { foreach ($jars as $file) @unlink($file); });
function check($ok, $label) { global $checks; if (!$ok) throw new RuntimeException($label); ++$checks; echo "PASS: $label\n"; }
function request($path, $post = null) {
    global $jar; $c = curl_init('http://127.0.0.1' . $path);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 15, CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar]);
    if ($post !== null) curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    $raw = curl_exec($c); if ($raw === false) throw new RuntimeException('Fixture transport failed');
    $n = curl_getinfo($c, CURLINFO_HEADER_SIZE); $r = ['status' => curl_getinfo($c, CURLINFO_RESPONSE_CODE), 'body' => substr($raw, $n), 'headers' => substr($raw, 0, $n)]; curl_close($c); return $r;
}
function csrf($page) { $d = new DOMDocument(); @$d->loadHTML($page['body'], LIBXML_NONET); $n = (new DOMXPath($d))->query('//meta[@name="csrf-token"]/@content'); if ($n->length !== 1) throw new RuntimeException('CSRF missing'); return $n->item(0)->nodeValue; }
function newJar() { global $jar, $jars; $jar = tempnam('/tmp', 'password-change-'); $jars[] = $jar; return $jar; }
function login($role, $password = 'fixture-password-only') { $p = request('/site/login'); return request('/site/login', ['_csrf' => csrf($p), 'LoginForm' => ['email' => $role . '@example.invalid', 'password' => $password, 'rememberMe' => 1]]); }
function change($current, $new, $repeat = null, $extra = []) { $p = request('/users/change-password'); return request('/users/change-password', ['_csrf' => csrf($p), 'ChangePasswordForm' => ['current_password' => $current, 'password' => $new, 'repeat_password' => $repeat ?? $new] + $extra]); }
newJar(); check(request('/users/change-password')['status'] === 302, 'Guest change requires login');
check(login('user')['status'] === 302, 'Current fixture login');
$user = app\models\Users::findByUsername('runtime-user'); $other = app\models\Users::findByUsername('runtime-other'); $before = $user->attributes; $otherBefore = $other->attributes;
check(request('/users/change-password')['status'] === 200, 'Password page renders');
check(request('/users/change-password', ['ChangePasswordForm' => []])['status'] === 400, 'Change requires CSRF');
$oldJar = $jar; newJar(); check(login('user')['status'] === 302, 'Second remembered session login'); $secondJar = $jar; $jar = $oldJar;
check(str_contains((string) file_get_contents($secondJar), "\t_identity\t"), 'Real remember-me cookie was issued before rotation');
foreach ([['wrong-current', 'valid-new-password'], ['fixture-password-only', 'short'], ['fixture-password-only', 'valid-new-password', 'different'], ['fixture-password-only', str_repeat('a', 73)], ['fixture-password-only', str_repeat('é', 37)], [['array'], 'valid-new-password']] as $n => $case) {
    $r = change(...$case);
    check($r['status'] === 200 && !str_contains($r['body'], 'value="valid-new-password"'), 'Invalid change rejected without echoing passwords: ' . $n);
    check(app\models\Users::findOne($user->id)->password === $before['password'], 'Rejected change preserves password');
}
$app->cache->flush();
for ($i = 0; $i < 5; ++$i) check(change('wrong-current', 'valid-new-password')['status'] === 200, 'Wrong current password counted: ' . $i);
$r = change('fixture-password-only', 'valid-new-password');
check($r['status'] === 429 && stripos($r['headers'], 'Retry-After:') !== false, 'Current password attempts throttled');
check(app\models\Users::findOne($user->id)->password === $before['password'], 'Throttled change preserves password');
$app->cache->flush();
$now = time(); $db->createCommand()->insert('pos_operator_sessions', ['user_id' => $user->id, 'token_sha256' => bin2hex(random_bytes(32)), 'client_key_prefix' => 'fixture', 'credential_fingerprint' => hash('sha256', 'pos-session|' . $before['password']), 'created_at' => $now, 'expires_at' => $now + 3600])->execute(); $posId = (int) $db->lastInsertID;
$knownNew = 'fixture-new-password-only';
$r = change('fixture-password-only', $knownNew, null, ['user_id' => $other->id, 'authKey' => 'injected']);
check($r['status'] === 302 && str_contains($r['headers'], '/site/login'), 'Successful change signs current session out');
$after = app\models\Users::findOne($user->id);
check(Yii::$app->security->validatePassword($knownNew, $after->password) && !Yii::$app->security->validatePassword('fixture-password-only', $after->password), 'Only new password authenticates');
check($after->authKey !== $before['authKey'] && $after->accessToken !== $before['accessToken'] && $after->activationCode === null, 'Remember-me, bearer and outstanding reset credentials rotated');
check(app\models\Users::findOne($other->id)->attributes === $otherBefore, 'Posted target ID cannot change another account');
$pos = $db->createCommand('SELECT revoked_at,revoked_reason FROM pos_operator_sessions WHERE id=:id', [':id' => $posId])->queryOne();
check($pos['revoked_at'] !== null && $pos['revoked_reason'] === 'password_changed', 'Existing POS session explicitly revoked by shared primitive');
$jar = $secondJar; check(request('/users/change-password')['status'] === 302, 'Other web session revoked');
$newCookieJar = newJar(); $cookie = file($secondJar, FILE_IGNORE_NEW_LINES); file_put_contents($newCookieJar, implode("\n", array_filter($cookie, static fn($line) => !str_contains($line, "\tPHPSESSID\t"))) . "\n");
check(request('/users/change-password')['status'] === 302, 'Remember-me cookie cannot restore old identity');
newJar(); check(login('user', $knownNew)['status'] === 302, 'New password login succeeds');
$mails = glob($root . '/runtime/mail/*.eml') ?: []; $mail = implode("\n", array_map('file_get_contents', $mails));
check($mails !== [] && !str_contains($mail, $knownNew) && !str_contains($mail, 'fixture-password-only'), 'Notification email contains no old or new password');
check((int) $db->createCommand("SELECT COUNT(*) FROM logs WHERE action='change-password' AND description='Account password changed.'")->queryScalar() === 1, 'Password change audited exactly once');
check(request('/users/send-password-reset?id=bad', ['_csrf' => csrf(request('/users/change-password'))])['status'] === 403, 'Non-admin cannot request another account reset');
newJar(); check(login('admin')['status'] === 302, 'Admin fixture login');
$index = request('/users/index'); $doc = new DOMDocument(); @$doc->loadHTML($index['body'], LIBXML_NONET); $xp = new DOMXPath($doc);
$nodes = $xp->query('//tr[.//*[contains(text(),"other@example.invalid")]]//a[contains(@href,"/users/view")]/@href');
check($nodes->length > 0, 'Admin target profile link exists'); $profile = request($nodes->item(0)->nodeValue);
$targetQuery = parse_url($nodes->item(0)->nodeValue, PHP_URL_QUERY);
$path = '/users/send-password-reset?' . $targetQuery;
$profileDoc = new DOMDocument(); @$profileDoc->loadHTML($profile['body'], LIBXML_NONET);
check((new DOMXPath($profileDoc))->query('//form[contains(@action,"send-password-reset")]')->length === 1, 'Admin target profile contains exactly one POST reset form');
$ownLink = $xp->query('//a[@data-napay-profile-link]/@href')->item(0)->nodeValue;
check(request('/users/send-password-reset?' . parse_url($ownLink, PHP_URL_QUERY), ['_csrf' => csrf($profile)])['status'] === 403, 'Admin own reset action denied; self-service remains separate');
    check(request($path)['status'] === 405, 'Admin reset is POST only');
    check(request($path, [])['status'] === 400, 'Admin reset requires CSRF');
    $app->cache->flush(); check(request($path, ['_csrf' => csrf($profile)])['status'] === 302, 'Admin sends existing reset flow');
    $target = app\models\Users::findOne($other->id); check($target->password === $otherBefore['password'] && $target->authKey === $otherBefore['authKey'] && $target->activationCode !== $otherBefore['activationCode'], 'Admin sends link, never sets password');
check(request('/users/send-password-reset?id=tampered', ['_csrf' => csrf($profile)])['status'] === 404, 'Admin reset rejects invalid target');
foreach (['password', 'authKey', 'accessToken', 'activationCode'] as $field) check(app\models\Users::findOne($user->id)->$field === $after->$field, 'Admin reset of other account does not change current fixture: ' . $field);
$logText = implode("\n", $db->createCommand('SELECT description FROM logs')->queryColumn());
foreach (glob($root . '/runtime/logs/*') ?: [] as $file) if (is_file($file)) $logText .= file_get_contents($file);
check(!str_contains($logText, $knownNew) && !str_contains($logText, 'wrong-current'), 'Audit and file logs contain no entered passwords');
echo "PASS $checks password change HTTP checks\n";
