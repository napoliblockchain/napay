<?php
// Posta in uscita (email su file mentre l'SMTP non è disponibile): lettura delle email, link ammessi, accesso solo admin.
// HTTP reale + DB usa e getta, mail su file. Mai DB condivisi.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime' || env('MAIL_TRANSPORT') !== 'file') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
$app = new yii\web\Application(require $root . '/config/web.php');
$db = $app->db;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);

use app\components\mail\Outbox;

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
    $jar = tempnam(sys_get_temp_dir(), 'napay-outbox-');
    $page = web('/site/login');
    return web('/site/login', ['_csrf' => csrf($page), 'LoginForm' => ['email' => $email, 'password' => 'fixture-password-only']]);
}
function eml(string $to, string $subject, string $body, string $type = 'text/plain; charset=utf-8', string $encoding = 'quoted-printable'): string {
    return "From: Napay <noreply@example.invalid>\r\nTo: $to\r\nSubject: $subject\r\nMIME-Version: 1.0\r\nContent-Type: $type\r\n"
        . "Content-Transfer-Encoding: $encoding\r\n\r\n" . ($encoding === 'quoted-printable' ? quoted_printable_encode($body) : $body) . "\r\n";
}

$host = 'napay.example.invalid';
$link = 'https://napay.example.invalid/pos-invitation/accept?token=AbC123&x=1';

// --- parser: multipart annidato, quoted-printable, base64, &amp;, oggetto codificato, intestazioni ripiegate
$html = base64_encode('<p><a href="https://napay.example.invalid/site/activate?id=7&amp;sign=Zz">Attiva</a>'
    . '<img src="https://napay.example.invalid/logo.png"><a href="https://evil.example.invalid/x">x</a></p>');
$raw = "To: socio@example.invalid\r\nSubject: =?UTF-8?B?" . base64_encode('Attivazione àccount') . "?=\r\nContent-Type: multipart/mixed;\r\n boundary=\"OUT\"\r\n\r\n"
    . "--OUT\r\nContent-Type: multipart/alternative; boundary=IN\r\n\r\n"
    . "--IN\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
    . quoted_printable_encode("Apri $link oppure http://napay.example.invalid/insecure e https://napay.example.invalid:8443/porta") . "\r\n"
    . "--IN\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split($html) . "--IN--\r\n"
    . "--OUT\r\nContent-Type: application/pdf\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode('https://napay.example.invalid/allegato') . "\r\n--OUT--\r\n";
$parsed = Outbox::parse($raw, $host);
check($parsed['to'] === 'socio@example.invalid' && $parsed['subject'] === 'Attivazione àccount', 'Recipient and encoded subject decoded');
check(in_array($link, $parsed['links'], true), 'Quoted-printable link in nested multipart found');
check(in_array('https://napay.example.invalid/site/activate?id=7&sign=Zz', $parsed['links'], true), 'Base64 HTML link found with &amp; decoded');
check(count($parsed['links']) === 2, 'Only HTTPS links of the source host: no foreign host, plain HTTP, explicit port, images or attachments');
check(Outbox::parse(eml('a@example.invalid', 'S', "https://napay.example.invalid/a\r\nhttps://napay.example.invalid/a"), $host)['links'] === ['https://napay.example.invalid/a'], 'Duplicate links listed once');
check(Outbox::parse("garbage without headers", $host) === ['to' => '', 'subject' => '', 'links' => []], 'Malformed email yields no data');

// --- fonte: ordine, limite, file illeggibili, collegamenti simbolici, altri file ignorati
$dir = sys_get_temp_dir() . '/napay-outbox-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
for ($i = 0; $i < Outbox::LIMIT + 2; $i++) {
    file_put_contents("$dir/m$i.eml", eml("r$i@example.invalid", "Mail $i", "https://napay.example.invalid/n/$i"));
    touch("$dir/m$i.eml", 1700000000 + $i);
}
file_put_contents("$dir/big.eml", str_repeat('x', 524289)); touch("$dir/big.eml", 1800000000);
file_put_contents("$dir/note.txt", eml('t@example.invalid', 'Txt', 'https://napay.example.invalid/txt')); touch("$dir/note.txt", 1900000000);
file_put_contents("$dir.target", eml('l@example.invalid', 'Linked', 'https://napay.example.invalid/l')); touch("$dir.target", 1900000001); symlink("$dir.target", "$dir/link.eml");
$list = Outbox::messages(['dir' => $dir, 'host' => $host]);
check(count($list) === Outbox::LIMIT, 'At most LIMIT emails listed');
check($list[0]['readable'] === false && $list[0]['links'] === [], 'Oversized email listed as not readable, content not loaded');
check($list[1]['subject'] === 'Mail ' . (Outbox::LIMIT + 1) && $list[1]['links'] === ['https://napay.example.invalid/n/' . (Outbox::LIMIT + 1)], 'Newest email first');
check(array_intersect(['Txt', 'Linked'], array_column($list, 'subject')) === [], 'Non-.eml files and symlinks ignored');
check(Outbox::messages(['dir' => "$dir/missing", 'host' => $host]) === [], 'Missing directory yields an empty list');

