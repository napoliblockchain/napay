<?php
// TIP-020 passo 5b: pagamento quota con PayPal Orders v2 contro il server PayPal finto (tests/application/paypal-mock.php).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime' || env('PAYPAL_MODE') !== 'mock') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
$app = new yii\web\Application(require $root . '/config/web.php');
$db = $app->db;
use app\components\payments\PayPal;
$mock = '/tmp/paypal-mock';
$checks = 0; $jar = null;
function check($condition, $label) {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks; echo 'PASS: ' . $label . "\n";
}
function request($path, ?array $form = null) {
    global $jar;
    $curl = curl_init('http://127.0.0.1' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($form !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form)]);
    $raw = curl_exec($curl);
    if ($raw === false) throw new RuntimeException('HTTP transport failed.');
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $r = ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    curl_close($curl);
    $r['location'] = preg_match('/^location:\s*(\S+)/mi', $r['headers'], $m) ? $m[1] : null;
    return $r;
}
function csrf($page) {
    if ($page['body'] === '') throw new RuntimeException('Empty page: HTTP ' . $page['status'] . ' -> ' . $page['location']);
    $d = new DOMDocument(); @$d->loadHTML($page['body'], LIBXML_NONET);
    $n = (new DOMXPath($d))->query('//meta[@name="csrf-token"]/@content');
    if ($n->length !== 1) throw new RuntimeException('CSRF unavailable.');
    return $n->item(0)->nodeValue;
}
function login($role) {
    global $jar;
    $jar = tempnam('/tmp', 'napay-payfee-');
    $page = request('/site/login');
    return request('/site/login', ['_csrf' => csrf($page), 'LoginForm' => ['email' => $role . '@example.invalid', 'password' => 'fixture-password-only']]);
}
function start(array $extra = []) {
    $page = request('/pay-fee/index');
    return request('/pay-fee/start', array_merge(['_csrf' => csrf($page), 'method' => 'paypal'], $extra));
}
function capture($order) {
    $page = request('/pay-fee/index');
    return request('/pay-fee/paypal-capture', ['_csrf' => csrf($page), 'token' => $order]);
}
function payment($role) { global $db; return $db->createCommand('SELECT * FROM membership_payments WHERE user_id=(SELECT id FROM users WHERE username=:u) ORDER BY id DESC', [':u' => 'runtime-' . $role])->queryOne(); }
function mode($m) { global $mock; @mkdir($mock, 0700, true); file_put_contents("$mock/mode", $m); }
function orderFile($id) { global $mock; return json_decode((string) @file_get_contents("$mock/order-$id.json"), true); }
function requests() { global $mock; return array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string) @file_get_contents("$mock/requests.log")))); }
$year = (int) date('Y');

// fixture: quota annuale, PayPal online, importo dalle impostazioni importate
$db->createCommand()->insert('membership_fees', ['description' => 'Quota associativa annuale', 'duration' => 1, 'amount' => '20.00'])->execute();
$db->createCommand()->insert('payment_methods', ['description' => 'Paypal', 'min_level' => 0])->execute(); $paypalId = (int) $db->getLastInsertID();
$db->createCommand()->insert('settings', ['description' => 'fixture', 'code' => 'quota_iscrizione_socio', 'value' => '25,50'])->execute();
$db->createCommand()->insert('settings', ['description' => 'fixture', 'code' => 'quota_iscrizione_socioGiuridico', 'value' => '50'])->execute();
$db->createCommand()->insert('member_profiles', ['user_id' => (int) $db->createCommand("SELECT id FROM users WHERE username='runtime-merchant'")->queryScalar(), 'is_corporate' => 1, 'denomination' => 'Ditta'])->execute();
// These are explicit PF members; the fixture named operator has legacy level40, not a POS profile.
foreach (['user', 'operator'] as $memberRole) {
    $db->createCommand()->insert('member_profiles', ['user_id'=>(int)$db->createCommand('SELECT id FROM users WHERE username=:u', [':u'=>'runtime-'.$memberRole])->queryScalar(), 'is_corporate'=>0])->execute();
}
mode('');

