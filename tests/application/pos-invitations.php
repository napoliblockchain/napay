<?php
// C4 parte D (SIP-021/SIP-023): inviti operatore POS; HTTP reale, mail su file, gare in processi separati, DB usa e getta.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime' || env('MAIL_TRANSPORT') !== 'file') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';

use app\components\identity\AccountEligibility;
use app\components\pos\management\ManagementException as Failure;
use app\components\pos\management\PosInvitations;
use app\components\pos\management\Scope;

$app = new yii\web\Application(require $root . '/config/web.php');
$app->user->enableSession = false;
$db = $app->db;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);

/** Processo figlio: accettazione concorrente dello stesso indirizzo da due inviti diversi. */
if (($argv[1] ?? '') === '--race-accept') {
    try {
        fwrite(STDOUT, "ready\n"); fread(STDIN, 1);
        PosInvitations::accept($argv[2], 'Gara', 'Operatore', 'fixture-password-long', null);
        exit(0);
    } catch (Failure $e) { exit($e->getMessage() === Failure::INVALID ? 10 : 3); }
    catch (Throwable $e) { exit(2); }
}

$checks = 0; $jar = null;
function check($condition, $label) {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks; echo 'PASS: ' . $label . "\n";
}
function fails(callable $fn, string $code, string $label) {
    try { $fn(); } catch (Failure $e) { check($e->getMessage() === $code, $label); return; }
    throw new RuntimeException($label . ' (nessun errore)');
}
function request($path, ?array $form = null) {
    global $jar;
    $curl = curl_init('http://127.0.0.1' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($form !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form)]);
    $raw = curl_exec($curl);
    if ($raw === false) throw new RuntimeException('HTTP transport failed.');
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $result = ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    curl_close($curl);
    return $result;
}
function xp($page) { $d = new DOMDocument(); @$d->loadHTML($page['body'], LIBXML_NONET); return new DOMXPath($d); }
function csrf($page) {
    $n = xp($page)->query('//meta[@name="csrf-token"]/@content');
    if ($n->length !== 1) throw new RuntimeException('CSRF unavailable.');
    return $n->item(0)->nodeValue;
}
function newJar() { global $jar; $jar = tempnam('/tmp', 'napay-c4d-'); }
function login($email) {
    newJar();
    $page = request('/site/login');
    return request('/site/login', ['_csrf' => csrf($page), 'LoginForm' => ['email' => $email, 'password' => 'fixture-password-only']])['status'];
}
function flash() {
    $page = request('/pos-management/index');
    $n = xp($page)->query('//div[contains(@class,"alert")]');
    return $n->length ? trim($n->item(0)->textContent) : '';
}
function invite($email, array $extra = []) {
    $page = request('/pos-management/index');
    request('/pos-management/invitation-create', ['_csrf' => csrf($page), 'InvitationForm' => ['email' => $email] + $extra]);
    return flash();
}
function scalar($sql, array $p = []) { global $db; return $db->createCommand($sql, $p)->queryScalar(); }
function scopeOf($userId): ?Scope { global $app; $app->user->setIdentity(app\models\Users::findOne($userId)); return Scope::current(); }
/** Unica mail nuova (mai letta prima) per l'indirizzo, decodificata. PrivateMailer usa nomi casuali: niente ordinamento. */
$seenMail = [];
function mailTo(string $email): string {
    global $seenMail;
    $found = [];
    foreach (glob(Yii::getAlias('@runtime/mail') . '/*.eml') ?: [] as $file) {
        if (isset($seenMail[$file])) continue;
        $raw = (string) file_get_contents($file);
        if (preg_match('/^To: .*' . preg_quote($email, '/') . '/mi', $raw)) $found[$file] = $raw;
    }
    if (count($found) !== 1) throw new RuntimeException('Expected exactly one new mail, found ' . count($found));
    $seenMail[array_key_first($found)] = true;
    return quoted_printable_decode(str_replace(["=\r\n", "=\n"], '', reset($found)));
}
function tokenIn(string $mail): ?string { return preg_match('#pos-invitation/accept\?token=([A-Za-z0-9_-]{43})#', $mail, $m) ? $m[1] : null; }
function acceptPage(string $token) { newJar(); return request('/pos-invitation/accept?token=' . $token); }
function acceptPost(string $token, array $fields) {
    $page = acceptPage($token);
    return request('/pos-invitation/accept', ['_csrf' => csrf($page), 'AcceptInvitationForm' => $fields + ['token' => $token]]);
}
$valid = ['first_name' => 'Nuovo', 'last_name' => 'Operatore', 'password' => 'fixture-password-long', 'repeat_password' => 'fixture-password-long'];

