<?php
// Server PayPal finto per la suite runtime (php -S 127.0.0.1:8099 <questo file>): Orders v2 minimo.
// Mai usato fuori dai test: PayPal::config() accetta PAYPAL_MODE=mock solo con NAPAY_TEST_DB_CONFIRM=isolated-fixture.
// Scenari pilotati dal test con il file /tmp/paypal-mock/mode.
$dir = '/tmp/paypal-mock';
if (!is_dir($dir)) mkdir($dir, 0700, true);
$mode = is_file("$dir/mode") ? trim((string) file_get_contents("$dir/mode")) : '';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$auth = $headers['authorization'] ?? '';
file_put_contents("$dir/requests.log", json_encode(['method' => $method, 'path' => $path,
    'request_id' => $headers['paypal-request-id'] ?? null, 'auth' => strtok($auth, ' ')]) . "\n", FILE_APPEND);
header('Content-Type: application/json');
function reply(int $status, array $body) { http_response_code($status); echo json_encode($body); exit; }
function bearer(string $auth, string $dir) { return $auth === 'Bearer ' . trim((string) @file_get_contents("$dir/token")); }

if ($method === 'POST' && $path === '/v1/oauth2/token') {
    $expected = 'Basic ' . base64_encode(getenv('PAYPAL_CLIENT_ID') . ':' . getenv('PAYPAL_CLIENT_SECRET'));
    if (!hash_equals($expected, $auth)) reply(401, ['error' => 'invalid_client']);
    $token = 'mock-access-' . bin2hex(random_bytes(8));
    file_put_contents("$dir/token", $token);
    reply(200, ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 32400]);
}
if (!bearer($auth, $dir)) reply(401, ['name' => 'AUTHENTICATION_FAILURE']);

if ($method === 'POST' && $path === '/v2/checkout/orders') {
    if ($mode === 'fail-create') reply(500, ['name' => 'INTERNAL_SERVER_ERROR']);
    $body = json_decode(file_get_contents('php://input'), true);
    $unit = $body['purchase_units'][0] ?? [];
    $id = strtoupper(substr(bin2hex(random_bytes(9)), 0, 17));
    file_put_contents("$dir/order-$id.json", json_encode(['unit' => $unit, 'intent' => $body['intent'] ?? null,
        'context' => $body['payment_source']['paypal']['experience_context'] ?? [], 'captures' => 0]));
    $host = $mode === 'evil-approve' ? 'evil.example.invalid' : 'www.sandbox.paypal.com';
    reply(200, ['id' => $id, 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [
        ['rel' => 'self', 'href' => "https://api-m.sandbox.paypal.com/v2/checkout/orders/$id"],
        ['rel' => 'payer-action', 'href' => "https://$host/checkoutnow?token=$id"],
    ]]);
}
if ($method === 'POST' && preg_match('#^/v2/checkout/orders/([A-Z0-9]+)/capture$#', $path, $m)) {
    $file = "$dir/order-{$m[1]}.json";
    if (!is_file($file)) reply(404, ['name' => 'RESOURCE_NOT_FOUND']);
    if ($mode === 'capture-declined') reply(422, ['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'INSTRUMENT_DECLINED']]]);
    $order = json_decode(file_get_contents($file), true);
    $order['captures']++;
    file_put_contents($file, json_encode($order));
    $amount = $order['unit']['amount'];
    if ($mode === 'capture-mismatch') $amount['value'] = '0.01';
    reply(201, ['id' => $m[1], 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [[
        'id' => 'CAP' . $m[1], 'status' => 'COMPLETED', 'amount' => $amount, 'custom_id' => $order['unit']['custom_id'] ?? null,
    ]]]]]]);
}
reply(404, ['name' => 'RESOURCE_NOT_FOUND']);