try {
    // link esterno: /quota -> login -> di nuovo alla pagina Paga quota
    $jar = tempnam('/tmp', 'napay-payfee-');
    $guest = request('/quota');
    check($guest['status'] === 302 && str_contains((string) $guest['location'], '/site/login'), 'External link /quota requires login');
    $page = request('/site/login');
    $back = request('/site/login', ['_csrf' => csrf($page), 'LoginForm' => ['email' => 'user@example.invalid', 'password' => 'fixture-password-only']]);
    check($back['status'] === 302 && parse_url((string) $back['location'], PHP_URL_PATH) === '/quota', 'After login back to /quota');
    $index = request('/quota');
    check($index['status'] === 200 && str_contains(html_entity_decode($index['body']), '25,50') && str_contains($index['body'], "01/01/$year - 31/12/$year"), 'Amount from settings (persona fisica), calendar-year period');

    // avvio: CSRF, metodo, importo mai dal client
    check(request('/pay-fee/start', ['method' => 'paypal'])['status'] === 400, 'Start requires CSRF');
    check(request('/pay-fee/start')['status'] === 405 && request('/pay-fee/start?method=paypal')['status'] === 405, 'Start only as POST');
    $before = (int) $db->createCommand('SELECT COUNT(*) FROM membership_payments')->queryScalar();
    $bad = start(['method' => 'bitcoin']);
    check($bad['status'] === 302 && (int) $db->createCommand('SELECT COUNT(*) FROM membership_payments')->queryScalar() === $before, 'Unknown method creates nothing');
    $go = start(['amount' => '0.01', 'fee_id' => 999, 'starts_on' => '2000-01-01']);
    $p = payment('user');
    check($go['status'] === 302 && PayPal::isApproveUrl((string) $go['location']) && str_contains($go['location'], 'token=' . $p['paypal_order_id']), 'Redirect only to PayPal approval page');
    check($p['status'] === 'pending' && $p['amount'] === '25.50' && $p['starts_on'] === "$year-01-01" && (int) $p['payment_method_id'] === $paypalId && $p['receipt_number'] === null, 'Pending payment with server amount and period; client values ignored');
    $order = orderFile($p['paypal_order_id']);
    check($order['intent'] === 'CAPTURE' && $order['unit']['amount'] === ['currency_code' => 'EUR', 'value' => '25.50'] && $order['unit']['custom_id'] === (string) $p['id'], 'PayPal order: CAPTURE, EUR 25.50, reference = payment id');
    check(str_starts_with($order['context']['return_url'], 'https://napay.example.invalid/pay-fee/paypal-return') && str_starts_with($order['context']['cancel_url'], 'https://napay.example.invalid/'), 'Return/cancel URLs from WEBAPP_LINK, not from request Host');
    $log = requests();
    check($log[0]['path'] === '/v1/oauth2/token' && $log[0]['auth'] === 'Basic' && $log[1]['request_id'] === 'napay-order-' . $p['id'] && $log[1]['auth'] === 'Bearer', 'OAuth client credentials, then order with idempotency key');

    // ritorno da PayPal: solo il titolare, nessun addebito in GET
    $order1 = $p['paypal_order_id'];
    login('operator');
    $foreign = request('/pay-fee/paypal-return?token=' . $order1 . '&PayerID=X');
    check($foreign['status'] === 302 && orderFile($order1)['captures'] === 0, 'Another member cannot open the review');
    check(capture($order1)['status'] === 302 && payment('user')['status'] === 'pending' && orderFile($order1)['captures'] === 0, 'Another member cannot capture');
    $back = login('user');
    $review = request('/pay-fee/paypal-return?token=' . $order1 . '&PayerID=X');
    check($review['status'] === 200 && str_contains($review['body'], 'paypal-capture') && orderFile($order1)['captures'] === 0, 'Review page, nothing captured on GET');
    check(request('/pay-fee/paypal-capture', ['token' => $order1])['status'] === 400, 'Capture requires CSRF');
    check(request('/pay-fee/paypal-return?token=../../etc')['status'] === 302, 'Malformed order token rejected');

    // conferma: capture verificata, ricevuta assegnata, una volta sola
    $done = capture($order1);
    $paid = payment('user');
    check($done['status'] === 302 && $paid['status'] === 'paid' && $paid['paypal_txn_id'] === 'CAP' . $order1 && (int) $paid['year'] === $year && (int) $paid['receipt_number'] >= 1, 'Captured: paid, capture id and receipt number');
    $captureCalls = array_values(array_filter(requests(), fn($r) => str_ends_with($r['path'], '/capture')));
    check(count($captureCalls) === 1 && $captureCalls[0]['request_id'] === 'napay-capture-' . $paid['id'], 'One capture call with idempotency key');
    check(capture($order1)['status'] === 302 && orderFile($order1)['captures'] === 1 && payment('user')['receipt_number'] === $paid['receipt_number'], 'Second confirmation: no new capture, same receipt');

    // rinnovo: anno successivo; poi niente oltre un anno di anticipo
    $index = request('/pay-fee/index');
    check(str_contains($index['body'], '01/01/' . ($year + 1)) && str_contains($index['body'], "31/12/$year"), 'Renewal offered for next year');
    $go = start();
    $next = payment('user');
    capture($next['paypal_order_id']);
    check(payment('user')['expires_on'] === ($year + 1) . '-12-31' && payment('user')['status'] === 'paid', 'Next year paid');
    $count = (int) $db->createCommand('SELECT COUNT(*) FROM membership_payments')->queryScalar();
    start();
    check((int) $db->createCommand('SELECT COUNT(*) FROM membership_payments')->queryScalar() === $count && str_contains(request('/pay-fee/index')['body'], 'già pagata'), 'No payment more than a year ahead');

    // persona giuridica: importo dedicato
    login('merchant');
    check(str_contains(html_entity_decode(request('/pay-fee/index')['body']), '50,00'), 'Company member pays the corporate amount');

    // tentativo precedente non confermato decade; scenari di errore
    login('operator');
    start(); $first = payment('operator');
    start(); $second = payment('operator');
    check($db->createCommand('SELECT status FROM membership_payments WHERE id=:i', [':i' => $first['id']])->queryScalar() === 'invalid' && $second['status'] === 'pending', 'Older unconfirmed PayPal attempt invalidated');
    mode('capture-declined');
    capture($second['paypal_order_id']);
    check(payment('operator')['status'] === 'pending' && str_contains(request('/pay-fee/index')['body'], 'non è stato completato'), 'Declined capture: still pending, member informed');
    mode('capture-mismatch');
    capture($second['paypal_order_id']);
    check(payment('operator')['status'] === 'invalid' && payment('operator')['receipt_number'] === null, 'Capture amount mismatch: invalid, no receipt');
    check((int) $db->createCommand("SELECT COUNT(*) FROM logs WHERE event_type='security' AND description LIKE 'Capture PayPal non corrispondente%'")->queryScalar() === 1, 'Mismatch logged as security event');
    mode('evil-approve');
    $evil = start();
    check(!str_contains((string) $evil['location'], 'evil.example') && payment('operator')['status'] === 'invalid', 'Approval link to a foreign host refused');
    mode('fail-create');
    $fail = start();
    check($fail['status'] === 302 && payment('operator')['status'] === 'invalid' && str_contains(request('/pay-fee/index')['body'], 'non è raggiungibile'), 'PayPal unavailable: attempt invalid, member informed');
    mode('');
    check(request('/pay-fee/paypal-cancel?token=X')['status'] === 302 && str_contains(request('/pay-fee/index')['body'], 'annullato'), 'Cancel page changes nothing');

    // configurazione e URL di approvazione
    foreach (['http://www.paypal.com/x', 'https://evil.example/x', 'https://www.paypal.com.evil.example/x', 'https://u@www.paypal.com/x', 'https://www.paypal.com:8443/x'] as $url) {
        check(!PayPal::isApproveUrl($url), 'Approve URL rejected: ' . $url);
    }
    check(PayPal::isApproveUrl('https://www.paypal.com/checkoutnow?token=AB') && PayPal::isApproveUrl('https://www.sandbox.paypal.com/checkoutnow?token=AB'), 'PayPal approve URLs accepted');
    putenv('NAPAY_TEST_DB_CONFIRM=');
    check(!PayPal::enabled(), 'Mock mode refused outside the isolated test suite');
    putenv('NAPAY_TEST_DB_CONFIRM=isolated-fixture'); putenv('PAYPAL_MOCK_BASE=http://10.0.0.1:8099');
    check(!PayPal::enabled(), 'Mock base only on 127.0.0.1');
    putenv('PAYPAL_MOCK_BASE=http://127.0.0.1:8099'); putenv('PAYPAL_MODE=whatever');
    check(!PayPal::enabled(), 'Unknown PayPal mode disables PayPal');
    putenv('PAYPAL_MODE=mock');

    // nessun segreto nei registri
    $register = json_encode($db->createCommand('SELECT description FROM logs')->queryAll());
    $files = '';
    foreach (glob($root . '/runtime/logs/*.log') ?: [] as $file) $files .= file_get_contents($file);
    $token = trim((string) @file_get_contents("$mock/token"));
    $secret = (string) getenv('PAYPAL_CLIENT_SECRET');
    check(!str_contains($register . $files, $secret) && ($token === '' || !str_contains($register . $files, $token)), 'No PayPal secret or access token in logs');
} finally {
    mode('');
    $db->createCommand()->delete('settings', ['description' => 'fixture'])->execute();
    foreach (['membership_payments', 'member_profiles', 'payment_methods', 'membership_fees'] as $table) $db->createCommand()->delete($table)->execute();
}
echo "PASS {$checks} pay fee checks\n";