// --- fixture: due gestori PG con merchant e negozio propri, un merchant non qualificato
$level = fn($l) => (int) scalar('SELECT id FROM privileges WHERE level=:l ORDER BY id LIMIT 1', [':l' => $l]);
$hash = password_hash('fixture-password-only', PASSWORD_BCRYPT);
$user = function (string $name, int $privilegeLevel) use ($db, $level, $hash) {
    $db->createCommand()->insert('users', ['username' => $name . '@example.invalid', 'email' => $name . '@example.invalid', 'password' => $hash,
        'first_name' => 'C4D', 'last_name' => $name, 'oauth_provider' => 'fixture', 'oauth_uid' => $name, 'authKey' => 'fixture-' . $name,
        'accessToken' => 'fixture-' . $name, 'picture' => '/favicon.ico', 'privilege_id' => $level($privilegeLevel), 'is_merchant' => 0, 'is_active' => 1])->execute();
    return (int) $db->lastInsertID;
};
$merchant = function (int $owner, string $description) use ($db) {
    $db->createCommand()->insert('merchants', ['user_id' => $owner, 'description' => $description, 'email' => 'fixture@example.invalid', 'vatNumber' => '12345678901',
        'phone' => '', 'mobile' => '', 'addressStreet' => '', 'addressNumberHouse' => '', 'addressCity' => '', 'addressZip' => '', 'addressProvince' => '', 'addressCountry' => ''])->execute();
    return (int) $db->lastInsertID;
};
$pg = fn(int $owner, string $name) => $db->createCommand()->insert('member_profiles', ['user_id' => $owner, 'is_corporate' => 1, 'denomination' => $name])->execute();
$managerD = $user('c4d-manager-d', 30); $pg($managerD, 'C4D PG D'); $merchantD = $merchant($managerD, 'C4D merchant D');
$managerE = $user('c4d-manager-e', 30); $pg($managerE, 'C4D PG E'); $merchantE = $merchant($managerE, 'C4D merchant E');
$ownerX = $user('c4d-owner-x', 30); $merchantX = $merchant($ownerX, 'C4D merchant non qualificato');
$admin = (int) scalar("SELECT id FROM users WHERE username='runtime-admin'");
$logStart = (int) scalar('SELECT COALESCE(MAX(id),0) FROM logs');
$usersBefore = (int) scalar('SELECT COUNT(*) FROM users');
check(true, 'Invitation fixture created in disposable DB');

// --- invito a un indirizzo libero (con maiuscole e spazi)
check(login('c4d-manager-d@example.invalid') === 302, 'Manager D logged in');
$okFlash = invite('  C4D-New@Example.invalid ');
$row = $db->createCommand("SELECT * FROM pos_operator_invitations WHERE email='c4d-new@example.invalid'")->queryOne();
check($row && (int) $row['merchant_id'] === $merchantD && (int) $row['created_by'] === $managerD && preg_match('/^[0-9a-f]{64}$/', $row['token_sha256'])
    && abs((int) $row['expires_at'] - (time() + 72 * 3600)) < 120 && $row['sent_at'] !== null, 'Invitation stored with normalized email, digest only, 72h expiry, sent');
