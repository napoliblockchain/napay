<?php
// SIP-040: documenti legali versionati. Pagine pubbliche, gestione admin, immutabilità, XSS, consenso con versione,
// nuova accettazione delle condizioni. HTTP reale + DB usa e getta. Mai DB condivisi.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
$app = new yii\web\Application(require $root . '/config/web.php');
$db = $app->db;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);

use app\models\LegalDocuments;

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
function anonymous(): void { global $jar; $jar = tempnam(sys_get_temp_dir(), 'napay-legal-'); }
function login(string $email): array {
    anonymous();
    $page = web('/site/login');
    return web('/site/login', ['_csrf' => csrf($page), 'LoginForm' => ['email' => $email, 'password' => 'fixture-password-only']]);
}
function location(array $r): string { return preg_match('/^Location:\s*(\S+)/mi', $r['headers'], $m) ? $m[1] : ''; }
function scalar(string $sql, array $p = []) { global $db; return $db->createCommand($sql, $p)->queryScalar(); }
function post(string $path, array $fields = []): array { $page = web('/site/index'); return web($path, ['_csrf' => csrf($page)] + $fields); }

// --- nessun documento pubblicato: pagine "in preparazione", registrazione aperta (decisione B)
anonymous();
foreach (['privacy', 'terms', 'cookies'] as $type) {
    $r = web('/legal/' . $type);
    check($r['status'] === 404 && str_contains($r['body'], 'in preparazione'), "Unpublished $type: 404 page without any text");
}
check(str_contains(web('/site/login')['body'], '/legal/cookies') && str_contains(web('/site/login')['body'], '/legal/privacy'), 'Login page footer links the legal pages');
check(web('/legal/other')['status'] === 404 && web('/legal/privacy?version=abc')['status'] === 404, 'Unknown type or malformed version: 404');
$signup = web('/site/signup');
check($signup['status'] === 200 && str_contains($signup['body'], '/legal/privacy') && str_contains($signup['body'], '/legal/terms')
    && !str_contains($signup['body'], 'print-privacy') && !str_contains($signup['body'], 'term-of-use'), 'Signup links the new public pages');

// --- accesso alla gestione
anonymous();
$r = web('/legal-documents/index');
check($r['status'] === 302 && str_contains(location($r), 'site/login'), 'Anonymous redirected to login');
check(login('user@example.invalid')['status'] === 302 && web('/legal-documents/index')['status'] === 403, 'Non-admin denied');
check(login('merchant@example.invalid')['status'] === 302 && web('/legal-documents/index')['status'] === 403, 'Merchant denied');
check(login('admin@example.invalid')['status'] === 302 && web('/legal-documents/index')['status'] === 200, 'Admin sees legal documents');
check(web('/legal-documents/draft', ['type' => 'privacy'])['status'] === 400, 'Draft creation requires CSRF');
check(web('/legal-documents/draft')['status'] === 405, 'Draft creation only by POST');

// --- bozza dal testo standard: segnaposto, pubblicazione rifiutata, bozza invisibile al pubblico
$r = post('/legal-documents/draft', ['type' => 'privacy']);
$draft = LegalDocuments::findOne(['type' => 'privacy', 'status' => 'draft']);
check($r['status'] === 302 && $draft !== null && (int) $draft->version === 1 && str_contains($draft->body, LegalDocuments::PLACEHOLDER), 'Draft from the standard text keeps parts to approve');
check((int) LegalDocuments::find()->where(['type' => 'privacy'])->count() === 1 && post('/legal-documents/draft', ['type' => 'privacy'])['status'] === 302
    && (int) LegalDocuments::find()->where(['type' => 'privacy'])->count() === 1, 'Only one open draft per type');
post('/legal-documents/publish?id=' . (int) $draft->id);
check(LegalDocuments::findOne($draft->id)->status === 'draft', 'Publishing refused while placeholders remain');
anonymous();
check(web('/legal/privacy')['status'] === 404 && web('/legal/privacy?version=1')['status'] === 404, 'Drafts never public');

// --- testo con tentativi XSS: salvato, pubblicato, mostrato filtrato
login('admin@example.invalid');
$evil = "## Titolare\n\nTesto approvato.\n\n<script>window.pwned=1</script>\n\n<img src=x onerror=alert(1)>\n\n[link](javascript:alert(1)) e [sito](https://napoliblockchain.it)\n";
$page = web('/legal-documents/update?id=' . (int) $draft->id);
web('/legal-documents/update?id=' . (int) $draft->id, ['_csrf' => csrf($page), 'LegalDocuments' => ['title' => 'Informativa <b>privacy</b>', 'body' => $evil]]);
post('/legal-documents/publish?id=' . (int) $draft->id);
$doc = LegalDocuments::findOne($draft->id);
check($doc->status === 'published' && (int) $doc->published_by > 0 && (int) $doc->published_at > 0, 'Clean draft published by the admin');
anonymous();
$r = web('/legal/privacy');
check($r['status'] === 200 && str_contains($r['body'], 'Testo approvato') && str_contains($r['body'], 'https://napoliblockchain.it'), 'Anonymous reads the published version');
check(!str_contains($r['body'], '<script>window.pwned') && !str_contains($r['body'], 'onerror') && !str_contains($r['body'], 'javascript:')
    && !str_contains($r['body'], '<b>privacy</b>'), 'Script, event handlers, javascript links and title HTML neutralised');

