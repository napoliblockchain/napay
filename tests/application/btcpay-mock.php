<?php
// BTCPay Server finto per la suite runtime (php -S 127.0.0.1:8098 <questo file>): Greenfield fatture minimo.
// Mai usato fuori dai test: BTCPay::config() accetta http://127.0.0.1 solo con NAPAY_TEST_DB_CONFIRM=isolated-fixture.
// Scenari dal test con /tmp/btcpay-mock/mode; stato fattura scrivibile in /tmp/btcpay-mock/invoice-<id>.json.
$dir = '/tmp/btcpay-mock';
if (!is_dir($dir)) mkdir($dir, 0700, true);
$mode = is_file("$dir/mode") ? trim((string) file_get_contents("$dir/mode")) : '';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
header('Content-Type: application/json');
function reply(int $status, array $body) { http_response_code($status); echo json_encode($body, JSON_UNESCAPED_SLASHES); exit; }
file_put_contents("$dir/requests.log", json_encode(['method' => $method, 'path' => $path]) . "\n", FILE_APPEND);
if (!hash_equals('token ' . getenv('BTCPAY_API_KEY'), $headers['authorization'] ?? '')) reply(401, ['code' => 'unauthenticated']);
$store = preg_quote((string) getenv('BTCPAY_STORE_ID'), '#');

if ($method === 'POST' && preg_match("#^/api/v1/stores/$store/invoices$#", $path)) {
    if ($mode === 'fail-create') reply(500, ['code' => 'error']);
    $body = json_decode(file_get_contents('php://input'), true);
    $id = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(24))), 0, 22);
    $invoice = ['id' => $id, 'storeId' => getenv('BTCPAY_STORE_ID'), 'status' => 'New', 'amount' => $body['amount'], 'currency' => $body['currency'],
        'metadata' => $body['metadata'] ?? [], 'checkout' => $body['checkout'] ?? [],
        'checkoutLink' => ($mode === 'evil-link' ? 'https://evil.example.invalid' : 'http://127.0.0.1:8098') . "/i/$id"];
    file_put_contents("$dir/invoice-$id.json", json_encode($invoice));
    reply(200, $invoice);
}
if ($method === 'GET' && preg_match("#^/api/v1/stores/$store/invoices/([A-Za-z0-9]+)$#", $path, $m)) {
    if ($mode === 'fail-get') reply(503, ['code' => 'unavailable']);
    $file = "$dir/invoice-{$m[1]}.json";
    if (!is_file($file)) reply(404, ['code' => 'invoice-not-found']);
    $invoice = json_decode(file_get_contents($file), true);
    if ($mode === 'mismatch') $invoice['amount'] = '0.01';
    reply(200, $invoice);
}
reply(404, ['code' => 'not-found']);