$token = tokenIn(mailTo('c4d-new@example.invalid'));
check($token !== null && hash('sha256', $token) === $row['token_sha256'], 'Mail carries the only clear copy of the token');
check(!str_contains(request('/pos-management/index')['body'], $token), 'Token never shown to the merchant');
// --- indirizzo gia registrato: stessa risposta, solo avviso senza link
$knownFlash = invite('user@example.invalid');
$notice = mailTo('user@example.invalid');
check($knownFlash === $okFlash && $okFlash !== '' && $notice !== '' && tokenIn($notice) === null && !str_contains($notice, 'pos-invitation'),
    'Registered address: identical merchant response, notice without link');
check((int) scalar("SELECT COUNT(*) FROM pos_operator_invitations WHERE email='user@example.invalid'") === 1
    && scalar("SELECT password FROM users WHERE username='runtime-user'") !== null, 'Registered account untouched');

// --- pagina del link
$page = acceptPage($token);
check($page['status'] === 200 && str_contains($page['body'], 'C4D merchant D') && str_contains($page['headers'], 'no-store')
    && scalar('SELECT consumed_at FROM pos_operator_invitations WHERE id=:i', [':i' => $row['id']]) === null, 'GET shows the form, no-store, token not consumed');
check(str_contains(acceptPage(str_repeat('A', 43))['body'], 'non valido'), 'Unknown token shows the generic invalid page');
check(request('/pos-invitation/accept', ['AcceptInvitationForm' => ['token' => $token] + $valid])['status'] === 400, 'POST without CSRF rejected');
$bad = acceptPost($token, ['repeat_password' => 'different-password'] + $valid);
check($bad['status'] === 200 && (int) scalar('SELECT COUNT(*) FROM users') === $usersBefore, 'Password mismatch: no account created');

// --- accettazione
$evil = '<script>window.pwned=1</script>';
$done = acceptPost($token, ['first_name' => $evil] + $valid);
$new = $db->createCommand("SELECT u.*, p.level, p.codice_ruolo FROM users u JOIN privileges p ON p.id=u.privilege_id WHERE u.email='c4d-new@example.invalid'")->queryOne();
check($done['status'] === 200 && str_contains($done['body'], 'Account operatore creato') && $new && (int) $new['level'] === 0 && $new['codice_ruolo'] === 'ROLE_USER'
    && (int) $new['is_active'] === 1 && (int) $new['is_merchant'] === 0 && $new['username'] === 'c4d-new@example.invalid'
    && password_verify('fixture-password-long', $new['password']), 'Accepted: dedicated active base account, own password');
$uid = (int) $new['id'];
check((int) scalar('SELECT COUNT(*) FROM member_profiles WHERE user_id=:u', [':u' => $uid]) === 0
    && (int) scalar('SELECT COUNT(*) FROM membership_payments WHERE user_id=:u', [':u' => $uid]) === 0
    && (int) scalar('SELECT COUNT(*) FROM merchants WHERE user_id=:u', [':u' => $uid]) === 0, 'No member profile, fees or merchant for the operator');
check((int) scalar('SELECT is_enabled FROM pos_operators WHERE user_id=:u AND merchant_id=:m', [':u' => $uid, ':m' => $merchantD]) === 0
    && (int) scalar('SELECT COUNT(*) FROM pos_store_operators WHERE user_id=:u', [':u' => $uid]) === 0, 'Operator profile disabled, no grants');
check(AccountEligibility::kind($uid) === AccountEligibility::OPERATOR, 'Shared policy classifies the account as operator');
check((int) scalar('SELECT consumed_user_id FROM pos_operator_invitations WHERE id=:i', [':i' => $row['id']]) === $uid, 'Invitation consumed by the new account');
$users = (int) scalar('SELECT COUNT(*) FROM users');
check(str_contains(acceptPage($token)['body'], 'non valido') && str_contains(acceptPost($token, $valid)['body'] ?? '', 'non valido')
    && (int) scalar('SELECT COUNT(*) FROM users') === $users, 'Used token rejected, no second account');
check(login('c4d-manager-d@example.invalid') === 302 && str_contains(request('/pos-management/index')['body'], htmlspecialchars($evil . ' Operatore', ENT_QUOTES))
    && !str_contains(request('/pos-management/index')['body'], $evil), 'Malicious operator name encoded in merchant list');