// --- versioni pubblicate immutabili
login('admin@example.invalid');
$page = web('/legal-documents/update?id=' . (int) $doc->id);
check($page['status'] === 302 && str_contains(location($page), 'preview'), 'Published version opens read-only');
$page = web('/legal-documents/preview?id=' . (int) $doc->id);
web('/legal-documents/update?id=' . (int) $doc->id, ['_csrf' => csrf($page), 'LegalDocuments' => ['title' => 'x', 'body' => 'changed']]);
post('/legal-documents/delete?id=' . (int) $doc->id);
$after = LegalDocuments::findOne($doc->id);
check($after !== null && $after->body === $doc->body && $after->title === $doc->title, 'Published version cannot be changed or deleted over HTTP');
$after->body = 'changed'; $model = $after->save(false); $deleted = $after->delete();
check($model === false && $deleted === false && LegalDocuments::findOne($doc->id)->body === $doc->body, 'Published version cannot be changed or deleted in the model');
$dup = new LegalDocuments(['type' => 'privacy', 'version' => 9, 'title' => 't', 'body' => 'b', 'status' => 'draft', 'created_by' => null]);
check($dup->save() && $dup->publish(1) && !$dup->publish(1), 'Concurrent second publication of the same draft refused');
check((int) LegalDocuments::current('privacy')->version === 9, 'Highest published version is in force');

// --- condizioni: consenso con versione alla registrazione
$terms = LegalDocuments::newDraft('terms', 'Condizioni', "## Oggetto\n\nCondizioni approvate.", 1);
check($terms->publish(1), 'Terms version 1 published');
anonymous();
$page = web('/site/signup');
check(str_contains($page['body'], 'name="legal[terms]" value="' . (int) $terms->id . '"'), 'Signup form carries the terms version in force');
$form = ['_csrf' => csrf($page), 'SignupForm' => ['email' => 'legal-signup@example.invalid', 'first_name' => 'Legal', 'last_name' => 'Fixture',
    'password' => 'legal-fixture-password', 'repeat_password' => 'legal-fixture-password', 'member_type' => 'pf'],
    'UserConsensus' => ['consenso_statuto' => '1', 'consenso_privacy' => '1', 'consenso_condizioni' => '1', 'consenso_marketing' => '0'],
    'legal' => ['privacy' => (string) LegalDocuments::current('privacy')->id, 'terms' => (string) ((int) $terms->id + 1000)]];
web('/site/signup', $form);
check(scalar("SELECT COUNT(*) FROM users WHERE email='legal-signup@example.invalid'") == 0, 'Signup with an outdated document version refused');
$form['legal']['terms'] = (string) $terms->id;
web('/site/signup', $form);
$row = $db->createCommand("SELECT c.privacy_document_id, c.terms_document_id, c.consenso_marketing FROM user_consensus c JOIN users u ON u.id=c.user_id WHERE u.email='legal-signup@example.invalid'")->queryOne();
check(is_array($row) && (int) $row['terms_document_id'] === (int) $terms->id && (int) $row['privacy_document_id'] === (int) LegalDocuments::current('privacy')->id
    && (int) $row['consenso_marketing'] === 0, 'Consent stores the versions shown; marketing stays optional');

// --- nuova accettazione: account esistente senza versione
check(login('user@example.invalid')['status'] === 302, 'Fixture user logs in');
$r = web('/site/index');
check($r['status'] === 302 && str_contains(location($r), 'accept-terms'), 'Backoffice blocked until the terms in force are accepted');
check(web('/legal/terms')['status'] === 200, 'Public legal pages stay reachable while blocked');
$page = web('/site/accept-terms');
check($page['status'] === 200 && str_contains($page['body'], 'Condizioni approvate'), 'Acceptance page shows the terms in force');
web('/site/accept-terms', ['_csrf' => csrf($page), 'document' => (string) $terms->id]);
check(str_contains(location(web('/site/index')), 'accept-terms'), 'Without the checkbox nothing is accepted');
$v2 = LegalDocuments::newDraft('terms', 'Condizioni', "## Oggetto\n\nCondizioni versione due.", 1);
check($v2->publish(1), 'Terms version 2 published while the page was open');
web('/site/accept-terms', ['_csrf' => csrf($page), 'document' => (string) $terms->id, 'accept' => '1']);
check(str_contains(location(web('/site/index')), 'accept-terms'), 'Accepting an outdated version is refused');
$page = web('/site/accept-terms');
web('/site/accept-terms', ['_csrf' => csrf($page), 'document' => (string) $v2->id, 'accept' => '1']);
$uid = (int) scalar("SELECT id FROM users WHERE email='user@example.invalid'");
check(web('/site/index')['status'] === 200 && (int) scalar('SELECT terms_document_id FROM user_consensus WHERE user_id=:u ORDER BY id DESC LIMIT 1', [':u' => $uid]) === (int) $v2->id,
    'Accepting the version in force unlocks the backoffice and is recorded');

// --- versioni storiche
anonymous();
$old = web('/legal/terms?version=1');
check($old['status'] === 200 && str_contains($old['body'], 'Condizioni approvate') && str_contains($old['body'], 'versione precedente'), 'Earlier version readable with notice');
check(web('/legal/terms?version=99')['status'] === 404, 'Missing version: 404');
echo "PASS $checks legal document checks\n";
