<?php
// SIP-030 punto 2: promemoria della quota. HTTP reale + DB usa e getta, mail su file. Mai DB condivisi.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime' || env('MAIL_TRANSPORT') !== 'file') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
$app = new yii\web\Application(require $root . '/config/web.php');
$db = $app->db;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);

use app\components\association\MembershipReminders;

$checks = 0; $jar = null;
function check($condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks; echo 'PASS: ' . $label . "\n";
}
function web(string $path, ?array $form = null): array {
    global $jar;
    $curl = curl_init('http://127.0.0.1' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20,
        CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($form !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form)]);
    $raw = curl_exec($curl);
    if (!is_string($raw)) throw new RuntimeException('HTTP transport failed.');
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $r = ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    curl_close($curl);
    return $r;
}
function csrf(array $page): string {
    if (!preg_match('/<meta name="csrf-token" content="([^"]+)"/', $page['body'], $m)) throw new RuntimeException('CSRF unavailable.');
    return html_entity_decode($m[1]);
}
function login(string $email): int {
    global $jar;
    $jar = tempnam(sys_get_temp_dir(), 'napay-reminders-');
    $page = web('/site/login');
    return web('/site/login', ['_csrf' => csrf($page), 'LoginForm' => ['email' => $email, 'password' => 'fixture-password-only']])['status'];
}
function scalar(string $sql, array $p = []) { global $db; return $db->createCommand($sql, $p)->queryScalar(); }
function mails(): array { global $root; $files = glob($root . '/runtime/mail/*.eml') ?: []; sort($files); return $files; }
function sentTo(array $files): array {
    $to = [];
    foreach ($files as $file) if (preg_match('/^To: (\S+)\r?$/m', file_get_contents($file), $m)) $to[] = $m[1];
    sort($to);
    return $to;
}

// --- Fixture: soci con quote in vari stati (date relative a oggi)
$today = date('Y-m-d');
$day = static fn(int $n) => date('Y-m-d', strtotime("$today $n days"));
$hash = password_hash('fixture-password-only', PASSWORD_BCRYPT);
$level = static fn(int $l) => (int) scalar('SELECT id FROM privileges WHERE level=:l ORDER BY id LIMIT 1', [':l' => $l]);
// quota e metodo di pagamento: quelli del dataset se presenti, altrimenti creati per il test
if (scalar('SELECT COUNT(*) FROM membership_fees WHERE duration >= 1') == 0) $db->createCommand()->insert('membership_fees', ['description' => 'Quota test', 'duration' => 1, 'amount' => 20])->execute();
if (scalar('SELECT COUNT(*) FROM payment_methods') == 0) $db->createCommand()->insert('payment_methods', ['description' => 'Contanti', 'min_level' => 20])->execute();
$fee = (int) scalar('SELECT id FROM membership_fees WHERE duration >= 1 ORDER BY id LIMIT 1');
$method = (int) scalar('SELECT id FROM payment_methods ORDER BY id LIMIT 1');
$member = function (string $name, ?string $expiry, array $profile = [], int $privilege = 0) use ($db, $hash, $level, $fee, $method): int {
    $db->createCommand()->insert('users', ['username' => "$name@example.invalid", 'email' => "$name@example.invalid", 'password' => $hash, 'first_name' => ucfirst($name), 'last_name' => 'Fee',
        'oauth_provider' => 'SELF', 'oauth_uid' => $name, 'authKey' => 'fixture-' . $name, 'accessToken' => 'fixture-' . $name, 'picture' => '/favicon.ico',
        'privilege_id' => $level($privilege), 'is_merchant' => 0, 'is_active' => 1])->execute();
    $id = (int) $db->lastInsertID;
    $db->createCommand()->insert('member_profiles', ['user_id' => $id, 'is_corporate' => 0] + $profile)->execute();
    if ($expiry !== null) {
        $db->createCommand()->insert('membership_payments', ['user_id' => $id, 'fee_id' => $fee, 'payment_method_id' => $method, 'amount' => 20, 'registered_on' => date('Y-m-d', strtotime("$expiry -1 year")),
            'starts_on' => date('Y-m-d', strtotime("$expiry -1 year +1 day")), 'expires_on' => $expiry, 'status' => 'paid'])->execute();
    }
    return $id;
};
$soon = $member('fee-soon', $day(20));                                      // scade tra 20 giorni: promemoria automatico
$later = $member('fee-later', $day(40));                                    // tra 40: in lista, niente automatico
$expired = $member('fee-expired', $day(-100));                              // scaduto da 100 giorni: in lista
$lapsed = $member('fee-lapsed', $day(-400));                                // decaduto: fuori
$never = $member('fee-never', null);                                        // mai pagato: fuori
$admin = $member('fee-admin', $day(10), [], 50);                            // amministratore esente: fuori
$off = $member('fee-off', $day(10), ['reminders_opt_out' => 1]);            // promemoria disattivati
$pending = $member('fee-pending', $day(10), ['approval_state' => 'pending']); // iscrizione non approvata: fuori

$listed = array_map('intval', MembershipReminders::query($today)->select('m.id')->column());
sort($listed);
$wanted = [$soon, $later, $expired, $off];
sort($wanted);
check(array_values(array_intersect($listed, [$soon, $later, $expired, $lapsed, $never, $admin, $off, $pending])) === $wanted,
    'List: expiring within 45 days or expired within a year; never lapsed, never paid, exempt or unapproved members');

