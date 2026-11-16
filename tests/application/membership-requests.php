<?php
// SIP-030 punto 1: richieste di iscrizione approvate dall'admin. HTTP reale + DB usa e getta, mail su file. Mai DB condivisi.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime' || env('MAIL_TRANSPORT') !== 'file') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
$app = new yii\web\Application(require $root . '/config/web.php');
$db = $app->db;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);

use app\components\association\MembershipRequests;
use app\models\MemberProfiles;

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
function login(string $email): array {
    global $jar;
    $jar = tempnam(sys_get_temp_dir(), 'napay-requests-');
    $page = web('/site/login');
    return web('/site/login', ['_csrf' => csrf($page), 'LoginForm' => ['email' => $email, 'password' => 'fixture-password-only']]);
}
function scalar(string $sql, array $p = []) { global $db; return $db->createCommand($sql, $p)->queryScalar(); }
function mails(): array { global $root; $files = glob($root . '/runtime/mail/*.eml') ?: []; sort($files); return $files; }
function mailTo(array $files, string $email): string {
    foreach ($files as $file) {
        $raw = file_get_contents($file);
        if (preg_match('/^To: ' . preg_quote($email, '/') . '\r?$/m', $raw)) return quoted_printable_decode($raw);
    }
    return '';
}

// --- Fixture: richieste in vari stati
$hash = password_hash('fixture-password-only', PASSWORD_BCRYPT);
$base = (int) scalar('SELECT id FROM privileges WHERE level=0 ORDER BY id LIMIT 1');
$now = time();
$member = function (string $name, int $active, string $state, ?int $requested, ?int $legacy = null, int $corporate = 0) use ($db, $hash, $base): int {
    $db->createCommand()->insert('users', ['username' => "$name@example.invalid", 'email' => "$name@example.invalid", 'password' => $hash, 'first_name' => ucfirst($name), 'last_name' => 'Request',
        'oauth_provider' => 'SELF', 'oauth_uid' => $name, 'authKey' => 'fixture-' . $name, 'accessToken' => 'fixture-' . $name, 'picture' => '/favicon.ico',
        'privilege_id' => $base, 'is_merchant' => 0, 'is_active' => $active])->execute();
    $id = (int) $db->lastInsertID;
    $db->createCommand()->insert('member_profiles', ['user_id' => $id, 'is_corporate' => $corporate, 'denomination' => $corporate ? 'Bot Srl' : null,
        'approval_state' => $state, 'requested_at' => $requested, 'legacy_user_id' => $legacy, 'user_imported' => $legacy === null ? 0 : 1])->execute();
    $t = time();
    $db->createCommand()->insert('user_consensus', ['user_id' => $id, 'consenso_statuto' => 1, 'consenso_privacy' => 1, 'consenso_condizioni' => 1, 'consenso_marketing' => 0,
        'timestamp_statuto' => $t, 'timestamp_privacy' => $t, 'timestamp_condizioni' => $t, 'timestamp_marketing' => $t])->execute();
    return $id;
};
// carica predefinita all'approvazione: presente nel dataset, creata per il test se manca
if (scalar("SELECT COUNT(*) FROM association_roles WHERE description='Socio ordinario'") == 0) $db->createCommand()->insert('association_roles', ['description' => 'Socio ordinario'])->execute();
$a = $member('req-a', 1, 'pending', $now - 300);
$b = $member('req-b', 1, 'pending', $now - 200, null, 1);
$c = $member('req-c', 1, 'pending', $now - 100);
$oldUnverified = $member('req-d', 0, 'pending', $now - 8 * 86400);
$newUnverified = $member('req-e', 0, 'pending', $now - 86400);
$legacy = $member('req-f', 0, 'pending', null, 990001);
$approved = $member('req-g', 1, 'approved', $now - 1000);

check(MembershipRequests::count() === 4 && array_map('intval', MembershipRequests::query()->column()) === [$legacy, $a, $b, $c],
    'List: verified requests and old-Napay imports, oldest first; never unverified, approved or other accounts');

// --- Accesso
$jar = tempnam(sys_get_temp_dir(), 'napay-requests-');
check(web('/membership-requests/index')['status'] === 302, 'Guest redirected to login');
check(login('req-g@example.invalid')['status'] === 302 && web('/membership-requests/index')['status'] === 403
    && web('/membership-requests/approve', ['_csrf' => csrf(web('/site/index')), 'selection' => [$a]])['status'] === 403 && !MemberProfiles::isPending($approved) && MemberProfiles::isPending($a),
    'Approved member logs in but cannot see or approve requests (403)');
check(login('req-b@example.invalid')['status'] === 200 && str_contains(login('req-b@example.invalid')['body'], 'in attesa di approvazione'), 'Pending member cannot log in');
check(app\components\association\MembershipFee::quote(app\models\Users::findOne($b))['reason'] === 'not_approved'
    && app\components\association\MembershipFee::quote(app\models\Users::findOne($approved))['reason'] !== 'not_approved', 'Fee payable only after approval');

