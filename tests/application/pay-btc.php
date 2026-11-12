<?php
// TIP-020 passo 5c: quota in Bitcoin con BTCPay Greenfield e webhook firmato, contro il BTCPay finto.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime' || !str_starts_with((string) env('BTCPAY_URL'), 'http://127.0.0.1:')) exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
$app = new yii\web\Application(require $root . '/config/web.php');
$db = $app->db;
use app\components\payments\BTCPay;
$mock = '/tmp/btcpay-mock';
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
    return $n->item(0)->nodeValue;
}
function login($role) {
    global $jar;
    $jar = tempnam('/tmp', 'napay-btc-');
    $page = request('/site/login');
    return request('/site/login', ['_csrf' => csrf($page), 'LoginForm' => ['email' => $role . '@example.invalid', 'password' => 'fixture-password-only']]);
}
function post($path, array $fields = []) { $page = request('/pay-fee/index'); return request($path, array_merge(['_csrf' => csrf($page)], $fields)); }
function payment($role) { global $db; return $db->createCommand('SELECT * FROM membership_payments WHERE user_id=(SELECT id FROM users WHERE username=:u) ORDER BY id DESC', [':u' => 'runtime-' . $role])->queryOne(); }
function mode($m) { global $mock; @mkdir($mock, 0700, true); file_put_contents("$mock/mode", $m); }
function invoice($id, ?array $set = null) {
    global $mock;
    $f = "$mock/invoice-$id.json";
    $data = json_decode((string) file_get_contents($f), true);
    if ($set !== null) file_put_contents($f, json_encode(array_merge($data, $set)));
    return $data;
}
function hook(array $event, ?string $signature = 'auto', string $type = 'application/json', string $method = 'POST') {
    $body = json_encode($event);
    $headers = ['Content-Type: ' . $type];
    if ($signature !== null) $headers[] = 'BTCPay-Sig: ' . ($signature === 'auto' ? 'sha256=' . hash_hmac('sha256', $body, (string) getenv('BTCPAY_WEBHOOK_SECRET')) : $signature);
    $curl = curl_init('http://127.0.0.1/api/btcpay/v1/webhook');
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30, CURLOPT_PROXY => '',
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body]);
    $raw = curl_exec($curl);
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $r = ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    curl_close($curl);
    $r['result'] = json_decode($r['body'], true)['result'] ?? null;
    return $r;
}
function event(string $type, ?string $invoiceId, array $extra = []): array {
    return array_merge(['deliveryId' => bin2hex(random_bytes(10)), 'webhookId' => 'whk1', 'originalDeliveryId' => null, 'isRedelivery' => false,
        'type' => $type, 'timestamp' => time(), 'storeId' => getenv('BTCPAY_STORE_ID'), 'invoiceId' => $invoiceId], $extra);
}
$year = (int) date('Y');

$db->createCommand()->insert('membership_fees', ['description' => 'Quota associativa annuale', 'duration' => 1, 'amount' => '20.00'])->execute();
$db->createCommand()->insert('payment_methods', ['description' => 'Bitcoin', 'min_level' => 0])->execute(); $btcId = (int) $db->getLastInsertID();
mode('');
$app->cache->flush();