// --- sostituzione, annullamento, scadenza
invite('c4d-two@example.invalid'); $first = tokenIn(mailTo('c4d-two@example.invalid'));
sleep(1);
invite('c4d-two@example.invalid'); $second = tokenIn(mailTo('c4d-two@example.invalid'));
check($first !== $second && str_contains(acceptPage($first)['body'], 'non valido') && str_contains(acceptPage($second)['body'], 'C4D merchant D')
    && (int) scalar("SELECT COUNT(*) FROM pos_operator_invitations WHERE email='c4d-two@example.invalid' AND revoked_at IS NULL") === 1, 'New invitation supersedes the active one');
$scopeD = scopeOf($managerD);
$twoId = (int) scalar("SELECT id FROM pos_operator_invitations WHERE email='c4d-two@example.invalid' AND revoked_at IS NULL");
PosInvitations::resend($scopeD, $twoId); $third = tokenIn(mailTo('c4d-two@example.invalid'));
check($third !== null && $third !== $second, 'Resend mails a new token');
check(str_contains(acceptPage($second)['body'], 'non valido'), 'Resend invalidates the previous link');
check(str_contains(acceptPage($third)['body'], 'C4D merchant D'), 'Resent link is valid');
PosInvitations::revoke($scopeD, $twoId);
check(str_contains(acceptPage($third)['body'], 'non valido'), 'Revoked invitation rejected');
fails(fn() => PosInvitations::revoke($scopeD, $twoId), Failure::NOT_ALLOWED, 'Revoked invitation cannot be revoked or resent again');
PosInvitations::invite($scopeD, $merchantD, 'c4d-old@example.invalid'); $old = tokenIn(mailTo('c4d-old@example.invalid'));
$db->createCommand()->update('pos_operator_invitations', ['expires_at' => time() - 1, 'created_at' => time() - 100, 'updated_at' => time() - 1], ['email' => 'c4d-old@example.invalid'])->execute();
check(str_contains(acceptPage($old)['body'], 'non valido'), 'Expired invitation rejected');

// --- account registrato dopo l'invito: rifiuto senza consumo
PosInvitations::invite($scopeD, $merchantD, 'c4d-late@example.invalid'); $late = tokenIn(mailTo('c4d-late@example.invalid'));
$user('c4d-late', 0);
fails(fn() => PosInvitations::accept($late, 'A', 'B', 'fixture-password-long', null), Failure::INVALID, 'Address registered after the invitation: refused');
check(scalar("SELECT consumed_at FROM pos_operator_invitations WHERE email='c4d-late@example.invalid' AND revoked_at IS NULL") === null
    && (int) scalar("SELECT COUNT(*) FROM pos_operators o JOIN users u ON u.id=o.user_id WHERE u.email='c4d-late@example.invalid'") === 0, 'Existing account never linked, invitation not consumed');

// --- utente connesso: nessun collegamento
PosInvitations::invite($scopeD, $merchantD, 'c4d-signed@example.invalid'); $signed = tokenIn(mailTo('c4d-signed@example.invalid'));
login('c4d-manager-e@example.invalid');
$page = request('/pos-invitation/accept?token=' . $signed);
check(str_contains($page['body'], 'Esci') && scalar("SELECT consumed_at FROM pos_operator_invitations WHERE email='c4d-signed@example.invalid'") === null, 'Signed-in user is not linked');

// --- scope
$scopeE = scopeOf($managerE);
$dId = (int) scalar("SELECT id FROM pos_operator_invitations WHERE email='c4d-signed@example.invalid'");
fails(fn() => PosInvitations::revoke($scopeE, $dId), Failure::NOT_FOUND, 'Other manager cannot revoke the invitation');
fails(fn() => PosInvitations::resend($scopeE, $dId), Failure::NOT_FOUND, 'Other manager cannot resend the invitation');
fails(fn() => PosInvitations::invite($scopeE, $merchantD, 'c4d-x@example.invalid'), Failure::NOT_FOUND, 'Manager cannot invite for another merchant');
check(!str_contains(request('/pos-management/index')['body'], 'c4d-signed@example.invalid'), 'Other manager does not see the invitation');
fails(fn() => PosInvitations::invite(scopeOf($admin), $merchantX, 'c4d-x@example.invalid'), Failure::NOT_ALLOWED, 'No invitation for an unqualified merchant');
check(scopeOf($uid) === null, 'Invited operator has no management scope');