// --- Primo promemoria automatico
$before = mails();
check(MembershipReminders::sendAutomatic($today) >= 1, 'Automatic run sends reminders');
$sent = array_values(array_diff(mails(), $before));
check(sentTo($sent) === ['fee-soon@example.invalid'], 'Automatic reminder only to members expiring within 30 days who did not turn reminders off');
$body = quoted_printable_decode(file_get_contents($sent[0]));
check(str_contains($body, date('d/m/Y', strtotime($day(20)))) && str_contains($body, 'https://napay.example.invalid/pay-fee/index'), 'Reminder mail carries the real expiry date and the fee link');
check((int) scalar("SELECT COUNT(*) FROM membership_reminders WHERE user_id=:u AND kind='auto' AND expires_on=:e", [':u' => $soon, ':e' => $day(20)]) === 1, 'Automatic reminder recorded for that expiry');
$before = mails();
check(MembershipReminders::sendAutomatic($today) === 0 && mails() === $before, 'Automatic run is idempotent: once per expiry');
// lo script gira come root: la cartella delle mail torna ad Apache (nel container cron i job girano già come www-data)
foreach ([$root . '/runtime/mail', ...glob($root . '/runtime/mail/*')] as $path) @chown($path, 33);

// --- Pagina admin e invii manuali
check(login('fee-soon@example.invalid') === 302 && web('/membership-reminders/index')['status'] === 403, 'Members cannot open the reminders page');
check(login('admin@example.invalid') === 302, 'Admin logs in');
$home = web('/site/index')['body'];
check(str_contains($home, 'data-dashboard="members"') && str_contains($home, 'data-members="expiring"')
    && str_contains(urldecode(html_entity_decode($home)), '/association/members?MembersSearch[status]=expired'), 'Dashboard: member counts by fee status, each linking to the filtered list');
$expiring = (new app\models\search\MembersSearch())->search(['MembersSearch' => ['status' => 'expiring']])->getTotalCount();
check(preg_match('~data-members="expiring"[\s\S]*?<span class="h2 mb-0 mt-1">' . $expiring . '</span>~', $home) === 1 && $expiring >= 3, 'Expiring count matches the filtered members list');
$page = web('/membership-reminders/index');
check($page['status'] === 200 && str_contains($page['body'], 'fee-later@example.invalid') && str_contains($page['body'], 'fee-expired@example.invalid')
    && str_contains($page['body'], 'Disattivati') && !str_contains($page['body'], 'fee-never@example.invalid') && !str_contains($page['body'], 'fee-lapsed@example.invalid'),
    'Admin list with status, counters and turned-off members');
check(web('/membership-reminders/send', ['selection' => [$later]])['status'] === 400 && web('/membership-reminders/send')['status'] === 405, 'Manual send needs POST with CSRF');
$before = mails();
check(web('/membership-reminders/send', ['_csrf' => csrf($page), 'selection' => [(string) $soon, (string) $later, (string) $expired, (string) $off, (string) $never, 'x']])['status'] === 302, 'Manual send submitted');
check(sentTo(array_values(array_diff(mails(), $before))) === ['fee-expired@example.invalid', 'fee-later@example.invalid'],
    'Manual send: listed members only, not within 7 days of the last reminder, not turned off');
check((int) scalar("SELECT COUNT(*) FROM membership_reminders WHERE kind='manual' AND sent_by=(SELECT id FROM users WHERE email='admin@example.invalid')") === 2, 'Manual reminders recorded with the admin');
$before = mails();
web('/membership-reminders/send', ['_csrf' => csrf(web('/membership-reminders/index')), 'selection' => [(string) $later]]);
check(mails() === $before, 'Same member again within 7 days: skipped');
$page = web('/membership-reminders/index')['body'];
check(preg_match('~fee-later@example\.invalid.*?<td>1</td>~s', $page) === 1, 'Counter shows the reminders of the current expiry');

// --- Il socio disattiva e riattiva i promemoria
check(login('fee-later@example.invalid') === 302, 'Member logs in');
$feePage = web('/pay-fee/index');
check(str_contains($feePage['body'], 'data-fee-reminders') && str_contains($feePage['body'], 'name="reminders" value="on" checked'), 'Fee page shows the reminders switch, on by default');
check(web('/pay-fee/reminders', ['_csrf' => csrf($feePage)])['status'] === 302 && (int) scalar('SELECT reminders_opt_out FROM member_profiles WHERE user_id=:u', [':u' => $later]) === 1, 'Member turns reminders off');
check(web('/pay-fee/reminders', ['_csrf' => csrf(web('/pay-fee/index')), 'reminders' => 'on'])['status'] === 302
    && (int) scalar('SELECT reminders_opt_out FROM member_profiles WHERE user_id=:u', [':u' => $later]) === 0, 'Member turns reminders on again');

// --- Stato account: solo l'amministratore lo cambia (il livello 40 gestisce la lista, non lo stato)
check(login('operator@example.invalid') === 302 && web('/users/index')['status'] === 200, 'Level-40 profile manages the users list');
$list = web('/users/index');
check(web('/users/disable?id=' . urlencode(app\components\Crypt::encrypt($later)), ['_csrf' => csrf($list)])['status'] === 403
    && (int) scalar('SELECT is_active FROM users WHERE id=:u', [':u' => $later]) === 1, 'Level-40 profile cannot disable accounts');

// --- Rinnovo: nuova scadenza, il conteggio riparte e il socio esce dalla lista
$db->createCommand()->insert('membership_payments', ['user_id' => $soon, 'fee_id' => $fee, 'payment_method_id' => $method, 'amount' => 20, 'registered_on' => $today,
    'starts_on' => $day(21), 'expires_on' => $day(386), 'status' => 'paid'])->execute();
check(!in_array($soon, array_map('intval', MembershipReminders::query($today)->select('m.id')->column()), true), 'Renewed member leaves the list (counter is per expiry)');
printf("PASS %d membership reminder checks\n", $checks);
