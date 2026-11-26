<?php
// TIP-020 passo 5b-bis: webhook PayPal (equivalente del vecchio IPN) contro il server PayPal finto.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime' || env('PAYPAL_MODE') !== 'mock') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
$app = new yii\web\Application(require $root . '/config/web.php');
$db = $app->db;
$quoteMemberId = (int)$db->createCommand("SELECT id FROM users WHERE username='runtime-user'")->queryScalar();
$quoteProfileCreated = !$db->createCommand('SELECT COUNT(*) FROM member_profiles WHERE user_id=:u', [':u'=>$quoteMemberId])->queryScalar();
if ($quoteProfileCreated) $db->createCommand()->insert('member_profiles', ['user_id'=>$quoteMemberId, 'is_corporate'=>0])->execute();
$mock = '/tmp/paypal-mock';
$checks = 0;
function check($condition, $label) {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks; echo 'PASS: ' . $label . "\n";
}
function headers(string $sig = 'valid-signature', array $override = []): array {
    $h = ['PAYPAL-AUTH-ALGO' => 'SHA256withRSA', 'PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-360caa42',
        'PAYPAL-TRANSMISSION-ID' => bin2hex(random_bytes(8)), 'PAYPAL-TRANSMISSION-SIG' => $sig, 'PAYPAL-TRANSMISSION-TIME' => gmdate('Y-m-d\TH:i:s\Z')];
    foreach ($override as $k => $v) { if ($v === null) unset($h[$k]); else $h[$k] = $v; }
    return array_map(fn($k, $v) => "$k: $v", array_keys($h), $h);
}
function hook($event, ?array $headers = null, string $type = 'application/json', string $method = 'POST') {
    $curl = curl_init('http://127.0.0.1/api/paypal/v1/webhook');
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30, CURLOPT_PROXY => '',
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => array_merge($headers ?? headers(), ['Content-Type: ' . $type])]);
    if ($event !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, is_string($event) ? $event : json_encode($event, JSON_UNESCAPED_SLASHES));
    $raw = curl_exec($curl);
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $r = ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    curl_close($curl);
    $r['result'] = json_decode($r['body'], true)['result'] ?? null;
    return $r;
}
function event(string $type, array $resource, ?string $id = null): array {
    return ['id' => $id ?? 'WH-' . strtoupper(bin2hex(random_bytes(10))), 'event_type' => $type, 'resource_type' => 'checkout-order', 'resource' => $resource];
}
function mode($m) { global $mock; @mkdir($mock, 0700, true); file_put_contents("$mock/mode", $m); }
function order(int $paymentId, string $amount, array $extra = []): string {
    global $mock;
    $id = strtoupper(substr(bin2hex(random_bytes(9)), 0, 17));
    file_put_contents("$mock/order-$id.json", json_encode(['unit' => ['custom_id' => (string) $paymentId, 'amount' => ['currency_code' => 'EUR', 'value' => $amount]], 'captures' => 0] + $extra));
    return $id;
}
function pending(string $role): array {
    global $db, $fee, $paypal;
    $user = (int) $db->createCommand('SELECT id FROM users WHERE username=:u', [':u' => 'runtime-' . $role])->queryScalar();
    $db->createCommand()->insert('membership_payments', ['user_id' => $user, 'fee_id' => $fee, 'payment_method_id' => $paypal, 'amount' => '20.00',
        'registered_on' => date('Y-m-d'), 'starts_on' => date('Y') . '-01-01', 'expires_on' => date('Y') . '-12-31', 'status' => 'pending'])->execute();
    $id = (int) $db->getLastInsertID();
    $order = order($id, '20.00');
    $db->createCommand()->update('membership_payments', ['paypal_order_id' => $order], ['id' => $id])->execute();
    return ['id' => $id, 'order' => $order];
}
function row($id) { global $db; return $db->createCommand('SELECT * FROM membership_payments WHERE id=:i', [':i' => $id])->queryOne(); }
function captures($order) { global $mock; return json_decode((string) file_get_contents("$mock/order-$order.json"), true)['captures']; }
function verifyCalls() { global $mock; return count(array_filter(explode("\n", (string) @file_get_contents("$mock/verify.log")))); }
function setOrder($order, array $fields) { global $mock; $f = "$mock/order-$order.json"; file_put_contents($f, json_encode(array_merge(json_decode(file_get_contents($f), true), $fields))); }

$db->createCommand()->insert('membership_fees', ['description' => 'Quota associativa annuale', 'duration' => 1, 'amount' => '20.00'])->execute(); $fee = (int) $db->getLastInsertID();
$db->createCommand()->insert('payment_methods', ['description' => 'Paypal', 'min_level' => 0])->execute(); $paypal = (int) $db->getLastInsertID();
mode('');
$app->cache->flush();