// --- review D: password oltre 72 byte, nomi non testuali, generazioni superate, errore mail
$scopeD = scopeOf($managerD);
PosInvitations::invite($scopeD, $merchantD, 'c4d-bytes@example.invalid'); $bytes = tokenIn(mailTo('c4d-bytes@example.invalid'));
$users = (int) scalar('SELECT COUNT(*) FROM users');
$long = str_repeat("\u{00E9}", 37); // 37 caratteri, 74 byte
$r = acceptPost($bytes, ['password' => $long, 'repeat_password' => $long] + $valid);
check($r['status'] === 200 && str_contains($r['body'], '72 bytes') && (int) scalar('SELECT COUNT(*) FROM users') === $users, 'Multibyte password over 72 bytes refused by the form');
fails(fn() => PosInvitations::accept($bytes, 'A', 'B', str_repeat('a', 73), null), Failure::INVALID, 'Service refuses passwords over 72 bytes');
foreach (['first_name', 'last_name'] as $field) {
    $r = acceptPost($bytes, [$field => ['x', 'y']] + $valid);
    check($r['status'] === 200 && (int) scalar('SELECT COUNT(*) FROM users') === $users
        && scalar("SELECT consumed_at FROM pos_operator_invitations WHERE email='c4d-bytes@example.invalid' AND revoked_at IS NULL") === null,
        "Array in $field: form re-rendered, no account, invitation not consumed");
}
$edge = str_repeat("\u{00E9}", 36); // esattamente 72 byte
$edgeUser = PosInvitations::accept($bytes, 'Limite', 'Byte', $edge, null);
check(password_verify($edge, (string) scalar('SELECT password FROM users WHERE id=:u', [':u' => $edgeUser])), 'Password of exactly 72 bytes accepted');
PosInvitations::invite($scopeD, $merchantD, 'c4d-gen@example.invalid'); $gen1 = tokenIn(mailTo('c4d-gen@example.invalid'));
$genId = (int) scalar("SELECT id FROM pos_operator_invitations WHERE email='c4d-gen@example.invalid' AND revoked_at IS NULL");
PosInvitations::resend($scopeD, $genId); $gen2 = tokenIn(mailTo('c4d-gen@example.invalid'));
$db->createCommand()->update('pos_operator_invitations', ['sent_at' => null, 'send_failures' => 0], ['id' => $genId])->execute();
$deliver = new ReflectionMethod(PosInvitations::class, 'deliver');
$deliver->invoke(null, $genId, 'c4d-gen@example.invalid', $gen1, false); mailTo('c4d-gen@example.invalid');
$state = $db->createCommand('SELECT sent_at, send_failures, token_sha256 FROM pos_operator_invitations WHERE id=:i', [':i' => $genId])->queryOne();
check($state['sent_at'] === null && (int) $state['send_failures'] === 0 && $state['token_sha256'] === hash('sha256', $gen2), 'Late outcome of an old generation does not touch the current one');
$deliver->invoke(null, $genId, 'c4d-gen@example.invalid', $gen2, false); mailTo('c4d-gen@example.invalid');
check(scalar('SELECT sent_at FROM pos_operator_invitations WHERE id=:i', [':i' => $genId]) !== null, 'Outcome of the current generation recorded');
$link = Yii::$app->params['webapp_link'];
Yii::$app->params['webapp_link'] = 'http://not-https.invalid';
try {
    PosInvitations::invite($scopeD, $merchantD, 'c4d-mailfail@example.invalid');
    PosInvitations::invite($scopeD, $merchantD, 'admin@example.invalid');
} finally { Yii::$app->params['webapp_link'] = $link; }
$fail = $db->createCommand("SELECT sent_at, send_failures FROM pos_operator_invitations WHERE email='c4d-mailfail@example.invalid'")->queryOne();
check($fail['sent_at'] === null && (int) $fail['send_failures'] === 1
    && PosInvitations::status(['consumed_at' => null, 'revoked_at' => null, 'expires_at' => time() + 60] + $fail, time()) === 'Invio non riuscito',
    'Mail configuration error after commit becomes a recorded delivery failure');