try {
    // avvio dal sito: fattura con importo e riferimento del server, redirect solo al checkout BTCPay
    login('user');
    check(str_contains(request('/pay-fee/index')['body'], 'Paga con Bitcoin'), 'Bitcoin button available');
    $go = post('/pay-fee/start', ['method' => 'bitcoin', 'amount' => '0.01']);
    $p = payment('user');
    check($go['status'] === 302 && BTCPay::isCheckoutUrl((string) $go['location']) && str_ends_with($go['location'], '/i/' . $p['btcpay_invoice_id']), 'Redirect only to the BTCPay checkout');
    check($p['status'] === 'pending' && $p['amount'] === '20.00' && (int) $p['payment_method_id'] === $btcId, 'Pending Bitcoin payment with server amount');
    $inv = invoice($p['btcpay_invoice_id']);
    check($inv['amount'] === '20.00' && $inv['currency'] === 'EUR' && $inv['metadata']['orderId'] === 'napay-fee-' . $p['id']
        && $inv['checkout']['redirectURL'] === 'https://napay.example.invalid/pay-fee/btcpay-return', 'Invoice: EUR 20.00, order reference, redirect from WEBAPP_LINK');
    check(request('/pay-fee/btcpay-return')['status'] === 302 && str_contains(request('/pay-fee/index')['body'], 'in elaborazione'), 'Return page: processing message, nothing changes');

    // "Aggiorna stato": solo POST, solo il proprio pagamento
    check(request('/pay-fee/btcpay-refresh')['status'] === 405 && request('/pay-fee/btcpay-refresh', [])['status'] === 400, 'Refresh only by POST with CSRF');
    post('/pay-fee/btcpay-refresh');
    check(payment('user')['status'] === 'pending', 'New invoice: still pending');
    invoice($p['btcpay_invoice_id'], ['status' => 'Processing']);
    post('/pay-fee/btcpay-refresh');
    check(payment('user')['status'] === 'pending' && str_contains(request('/pay-fee/index')['body'], 'attesa di conferma'), 'Processing (unconfirmed): still pending');
    invoice($p['btcpay_invoice_id'], ['status' => 'Settled']);
    login('operator');
    post('/pay-fee/btcpay-refresh');
    check(payment('user')['status'] === 'pending', 'Another member cannot refresh this payment');
    login('user');
    post('/pay-fee/btcpay-refresh');
    $paid = payment('user');
    check($paid['status'] === 'paid' && (int) $paid['year'] === $year && $paid['receipt_number'] !== null, 'Settled invoice: paid with receipt');

    // webhook firmato
    login('admin');
    post('/pay-fee/start', ['method' => 'bitcoin']);
    $p2 = payment('admin');
    $settled = event('InvoiceSettled', $p2['btcpay_invoice_id']);
    check(hook($settled, null)['status'] === 401 && hook($settled, 'sha256=' . str_repeat('0', 64))['status'] === 401, 'Missing or forged signature: 401');
    check(hook($settled, 'auto', 'text/plain')['status'] === 400 && hook($settled, 'auto', 'application/json', 'GET')['status'] === 405, 'Content-Type and method enforced');
    check(hook($settled)['result'] === 'processing' && payment('admin')['status'] === 'pending', 'Event payload not trusted: invoice still New on BTCPay');
    invoice($p2['btcpay_invoice_id'], ['status' => 'Settled']);
    $settled2 = event('InvoiceSettled', $p2['btcpay_invoice_id']);
    $r = hook($settled2);
    check($r['status'] === 200 && $r['result'] === 'paid' && payment('admin')['status'] === 'paid', 'Signed settled event: paid');
    check(hook($settled2)['result'] === 'duplicate' && hook(event('InvoiceSettled', $p2['btcpay_invoice_id'], ['deliveryId' => 'redelivery0001', 'originalDeliveryId' => $settled2['deliveryId'], 'isRedelivery' => true]))['result'] === 'duplicate', 'Replay and redelivery processed once');
    check(!preg_match('/^set-cookie:/mi', $r['headers']) && stripos($r['headers'], 'cache-control: no-store') !== false, 'No cookies, no-store');
    check(hook(event('InvoiceSettled', $p2['btcpay_invoice_id'], ['storeId' => 'OtherStore1234567890ABCD']))['result'] === 'other_store', 'Other store ignored');
    check(hook(event('InvoiceSettled', 'UnknownInvoice0001'))['result'] === 'not_found' && hook(event('PayoutCreated', null))['result'] === 'ignored', 'Unknown invoice and other events: 200');

    // scadenza, importo diverso, BTCPay non raggiungibile
    login('operator');
    post('/pay-fee/start', ['method' => 'bitcoin']);
    $p3 = payment('operator');
    invoice($p3['btcpay_invoice_id'], ['status' => 'Expired']);
    check(hook(event('InvoiceExpired', $p3['btcpay_invoice_id']))['result'] === 'expired' && payment('operator')['status'] === 'invalid', 'Expired invoice: invalid');
    post('/pay-fee/start', ['method' => 'bitcoin']);
    $p4 = payment('operator');
    invoice($p4['btcpay_invoice_id'], ['status' => 'Settled']);
    mode('mismatch');
    check(hook(event('InvoiceSettled', $p4['btcpay_invoice_id']))['result'] === 'invalid' && payment('operator')['status'] === 'invalid' && payment('operator')['receipt_number'] === null, 'Amount mismatch on BTCPay: invalid, no receipt');
    check((int) $db->createCommand("SELECT COUNT(*) FROM logs WHERE event_type='security' AND description LIKE 'Fattura BTCPay non corrispondente%'")->queryScalar() === 1, 'Mismatch in security log');
    mode('');
    post('/pay-fee/start', ['method' => 'bitcoin']);
    $p5 = payment('operator');
    invoice($p5['btcpay_invoice_id'], ['status' => 'Settled']);
    $retry = event('InvoiceSettled', $p5['btcpay_invoice_id']);
    mode('fail-get');
    check(hook($retry)['status'] === 500 && payment('operator')['status'] === 'pending', 'BTCPay unavailable: 500, BTCPay retries');
    mode('');
    check(hook($retry)['result'] === 'paid', 'Retry succeeds');

    // avvio fallito o link di pagamento estraneo
    login('merchant');
    mode('evil-link');
    $evil = post('/pay-fee/start', ['method' => 'bitcoin']);
    check(!str_contains((string) $evil['location'], 'evil.example') && payment('merchant')['status'] === 'invalid', 'Checkout link on a foreign host refused');
    mode('fail-create');
    post('/pay-fee/start', ['method' => 'bitcoin']);
    check(payment('merchant')['status'] === 'invalid' && str_contains(request('/pay-fee/index')['body'], 'non è raggiungibile'), 'BTCPay unavailable at start: invalid, member informed');
    mode('');

    // configurazione
    foreach (['http://btcpay.example.org' => 'plain HTTP', 'https://user@btcpay.example.org' => 'credentials in URL'] as $url => $case) {
        putenv("BTCPAY_URL=$url");
        check(!BTCPay::enabled(), "BTCPay URL refused: $case");
    }
    putenv('BTCPAY_URL=https://btcpay.example.org');
    check(BTCPay::enabled() && !BTCPay::isCheckoutUrl('https://btcpay.example.org.evil.example/i/x') && BTCPay::isCheckoutUrl('https://btcpay.example.org/i/x'), 'Checkout host must match BTCPAY_URL');
    putenv('BTCPAY_URL=http://127.0.0.1:8098'); putenv('NAPAY_TEST_DB_CONFIRM=');
    check(!BTCPay::enabled(), 'Local mock refused outside the isolated test suite');
    putenv('NAPAY_TEST_DB_CONFIRM=isolated-fixture'); $secret = (string) getenv('BTCPAY_WEBHOOK_SECRET'); putenv('BTCPAY_WEBHOOK_SECRET=short');
    check(!BTCPay::enabled(), 'Short webhook secret refused');
    putenv("BTCPAY_WEBHOOK_SECRET=$secret");

    // proxy di uscita: valore non valido = nessuna connessione
    putenv('PAYMENT_PROXY=http://evil host:3128');
    $blocked = false;
    try { BTCPay::getInvoice('ABCDEFGH12345678'); } catch (app\components\payments\PaymentGatewayException $e) { $blocked = str_contains($e->getMessage(), 'PAYMENT_PROXY'); }
    check($blocked && app\components\payments\Egress::proxy() === false, 'Invalid PAYMENT_PROXY blocks gateway calls');
    putenv('PAYMENT_PROXY=http://egress:3128');
    check(app\components\payments\Egress::proxy() === 'http://egress:3128', 'Valid PAYMENT_PROXY accepted');
    putenv('PAYMENT_PROXY');
    check(app\components\payments\Egress::proxy() === null, 'No PAYMENT_PROXY: direct connection');

    // nessun segreto nei registri
    $all = json_encode($db->createCommand('SELECT description FROM logs')->queryAll());
    foreach (glob($root . '/runtime/logs/*.log') ?: [] as $file) $all .= file_get_contents($file);
    check(!str_contains($all, (string) getenv('BTCPAY_API_KEY')) && !str_contains($all, $secret), 'No BTCPay API key or webhook secret in logs');
} finally {
    mode('');
    foreach (['btcpay_webhook_events', 'membership_payments', 'payment_methods', 'membership_fees'] as $table) $db->createCommand()->delete($table)->execute();
}
echo "PASS {$checks} Bitcoin (BTCPay) fee checks\n";