try {
    $p1 = pending('user');
    $approved = event('CHECKOUT.ORDER.APPROVED', ['id' => $p1['order'], 'status' => 'APPROVED']);

    // richieste non valide: rifiutate prima di chiamare PayPal
    $calls = verifyCalls();
    check(hook(null, null, 'application/json', 'GET')['status'] === 405, 'Only POST');
    check(hook($approved, headers('valid-signature', ['PAYPAL-TRANSMISSION-SIG' => null]))['status'] === 400, 'Missing transmission header rejected');
    check(hook($approved, headers('valid-signature', ['PAYPAL-CERT-URL' => 'https://evil.example/cert.pem']))['status'] === 400, 'Certificate URL outside PayPal rejected');
    check(hook($approved, headers('valid-signature', ['PAYPAL-CERT-URL' => 'http://api.paypal.com/cert']))['status'] === 400, 'Non-HTTPS certificate URL rejected');
    check(hook($approved, null, 'text/plain')['status'] === 400, 'Content-Type application/json required');
    check(hook('{not json')['status'] === 400 && hook(['id' => 'x', 'event_type' => 'A.B'])['status'] === 400, 'Malformed event rejected');
    check(hook(str_repeat(' ', 70000) . json_encode($approved))['status'] === 400, 'Oversized body rejected');
    check(verifyCalls() === $calls, 'No PayPal call for malformed requests');

    // firma non valida
    $bad = hook($approved, headers('forged-signature'));
    check($bad['status'] === 401 && row($p1['id'])['status'] === 'pending' && captures($p1['order']) === 0, 'Forged signature: 401, nothing changes');
    check((int) $db->createCommand('SELECT COUNT(*) FROM paypal_webhook_events WHERE event_id=:e', [':e' => $approved['id']])->queryScalar() === 0, 'Forged event not recorded');
    check((int) $db->createCommand("SELECT COUNT(*) FROM logs WHERE event_type='security' AND description LIKE 'Webhook PayPal con firma non valida%'")->queryScalar() >= 1, 'Forged signature in security log');
    $verified = json_decode(trim(array_slice(explode("\n", trim(file_get_contents("$mock/verify.log"))), -1)[0]), true);
    check($verified['webhook_event'] === $approved && $verified['webhook_id'] === getenv('PAYPAL_WEBHOOK_ID'), 'Event forwarded unchanged with configured webhook id');

    // ordine approvato: capture automatica, una sola volta
    $ok = hook($approved);
    $paid = row($p1['id']);
    check($ok['status'] === 200 && $ok['result'] === 'paid' && $paid['status'] === 'paid' && $paid['paypal_txn_id'] === 'CAP' . $p1['order'] && $paid['receipt_number'] !== null, 'Approved order captured automatically: paid with receipt');
    check(captures($p1['order']) === 1, 'Exactly one capture');
    check(hook($approved)['result'] === 'duplicate' && captures($p1['order']) === 1, 'Replayed event ignored');
    check(hook(event('CHECKOUT.ORDER.APPROVED', ['id' => $p1['order']]))['result'] === 'already' && captures($p1['order']) === 1, 'New event for a paid payment: no second capture');
    check(!preg_match('/^set-cookie:/mi', $ok['headers']) && stripos($ok['headers'], 'cache-control: no-store') !== false, 'No cookies, no-store');

    // capture completata altrove: stato riletto da PayPal, payload ignorato
    $p2 = pending('admin');
    setOrder($p2['order'], ['captures' => 1]);
    $completed = hook(event('PAYMENT.CAPTURE.COMPLETED', ['id' => 'CAP' . $p2['order'], 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '0.01'],
        'supplementary_data' => ['related_ids' => ['order_id' => $p2['order']]]]));
    check($completed['result'] === 'paid' && row($p2['id'])['status'] === 'paid' && row($p2['id'])['amount'] === '20.00', 'Capture completed: paid, amount from PayPal not from payload');
    $p3 = pending('merchant');
    setOrder($p3['order'], ['captures' => 1]);
    mode('capture-mismatch');
    $mismatch = hook(event('PAYMENT.CAPTURE.COMPLETED', ['id' => 'CAP' . $p3['order'], 'supplementary_data' => ['related_ids' => ['order_id' => $p3['order']]]]));
    mode('');
    check($mismatch['result'] === 'invalid' && row($p3['id'])['status'] === 'invalid' && row($p3['id'])['receipt_number'] === null, 'Amount mismatch at PayPal: invalid, no receipt');
    $p4 = pending('operator');
    setOrder($p4['order'], ['captures' => 1, 'capture_status' => 'DENIED']);
    check(hook(event('PAYMENT.CAPTURE.DENIED', ['id' => 'CAP' . $p4['order'], 'supplementary_data' => ['related_ids' => ['order_id' => $p4['order']]]]))['result'] === 'denied'
        && row($p4['id'])['status'] === 'invalid', 'Denied capture: invalid');

    // rimborsi e storni (verificati su PayPal)
    $refund = ['id' => 'REF' . $p1['order'], 'status' => 'COMPLETED', 'links' => [['rel' => 'up', 'href' => 'https://api.sandbox.paypal.com/v2/payments/captures/CAP' . $p1['order']]]];
    check(hook(event('PAYMENT.CAPTURE.REFUNDED', $refund))['result'] === 'partial_or_unchanged' && row($p1['id'])['status'] === 'paid', 'Refund event not confirmed by PayPal: status unchanged');
    setOrder($p1['order'], ['capture_status' => 'PARTIALLY_REFUNDED']);
    check(hook(event('PAYMENT.CAPTURE.REFUNDED', $refund))['result'] === 'partial_or_unchanged' && row($p1['id'])['status'] === 'paid', 'Partial refund: logged only');
    setOrder($p1['order'], ['capture_status' => 'REFUNDED']);
    check(hook(event('PAYMENT.CAPTURE.REFUNDED', $refund))['result'] === 'refunded' && row($p1['id'])['status'] === 'refunded' && row($p1['id'])['receipt_number'] !== null, 'Full refund: refunded, receipt kept');
    $user = app\models\Users::findOne(['username' => 'runtime-user']);
    check(app\components\association\MembershipFee::quote($user)['paid_until'] === null, 'Refunded fee no longer counts as paid');
    setOrder($p2['order'], ['capture_status' => 'REVERSED']);
    check(hook(event('PAYMENT.CAPTURE.REVERSED', ['id' => 'CAP' . $p2['order'], 'status' => 'REVERSED']))['result'] === 'reversed' && row($p2['id'])['status'] === 'reversed', 'Reversal: reversed');
    check((int) $db->createCommand("SELECT COUNT(*) FROM logs WHERE event_type='security' AND description LIKE 'Pagamento quota #% refunded da PayPal'")->queryScalar() === 1, 'Refund in security log');

    // eventi sconosciuti o senza pagamento: 200, PayPal non riprova
    check(hook(event('BILLING.SUBSCRIPTION.CREATED', ['id' => 'I-ABC']))['result'] === 'ignored', 'Unknown event type ignored');
    check(hook(event('CHECKOUT.ORDER.APPROVED', ['id' => 'NOSUCHORDER123']))['result'] === 'not_found', 'Unknown order: not_found');

    // PayPal non raggiungibile: 500 e nuovo tentativo riuscito
    $p5 = pending('user');
    $retry = event('CHECKOUT.ORDER.APPROVED', ['id' => $p5['order']]);
    mode('fail-verify');
    check(hook($retry)['status'] === 500 && (int) $db->createCommand('SELECT COUNT(*) FROM paypal_webhook_events WHERE event_id=:e', [':e' => $retry['id']])->queryScalar() === 0, 'Verification unavailable: 500, not recorded');
    mode('fail-create');
    $p6 = pending('admin');
    setOrder($p6['order'], ['captures' => 1]);
    $later = event('PAYMENT.CAPTURE.COMPLETED', ['id' => 'CAP' . $p6['order'], 'supplementary_data' => ['related_ids' => ['order_id' => $p6['order']]]]);
    mode('fail-get');
    check(hook($later)['status'] === 500 && $db->createCommand('SELECT processed_at FROM paypal_webhook_events WHERE event_id=:e', [':e' => $later['id']])->queryScalar() === null, 'Processing failure: 500, event kept unprocessed');
    mode('');
    check(hook($retry)['result'] === 'paid' && hook($later)['result'] === 'paid' && row($p6['id'])['status'] === 'paid', 'PayPal retries succeed');

    // rate limit per IP
    $app->cache->flush();
    for ($i = 0; $i < 120; $i++) hook('{');
    $limited = hook($approved);
    check($limited['status'] === 429 && stripos($limited['headers'], 'retry-after:') !== false, 'Rate limit: 120 requests per minute per IP');
    $app->cache->flush();

    // configurazione: senza PAYPAL_WEBHOOK_ID il webhook è spento
    putenv('PAYPAL_WEBHOOK_ID=');
    check(app\components\payments\PayPal::webhookId() === null, 'Webhook disabled without PAYPAL_WEBHOOK_ID');
    putenv('PAYPAL_WEBHOOK_ID=' . env('PAYPAL_WEBHOOK_ID'));

    // nessun segreto o firma nei registri
    $register = json_encode($db->createCommand('SELECT description FROM logs')->queryAll());
    $files = '';
    foreach (glob($root . '/runtime/logs/*.log') ?: [] as $file) $files .= file_get_contents($file);
    check(!str_contains($register . $files, 'forged-signature') && !str_contains($register . $files, 'valid-signature') && !str_contains($register . $files, (string) getenv('PAYPAL_CLIENT_SECRET')), 'No signatures or secrets in logs');
} finally {
    mode('');
    if ($quoteProfileCreated) $db->createCommand()->delete('member_profiles', ['user_id'=>$quoteMemberId])->execute();
    foreach (['paypal_webhook_events', 'membership_payments', 'payment_methods', 'membership_fees'] as $table) $db->createCommand()->delete($table)->execute();
}
echo "PASS {$checks} PayPal webhook checks\n";