// --- Admin: lista, approvazione, rifiuto
check(login('admin@example.invalid')['status'] === 302, 'Admin logs in');
$home = web('/site/index')['body'];
check(str_contains($home, 'data-dashboard="requests"') && str_contains($home, 'Richieste di iscrizione da esaminare: 4'), 'Dashboard shows the number of requests to review');
$page = web('/membership-requests/index');
check($page['status'] === 200 && str_contains($page['body'], 'req-a@example.invalid') && str_contains($page['body'], 'Bot Srl') && str_contains($page['body'], 'Vecchio Napay')
    && !str_contains($page['body'], 'req-d@example.invalid') && !str_contains($page['body'], 'req-g@example.invalid'), 'Admin list with type, legal name and old-Napay badge');
check(web('/membership-requests/approve', ['selection' => [$a]])['status'] === 400 && web('/membership-requests/approve')['status'] === 405
    && MemberProfiles::isPending($a), 'Approval needs POST with CSRF');
$mail = mails();
$r = web('/membership-requests/approve', ['_csrf' => csrf($page), 'selection' => [(string) $a, (string) $legacy, (string) $approved, 'x', (string) $oldUnverified]]);
$sent = array_values(array_diff(mails(), $mail));
check($r['status'] === 302 && !MemberProfiles::isPending($a) && !MemberProfiles::isPending($legacy) && MemberProfiles::isPending($oldUnverified)
    && (int) scalar('SELECT approved_by FROM member_profiles WHERE user_id=:u', [':u' => $a]) === (int) scalar("SELECT id FROM users WHERE email='admin@example.invalid'"),
    'Approve: only listed pending requests change, with the approving admin');
check((int) scalar('SELECT is_active FROM users WHERE id=:u', [':u' => $legacy]) === 1, 'Old-Napay request approved: account activated as in the old Napay');
check(scalar('SELECT r.description FROM member_profiles p JOIN association_roles r ON r.id = p.association_role_id WHERE p.user_id=:u', [':u' => $a]) === 'Socio ordinario',
    'Approved member gets the "Socio ordinario" role');
check(count($sent) === 2 && str_contains(mailTo($sent, 'req-a@example.invalid'), 'approvata') && str_contains(mailTo($sent, 'req-a@example.invalid'), '/pay-fee/index'),
    'Approval mail with the link to pay the fee');
check((int) scalar("SELECT COUNT(*) FROM logs WHERE description=:d", [':d' => 'Iscrizione approvata: utente ' . $a]) === 1, 'Approval audited');

$page = web('/membership-requests/index');
$r = web('/membership-requests/reject', ['_csrf' => csrf($page), 'selection' => [(string) $c], 'reason' => ' x ']);
check($r['status'] === 302 && MemberProfiles::isPending($c) && (int) scalar('SELECT COUNT(*) FROM users WHERE id=:u', [':u' => $c]) === 1, 'Reject without a real reason refused, nothing deleted');
$mail = mails();
$r = web('/membership-requests/reject', ['_csrf' => csrf($page), 'selection' => [(string) $c, (string) $approved], 'reason' => "Dati non verificabili <script>x</script>"]);
$sent = array_values(array_diff(mails(), $mail));
$rejectMail = mailTo($sent, 'req-c@example.invalid');
check($r['status'] === 302 && (int) scalar('SELECT COUNT(*) FROM users WHERE id=:u', [':u' => $c]) === 0
    && (int) scalar('SELECT COUNT(*) FROM member_profiles WHERE user_id=:u', [':u' => $c]) === 0 && (int) scalar('SELECT COUNT(*) FROM user_consensus WHERE user_id=:u', [':u' => $c]) === 0
    && (int) scalar('SELECT COUNT(*) FROM users WHERE id=:u', [':u' => $approved]) === 1, 'Reject deletes the account with profile and consents; approved members untouched');
// parte HTML: motivazione codificata; la parte di solo testo la riporta così com'è (non interpretata)
$htmlPart = preg_match('~Content-Type: text/html[\s\S]*?\r?\n\r?\n([\s\S]*?)(?:\r?\n--|$)~', $rejectMail, $hm) === 1 ? $hm[1] : '';
check(count($sent) === 1 && str_contains($rejectMail, 'Dati non verificabili') && str_contains($htmlPart, '&lt;script&gt;x') && !str_contains($htmlPart, '<script>x')
    && str_contains($rejectMail, 'eliminati'), 'Rejection mail carries the reason, encoded in the HTML part');
check((int) scalar("SELECT COUNT(*) FROM logs WHERE description=:d", [':d' => 'Richiesta di iscrizione rifiutata: utente ' . $c . ' eliminato']) === 1
    && (int) scalar("SELECT COUNT(*) FROM logs WHERE description LIKE '%req-c@%'") === 0, 'Rejection audited by ID only, no personal data');
check(login('req-c@example.invalid')['status'] === 200, 'Rejected account cannot log in');
check(login('req-a@example.invalid')['status'] === 302, 'Approved member logs in');

// --- Account mai verificati: eliminati dopo 7 giorni, mai quelli recenti o importati
check(MembershipRequests::purgeUnverified(time()) === 1 && (int) scalar('SELECT COUNT(*) FROM users WHERE id=:u', [':u' => $oldUnverified]) === 0
    && (int) scalar('SELECT COUNT(*) FROM users WHERE id IN (:e, :b)', [':e' => $newUnverified, ':b' => $b]) === 2, 'Purge removes only site accounts unverified for more than 7 days');
check(MembershipRequests::purgeUnverified(time()) === 0, 'Purge is idempotent');
printf("PASS %d membership request checks\n", $checks);