// --- fonti attive: Napay con la posta su file; wallet solo con cartella assoluta esistente e URL HTTPS
$sources = Outbox::sources();
check(array_keys($sources) === ['napay'] && $sources['napay']['host'] === $host, 'Napay source active with file mail, wallet absent without configuration');
putenv("NAPAY_WALLET_MAIL_DIR=$dir"); putenv('NAPAY_WALLET_URL=https://wallet.example.invalid');
check((Outbox::sources()['wallet'] ?? null) === ['dir' => $dir, 'host' => 'wallet.example.invalid'], 'Wallet source active with absolute directory and HTTPS URL');
putenv('NAPAY_WALLET_URL=http://wallet.example.invalid');
check(!isset(Outbox::sources()['wallet']), 'Wallet source refused with plain HTTP URL');
putenv('NAPAY_WALLET_URL=https://wallet.example.invalid'); putenv('NAPAY_WALLET_MAIL_DIR=relative/dir');
check(!isset(Outbox::sources()['wallet']), 'Wallet source refused with relative directory');
putenv('NAPAY_WALLET_MAIL_DIR'); putenv('NAPAY_WALLET_URL');
array_map('unlink', glob("$dir/*") ?: []); rmdir($dir); unlink("$dir.target");

// --- pagina: solo admin, link visibili, contenuto delle email codificato, niente cache, consultazione registrata
$mail = Yii::getAlias('@runtime/mail');
if (!is_dir($mail)) mkdir($mail, 0700, true);
$file = "$mail/outbox-test-" . bin2hex(random_bytes(4)) . '.eml';
file_put_contents($file, eml('<script>alert(1)</script>@example.invalid', '<img src=x onerror=alert(1)>', "Invito: $link"));
@chown($mail, 33); @chown($file, 33);
$anonymous = web('/mail-outbox/index');
check($anonymous['status'] === 302 && stripos($anonymous['headers'], 'site/login') !== false, 'Anonymous redirected to login');
check(login('user@example.invalid')['status'] === 302, 'Fixture user logs in');
check(web('/mail-outbox/index')['status'] === 403, 'Non-admin user denied');
check(strpos(web('/site/index')['body'], 'mail-outbox') === false, 'Menu entry hidden from non-admin user');
check(login('merchant@example.invalid')['status'] === 302 && web('/mail-outbox/index')['status'] === 403, 'Merchant denied');
check(login('admin@example.invalid')['status'] === 302, 'Fixture admin logs in');
$logs = (int) $db->createCommand("SELECT COUNT(*) FROM logs WHERE description LIKE 'Mail outbox viewed%'")->queryScalar();
$page = web('/mail-outbox/index');
check($page['status'] === 200 && strpos($page['body'], htmlspecialchars($link, ENT_QUOTES)) !== false, 'Admin sees the email link');
check(strpos($page['body'], '<script>alert(1)</script>') === false && strpos($page['body'], '<img src=x') === false
    && strpos($page['body'], '&lt;img src=x onerror=alert(1)&gt;') !== false, 'Recipient and subject HTML-encoded');
check(preg_match('/^Cache-Control:.*no-store/mi', $page['headers']) === 1, 'Page not cached');
check((int) $db->createCommand("SELECT COUNT(*) FROM logs WHERE description LIKE 'Mail outbox viewed%'")->queryScalar() === $logs + 1, 'Viewing recorded in the activity log');
check(strpos($page['body'], 'mail-outbox.js') !== false && strpos($page['body'], 'data-napay-copy') !== false, 'Copy button served from the asset bundle');
check(strpos(web('/site/index')['body'], 'mail-outbox') !== false, 'Menu entry visible to admin');
check(web('/mail-outbox/index', ['_csrf' => csrf($page)])['status'] === 405, 'POST not allowed');
unlink($file);
echo "Mail outbox checks: $checks\n";
