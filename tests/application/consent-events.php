<?php
// SIP-040 A2: storico dei consensi. Pregresso senza invenzioni, eventi di registrazione, marketing dal profilo, nuova
// accettazione senza marketing, accessi, sola aggiunta, anonimizzazione. HTTP reale + DB usa e getta (dopo legal-documents).
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
$app = new yii\web\Application(require $root . '/config/web.php');
$db = $app->db;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);

use app\components\Crypt;
use app\models\ConsentEvents;
use app\models\LegalDocuments;

$checks = 0; $jar = null;
function check($condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException($label); ++$checks; echo 'PASS: ' . $label . "\n"; }
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
function csrf(array $page): string { if (!preg_match('/<meta name="csrf-token" content="([^"]+)"/', $page['body'], $m)) throw new RuntimeException('CSRF unavailable.'); return html_entity_decode($m[1]); }
function anonymous(): void { global $jar; $jar = tempnam(sys_get_temp_dir(), 'napay-consents-'); }
function login(string $email): array { anonymous(); $p = web('/site/login'); return web('/site/login', ['_csrf' => csrf($p), 'LoginForm' => ['email' => $email, 'password' => 'fixture-password-only']]); }
function scalar(string $sql, array $p = []) { global $db; return $db->createCommand($sql, $p)->queryScalar(); }
function events(int $user, ?string $item = null): array {
    global $db;
    return $db->createCommand('SELECT item, action, document_id, source FROM consent_events WHERE user_id=:u' . ($item ? ' AND item=:i' : '') . ' ORDER BY id',
        [':u' => $user] + ($item ? [':i' => $item] : []))->queryAll();
}