check(scalar("SELECT sent_at FROM pos_operator_invitations WHERE email='admin@example.invalid'") !== null, 'Registered address unaffected by the link error (notice has no link)');

// --- limiti (anche sugli avvisi)
$scopeE = scopeOf($managerE);
foreach ([1, 2, 3] as $n) PosInvitations::invite($scopeE, $merchantE, 'c4d-rl@example.invalid');
fails(fn() => PosInvitations::invite($scopeE, $merchantE, 'c4d-rl@example.invalid'), Failure::RATE_LIMITED, 'Fourth invitation to the same address in a day refused');
foreach ([1, 2, 3] as $n) PosInvitations::invite($scopeE, $merchantE, 'other@example.invalid');
fails(fn() => PosInvitations::invite($scopeE, $merchantE, 'other@example.invalid'), Failure::RATE_LIMITED, 'Notices to a registered address are rate limited too');
fails(fn() => PosInvitations::invite($scopeE, $merchantE, 'not-an-email'), Failure::INVALID, 'Invalid address refused before any write');

// --- gara: due inviti (merchant D ed E) allo stesso indirizzo accettati insieme
PosInvitations::invite(scopeOf($managerD), $merchantD, 'c4d-race@example.invalid'); $raceD = tokenIn(mailTo('c4d-race@example.invalid'));
PosInvitations::invite(scopeOf($managerE), $merchantE, 'c4d-race@example.invalid'); $raceE = tokenIn(mailTo('c4d-race@example.invalid'));
check($raceD !== null && $raceE !== null && $raceD !== $raceE, 'Two merchants invited the same address');
$children = [];
foreach ([$raceD, $raceE] as $raceToken) {
    $process = proc_open([PHP_BINARY, __FILE__, '--race-accept', $raceToken], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Race fixture unavailable.');
    stream_set_timeout($pipes[1], 20);
    if (fgets($pipes[1]) !== "ready\n") throw new RuntimeException('Race fixture did not initialize.');
    $children[] = [$process, $pipes];
}
foreach ($children as [$process, $pipes]) { fwrite($pipes[0], 'x'); fclose($pipes[0]); }
$codes = [];
foreach ($children as [$process, $pipes]) { fclose($pipes[1]); $codes[] = proc_close($process); }
sort($codes);
check($codes === [0, 10] && (int) scalar("SELECT COUNT(*) FROM users WHERE email='c4d-race@example.invalid'") === 1
    && (int) scalar("SELECT COUNT(*) FROM pos_operator_invitations WHERE email='c4d-race@example.invalid' AND consumed_at IS NOT NULL") === 1
    && (int) scalar("SELECT COUNT(*) FROM pos_operators o JOIN users u ON u.id=o.user_id WHERE u.email='c4d-race@example.invalid'") === 1,
    'Concurrent acceptance: one account, one profile, loser invitation not consumed');

// --- registro
$logs = $db->createCommand('SELECT description FROM logs WHERE id>:i', [':i' => $logStart])->queryColumn();
$invitationLogs = array_filter($logs, fn($l) => str_contains($l, 'Invito operatore POS'));
check(count($invitationLogs) >= 10 && !array_filter($invitationLogs, fn($l) => str_contains($l, '@')), 'Invitation audit without email addresses');
foreach ([$token, $first, $second, $third, $old, $late, $signed, $raceD, $raceE] as $t) {
    if (array_filter($logs, fn($l) => str_contains($l, $t))) throw new RuntimeException('Token in audit log');
}
check(true, 'No token in the audit log');
echo "PASS $checks C4 invitation checks\n";