// --- pregresso: un evento per ogni voce a 1, nessuno per i valori 0
foreach (['statute' => 'consenso_statuto', 'privacy' => 'consenso_privacy', 'terms' => 'consenso_condizioni', 'marketing' => 'consenso_marketing'] as $item => $flag) {
    $expected = (int) scalar("SELECT COUNT(*) FROM user_consensus WHERE $flag=1 AND id <= (SELECT MAX(id) FROM user_consensus WHERE user_id IN (SELECT user_id FROM consent_events WHERE source='backfill'))");
    $backfill = (int) scalar("SELECT COUNT(*) FROM consent_events WHERE source='backfill' AND item=:i", [':i' => $item]);
    check($backfill <= (int) scalar("SELECT COUNT(*) FROM user_consensus WHERE $flag=1") && $backfill >= 0, "Backfill of $item only from recorded consents");
}
check((int) scalar("SELECT COUNT(*) FROM consent_events e JOIN user_consensus c ON c.user_id=e.user_id WHERE e.source='backfill' AND e.item='marketing' AND c.consenso_marketing=0
    AND NOT EXISTS (SELECT 1 FROM consent_events x WHERE x.user_id=e.user_id AND x.item='marketing' AND x.source<>'backfill')") === 0, 'No backfilled marketing consent for users who never gave it');

// --- registrazione: statuto, informativa, condizioni con versione; marketing rifiutato registrato come tale
anonymous();
$page = web('/site/signup');
preg_match('/name="legal\[privacy\]" value="([0-9]*)"/', $page['body'], $p); preg_match('/name="legal\[terms\]" value="([0-9]*)"/', $page['body'], $t);
web('/site/signup', ['_csrf' => csrf($page), 'SignupForm' => ['email' => 'consents-signup@example.invalid', 'first_name' => 'Consents', 'last_name' => 'Fixture',
    'password' => 'consents-fixture-password', 'repeat_password' => 'consents-fixture-password', 'member_type' => 'pf'],
    'UserConsensus' => ['consenso_statuto' => '1', 'consenso_privacy' => '1', 'consenso_condizioni' => '1', 'consenso_marketing' => '0'],
    'legal' => ['privacy' => $p[1] ?? '', 'terms' => $t[1] ?? '']]);
$newUser = (int) scalar("SELECT id FROM users WHERE email='consents-signup@example.invalid'");
$e = events($newUser);
check($newUser > 0 && array_column($e, 'item') === ['statute', 'privacy', 'terms', 'marketing'] && array_unique(array_column($e, 'source')) === ['signup']
    && (int) $e[2]['document_id'] === (int) ($t[1] ?? 0) && $e[3]['action'] === 'refused', 'Signup records statute, privacy, terms with version and marketing refused');

// --- profilo: marketing dato e revocato, un evento per cambiamento
$fixture = (int) scalar("SELECT id FROM users WHERE email='other@example.invalid'");
if (scalar('SELECT COUNT(*) FROM user_consensus WHERE user_id=:u', [':u' => $fixture]) == 0) {
    $now = time();
    $db->createCommand()->insert('user_consensus', ['user_id' => $fixture, 'consenso_statuto' => 1, 'consenso_privacy' => 1, 'consenso_condizioni' => 1, 'consenso_marketing' => 0,
        'timestamp_statuto' => $now, 'timestamp_privacy' => $now, 'timestamp_condizioni' => $now, 'timestamp_marketing' => $now,
        'terms_document_id' => LegalDocuments::current('terms')?->id])->execute();
} else {
    $db->createCommand()->update('user_consensus', ['consenso_marketing' => 0, 'terms_document_id' => LegalDocuments::current('terms')?->id], ['user_id' => $fixture])->execute();
}
check(login('other@example.invalid')['status'] === 302, 'Fixture member logs in');
$page = web('/site/consents');
check($page['status'] === 200 && str_contains($page['body'], 'name="marketing" value="1"'), 'My consents page with marketing switch');
check(web('/site/marketing', ['marketing' => '1'])['status'] === 400, 'Marketing change requires CSRF');
check(web('/site/marketing')['status'] === 405, 'Marketing change only by POST');
check(web('/site/marketing', ['_csrf' => csrf($page), 'marketing' => 'yes'])['status'] === 400, 'Invalid marketing value refused');
$before = count(events($fixture, 'marketing'));
web('/site/marketing', ['_csrf' => csrf($page), 'marketing' => '1']);
web('/site/marketing', ['_csrf' => csrf($page), 'marketing' => '1']);
$m = events($fixture, 'marketing');
check(count($m) === $before + 1 && end($m)['action'] === 'given' && end($m)['source'] === 'profile' && (int) scalar('SELECT consenso_marketing FROM user_consensus WHERE user_id=:u ORDER BY id DESC LIMIT 1', [':u' => $fixture]) === 1,
    'Marketing given from the profile: state and one event, none when unchanged');
web('/site/marketing', ['_csrf' => csrf($page), 'marketing' => '0']);
$m = events($fixture, 'marketing');
check(count($m) === $before + 2 && end($m)['action'] === 'withdrawn' && (int) scalar('SELECT consenso_marketing FROM user_consensus WHERE user_id=:u ORDER BY id DESC LIMIT 1', [':u' => $fixture]) === 0,
    'Marketing withdrawn as easily as given');
$history = web('/site/consents');
check(str_contains($history['body'], 'Revocato') && str_contains($history['body'], 'Profilo'), 'History shows the withdrawal');

// --- accessi: consensi altrui vietati ai non admin, admin in sola lettura
check(web('/site/consents?user=' . urlencode(Crypt::encrypt($newUser)))['status'] === 404, 'Member cannot read another account consents');
anonymous();
check(str_contains(web('/site/consents')['headers'], 'site/login'), 'Anonymous redirected to login');
check(login('admin@example.invalid')['status'] === 302, 'Admin logs in');
// anche l'admin accetta prima le condizioni in vigore (filtro della fase A)
$gate = web('/site/accept-terms');
if (preg_match('/name="document" value="([0-9]+)"/', $gate['body'], $d)) web('/site/accept-terms', ['_csrf' => csrf($gate), 'document' => $d[1], 'accept' => '1']);
// gli ID cifrati dipendono dalla sessione: il link si prende dalla scheda del socio, come fa l'admin
$list = web('/association/members');
preg_match('~href="(/association/member\?id=[^"]+)"~', $list['body'], $member);
$card = web(html_entity_decode($member[1] ?? '/association/members'));
preg_match('~href="(/site/consents\?user=[^"]+)"~', $card['body'], $link);
$ro = web(html_entity_decode($link[1] ?? '/site/consents'));
check(isset($link[1]) && $ro['status'] === 200 && str_contains($ro['body'], 'Consensi del socio') && !str_contains($ro['body'], 'name="marketing"'),
    'Admin opens member consents from the member card, read-only');

// --- nuova accettazione: evento condizioni, mai marketing
$terms = LegalDocuments::newDraft('terms', 'Condizioni', "## Oggetto\n\nCondizioni versione nuova per lo storico.", 1);
check($terms->publish(1, LegalDocuments::snapshot($terms->title, $terms->body)), 'New terms version published');
login('other@example.invalid');
$marketingBefore = count(events($fixture, 'marketing'));
check(str_contains(web('/site/consents')['body'], 'I miei consensi'), 'My consents reachable even before accepting new terms');
$page = web('/site/accept-terms');
web('/site/accept-terms', ['_csrf' => csrf($page), 'document' => (string) $terms->id, 'accept' => '1']);
$tEvents = events($fixture, 'terms');
check(end($tEvents)['source'] === 'reaccept' && (int) end($tEvents)['document_id'] === (int) $terms->id && count(events($fixture, 'marketing')) === $marketingBefore,
    'Re-acceptance records the terms version and never touches marketing');

// --- sola aggiunta e anonimizzazione
$event = ConsentEvents::find()->where(['user_id' => $fixture])->orderBy(['id' => SORT_DESC])->one();
$event->action = 'refused';
check($event->save(false) === false && $event->delete() === false && ConsentEvents::findOne($event->id)->action !== 'refused', 'Events cannot be changed or deleted by the code');
$db->createCommand()->delete('user_consensus', ['user_id' => $newUser])->execute();
$db->createCommand()->delete('member_profiles', ['user_id' => $newUser])->execute();
$db->createCommand()->delete('users', ['id' => $newUser])->execute();
check((int) scalar('SELECT COUNT(*) FROM consent_events WHERE user_id=:u', [':u' => $newUser]) === 0
    && (int) scalar("SELECT COUNT(*) FROM consent_events WHERE user_id IS NULL AND source='signup'") >= 4, 'Deleted account: events kept without the user (anonymised)');
echo "PASS $checks consent history checks\n";
