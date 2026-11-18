<?php
// SIP-033 passo 3: pagamenti BTC del POS. API HTTP reale (validazione, BTCPay non configurato) e servizio con un gateway
// finto (creazione, verifica della fattura, esiti, webhook, sweep). DB usa e getta; nessuna rete verso BTCPay.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
$app = new yii\web\Application(require $root . '/config/web.php');
$db = $app->db;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);
$key = env_required('NAPAY_TEST_POS_CLIENT_KEY');
$checks = 0; $secrets = [$key, 'fixture-password-only'];

use app\components\pos\PosApi;
use app\components\pos\btc\BtcPayments;
use app\components\pos\btc\BtcpayPosGateway;
use app\components\pos\btc\PosInvoiceGateway;
use app\components\pos\btc\PosInvoiceUnknown;
use app\components\pos\tts\PaymentException;

function check($condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks; echo 'PASS: ' . $label . "\n";
}
function fresh(): void { global $app; if (!$app->cache->flush()) throw new RuntimeException('Cache reset failed.'); }
function uuid(): string {
    $b = random_bytes(16); $b[6] = chr(ord($b[6]) & 0x0f | 0x40); $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
function api(string $method, string $path, ?string $token, ?array $body = null, ?string $idempotency = null): array {
    global $key;
    $headers = ['Accept: application/json', 'X-POS-Client-Key: ' . $key];
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    if ($idempotency !== null) $headers[] = 'Idempotency-Key: ' . $idempotency;
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    $curl = curl_init('http://127.0.0.1/api/pos/v1/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30, CURLOPT_PROXY => '',
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($curl);
    if (!is_string($raw)) throw new RuntimeException('HTTP transport failed.');
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $r = ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => substr($raw, $size)];
    curl_close($curl);
    $r['json'] = json_decode($r['body'], true);
    return $r;
}
function code(array $r): ?string { return $r['json']['error']['code'] ?? null; }
function apiLogin(string $email): string {
    global $secrets;
    fresh();
    $r = api('POST', 'sessions', null, ['email' => $email, 'password' => 'fixture-password-only']);
    if ($r['status'] !== 201) throw new RuntimeException('Fixture login failed: ' . $email);
    $secrets[] = $r['json']['access_token'];
    return $r['json']['access_token'];
}
function scalar(string $sql, array $p = []) { global $db; return $db->createCommand($sql, $p)->queryScalar(); }
function row(string $publicId): array { global $db; return $db->createCommand('SELECT * FROM pos_btc_payments WHERE public_id=:p', [':p' => $publicId])->queryOne(); }
function refused(callable $call, string $code): bool { try { $call(); } catch (PaymentException $e) { return $e->getMessage() === $code; } return false; }

/** BTCPay finto: risposte in coda, chiamate registrate; lo stato riflette l'orderId ricevuto (come BTCPay). */
final class FakeGateway implements PosInvoiceGateway
{
    public array $posts = []; public array $statusCalls = []; public array $create = []; public array $status = [];
    public array $orders = [];
    public function __construct(private readonly string $origin) {}
    public function instance(): string { return hash('sha256', $this->origin); }
    public function checkoutUrl(string $invoiceId): string { return $this->origin . '/i/' . $invoiceId; }
    public function createInvoice(string $appId, int $amountCents, string $orderId): array {
        $this->posts[] = [$appId, $amountCents, $orderId];
        $next = array_shift($this->create) ?? throw new RuntimeException('Unexpected POST');
        if ($next instanceof Throwable) throw $next;
        if ($next['outcome'] === 'created') $this->orders[$next['invoice_id']] = [$orderId, $amountCents];
        return $next;
    }
    public function status(string $invoiceId): ?array {
        $this->statusCalls[] = $invoiceId;
        $next = array_shift($this->status);
        if ($next === null) return null;
        [$order, $cents] = $this->orders[$invoiceId] ?? [null, 0];
        return $next + ['invoice_id' => $invoiceId, 'order_id' => $order, 'store_id' => 'st-btc-a', 'currency' => 'EUR', 'fiat_digits' => (string) $cents,
            'status' => 'new', 'address' => 'bcrt1qnynpu9auq7msewe4t5lwkv042x6vvp6wxcdmtx', 'due_satoshis' => 24680, 'confirmations' => 0, 'expires_in' => 898,
            // 12,34 EUR = 20000 sat a 61.700,00 EUR/BTC; 4680 sat di fee di rete aggiunti da BTCPay
            'order_satoshis' => 20000, 'paid_satoshis' => 0, 'rate_cents' => 6170000];
    }
}

// --- Fixture: merchant A (gestore runtime-merchant), negozio A1 con wallet BTC, A2 collegato ma senza wallet, A3 senza collegamento
$hash = password_hash('fixture-password-only', PASSWORD_BCRYPT);
$level = fn(int $l) => (int) scalar('SELECT id FROM privileges WHERE level=:l ORDER BY id LIMIT 1', [':l' => $l]);
$user = function (string $name, int $privilegeLevel) use ($db, $level, $hash): int {
    $db->createCommand()->insert('users', ['username' => $name, 'email' => $name . '@example.invalid', 'password' => $hash, 'first_name' => 'BTC', 'last_name' => $name,
        'oauth_provider' => 'fixture', 'oauth_uid' => $name, 'authKey' => 'fixture-' . $name, 'accessToken' => 'fixture-' . $name, 'picture' => '/favicon.ico',
        'privilege_id' => $level($privilegeLevel), 'is_merchant' => 0, 'is_active' => 1])->execute();
    return (int) $db->lastInsertID;
};
$base = ['email' => 'fixture@example.invalid', 'phone' => '', 'mobile' => '', 'addressStreet' => '', 'addressNumberHouse' => '', 'addressCity' => '', 'addressZip' => '', 'addressProvince' => '', 'addressCountry' => ''];
$now = time(); $on = ['is_enabled' => 1, 'created_at' => $now, 'updated_at' => $now];
$managerA = (int) scalar("SELECT id FROM users WHERE username='runtime-merchant'");
$merchantA = (int) scalar("SELECT id FROM merchants WHERE description='Runtime company merchant'");
$db->createCommand()->insert('member_profiles', ['user_id' => $managerA, 'is_corporate' => 1, 'denomination' => 'BTC PG A'])->execute();
$vpub = 'vpub5Zf9o1xg1TVJN3pte4sDD73BMq71dLFTZPgqvBxe872nMEiDtoBo99udYHBuxP3wDbhie79PmTtL8GxYR5ana9FgxdKTp13TvV3kU6UaHrP';
$store = function (string $name, ?string $scheme) use ($db, $base, $merchantA): int {
    $db->createCommand()->insert('stores', ['merchant_id' => $merchantA, 'description' => $name] + $base)->execute();
    $id = (int) $db->lastInsertID;
    $db->createCommand()->insert('storesettings', ['store_id' => $id, 'defaultCurrency' => 'EUR', 'speedPolicy' => 'MediumSpeed', 'requiresRefundEmail' => 1,
        'derivationScheme' => $scheme, 'receipt' => json_encode(['enabled' => 1, 'showQR' => 1, 'showPayments' => 1])])->execute();
    return $id;
};
$sA = $store('BTC A1', $vpub); $sA2 = $store('BTC A2', null); $sA3 = $store('BTC A3', $vpub);
$op = $user('btc-op', 0); $op2 = $user('btc-op-2', 0);
foreach ([$op, $op2] as $u) {
    $db->createCommand()->insert('pos_operators', ['user_id' => $u, 'merchant_id' => $merchantA] + $on)->execute();
    foreach ([$sA, $sA2, $sA3] as $s) $db->createCommand()->insert('pos_store_operators', ['user_id' => $u, 'merchant_id' => $merchantA, 'store_id' => $s] + $on)->execute();
}
$terminal = function (int $storeId, string $name) use ($db, $now, $merchantA): array {
    $id = uuid();
    $db->createCommand()->insert('pos_terminals', ['public_id' => $id, 'merchant_id' => $merchantA, 'store_id' => $storeId, 'name' => $name, 'is_enabled' => 1, 'created_at' => $now, 'updated_at' => $now])->execute();
    return [$id, (int) $db->lastInsertID];
};
[$tA, $tAi] = $terminal($sA, 'Cassa BTC A1'); [$tA2, $tA2i] = $terminal($sA2, 'Cassa BTC A2'); [$tA3, ] = $terminal($sA3, 'Cassa BTC A3');
// collegamenti BTCPay come li lascia il worker (SIP-022): account, store e app POS sincronizzati
$origin = 'https://btcpay.example.invalid';
$instance = hash('sha256', $origin);
$ref = bin2hex(random_bytes(16));
$db->createCommand()->insert('btcpay_merchant_accounts', ['instance' => $instance, 'merchant_id' => $merchantA, 'ref' => $ref, 'technical_email' => 'm-' . $ref . '@btcpay-merchants.jamprojects.it',
    'remote_user_id' => 'user-a', 'state' => 'synced', 'created_at' => $now, 'updated_at' => $now])->execute();
$account = (int) $db->lastInsertID;
$link = function (int $storeId, int $terminalId, string $remoteStore, string $remoteApp) use ($db, $instance, $account, $merchantA, $now): void {
    $db->createCommand()->insert('btcpay_store_links', ['instance' => $instance, 'account_id' => $account, 'merchant_id' => $merchantA, 'store_id' => $storeId,
        'marker' => bin2hex(random_bytes(16)), 'remote_store_id' => $remoteStore, 'state' => 'synced', 'created_at' => $now, 'updated_at' => $now])->execute();
    $storeLink = (int) $db->lastInsertID;
    $db->createCommand()->insert('btcpay_pos_links', ['instance' => $instance, 'store_link_id' => $storeLink, 'merchant_id' => $merchantA, 'store_id' => $storeId,
        'terminal_id' => $terminalId, 'marker' => bin2hex(random_bytes(16)), 'remote_app_id' => $remoteApp, 'state' => 'synced', 'created_at' => $now, 'updated_at' => $now])->execute();
};
$link($sA, $tAi, 'st-btc-a', 'app-btc-a'); $link($sA2, $tA2i, 'st-btc-a2', 'app-btc-a2');
check((int) scalar('SELECT COUNT(*) FROM pos_btc_payments') === 0, 'Ledger empty before the test');

// --- API: sessioni, aperture e configurazione assente nel web di test
$u = apiLogin('btc-op@example.invalid'); $u2 = apiLogin('btc-op-2@example.invalid');
fresh();
$open = fn(string $token, string $terminalId) => api('POST', 'terminals/' . $terminalId . '/openings', $token, ['instance_id' => uuid()], uuid())['json'];
$oA = $open($u, $tA); $oA2 = $open($u, $tA2); $oA3 = $open($u2, $tA3);
check(($oA['state'] ?? null) === 'active' && ($oA2['state'] ?? null) === 'active' && ($oA3['state'] ?? null) === 'active', 'Terminals opened');
$btc = fn(string $token, array $o, $cents, ?string $idem = null) => api('POST', 'openings/' . $o['id'] . '/btc-payments', $token,
    ['instance_id' => $o['instance_id'], 'generation' => $o['generation'], 'amount_cents' => $cents], $idem ?? uuid());
check(BtcpayPosGateway::fromEnv() === null, 'Web without BTCPay origin: BTC gateway off (fail closed)');
$r = api('GET', 'openings/' . $oA['id'] . '/methods', $u);
check($r['status'] === 200 && $r['json'] === ['tts' => false, 'btc' => false, 'btc_currency' => null], 'Methods: BTC off when BTCPay is not configured');
$r = $btc($u, $oA, 1000);
check($r['status'] === 503 && code($r) === 'payment_unavailable', 'BTC payment without BTCPay configuration: 503 payment_unavailable');
foreach ([0, -1, 100001, '1000', 10.5] as $bad) check(code($btc($u, $oA, $bad)) === 'validation_failed', 'Invalid BTC amount refused: ' . json_encode($bad));
check(api('POST', 'openings/' . $oA['id'] . '/btc-payments', $u, ['instance_id' => $oA['instance_id'], 'generation' => $oA['generation'], 'amount_cents' => 100])['status'] === 400, 'Missing Idempotency-Key refused');
check(code(api('POST', 'openings/' . $oA['id'] . '/btc-payments', null, ['amount_cents' => 1], uuid())) === 'invalid_token', 'Bearer required');
check(code(api('GET', 'btc-payments/not-a-uuid', $u)) === 'not_found' && code(api('GET', 'openings/' . $oA['id'] . '/methods', $u2)) === 'not_found', 'Malformed payment 404; opening of another session 404');
check((int) scalar('SELECT COUNT(*) FROM pos_btc_payments') === 0, 'Refused requests created nothing');

// --- Servizio con BTCPay finto
$client = PosApi::authenticateClient($key);
$session = PosApi::findActiveSession($u, $client); $session2 = PosApi::findActiveSession($u2, $client);
$fake = new FakeGateway($origin);
check(BtcPayments::methods($session, $oA['id'], $fake) === ['tts' => false, 'btc' => true, 'btc_currency' => 'EUR'] && BtcPayments::methods($session, $oA2['id'], $fake)['btc'] === false
    && BtcPayments::methods($session2, $oA3['id'], $fake)['btc'] === false, 'BTC only with xpub wallet and linked store and POS app (not for a store without wallet or link)');
check(refused(fn() => BtcPayments::create($session, $oA2['id'], $oA2['instance_id'], $oA2['generation'], 500, uuid(), $fake), PaymentException::UNAVAILABLE)
    && $fake->posts === [], 'Store without BTC wallet: unavailable, no BTCPay call');
check(refused(fn() => BtcPayments::create($session, $oA['id'], $oA['instance_id'], $oA['generation'], 500, uuid(), new FakeGateway('https://other.example.invalid')), PaymentException::UNAVAILABLE),
    'Another BTCPay instance: unavailable (links belong to the configured instance only)');

// creazione riuscita
$idem = uuid();
$fake->create = [['outcome' => 'created', 'invoice_id' => 'INV0000000001']]; $fake->status = [[]];
[$status, $p] = BtcPayments::create($session, $oA['id'], $oA['instance_id'], $oA['generation'], 1234, $idem, $fake);
$row = row($p['id']);
check($status === 201 && $p['state'] === 'pending' && $p['method'] === 'btc' && $p['amount'] === '12.34' && $p['currency'] === 'EUR' && $p['opening_id'] === $oA['id']
    && $p['btc_address'] === 'bcrt1qnynpu9auq7msewe4t5lwkv042x6vvp6wxcdmtx' && $p['amount_btc'] === '0.00024680'
    && $p['qr'] === 'bitcoin:bcrt1qnynpu9auq7msewe4t5lwkv042x6vvp6wxcdmtx?amount=0.0002468' && $p['checkout_url'] === $origin . '/i/INV0000000001' && $p['error'] === null,
    'Invoice created: pending with address, BTC amount, BIP21 QR and checkout fallback');
check($fake->posts === [['app-btc-a', 1234, $p['id']]] && $fake->statusCalls === ['INV0000000001'], 'One POST to the terminal POS app with the Napay payment ID as orderId, then the public status');
check($row['instance'] === $instance && $row['remote_store_id'] === 'st-btc-a' && $row['remote_app_id'] === 'app-btc-a' && $row['invoice_id'] === 'INV0000000001'
    && (int) $row['creation_started_at'] === (int) $row['created_at'] && (int) $row['amount_satoshis'] === 24680 && (int) $row['session_id'] === (int) $session->id,
    'Row: instance, remote IDs and invoice recorded, checkpoint before the network');
$public = json_encode($p);
check(array_keys($p) === ['id', 'opening_id', 'method', 'amount', 'currency', 'state', 'expires_at', 'btc_address', 'amount_btc', 'qr', 'checkout_url', 'confirmations',
    'rate', 'order_btc', 'network_fee_btc', 'paid_btc', 'processing_at', 'settled_at', 'error'] && !str_contains($public, 'st-btc-a') && !str_contains($public, 'app-btc-a'),
    'Exact projection: no remote store/app IDs, session, operator or invoice field');
check($p['rate'] === '61700.00' && $p['order_btc'] === '0.00020000' && $p['network_fee_btc'] === '0.00004680' && $p['paid_btc'] === '0.00000000'
    && (int) $row['rate_cents'] === 6170000 && (int) $row['order_satoshis'] === 20000 && (int) $row['paid_satoshis'] === 0,
    'SIP-033 step 5: BTCPay rate (consistent with euro and order satoshis), order without network fee, fee and BTC received');
[$s2, $again] = BtcPayments::create($session, $oA['id'], $oA['instance_id'], $oA['generation'], 1234, $idem, $fake);
check($s2 === 200 && $again['id'] === $p['id'] && count($fake->posts) === 1, 'Idempotent replay: same payment, no second invoice');
check(refused(fn() => BtcPayments::create($session, $oA['id'], $oA['instance_id'], $oA['generation'], 999, $idem, $fake), PaymentException::IDEMPOTENCY), 'Same key, other amount: conflict');
check(refused(fn() => BtcPayments::view($session2, $p['id'], $fake), PaymentException::NOT_FOUND), 'Payment of another session: not found');

// esiti dallo stato pubblico e dai webhook firmati
$fake->status = [['status' => 'processing', 'confirmations' => 0, 'due_satoshis' => 0, 'paid_satoshis' => 24680, 'rate_cents' => 9999999, 'order_satoshis' => 1]];
$v = BtcPayments::view($session, $p['id'], $fake);
check($v['paid_btc'] === '0.00024680' && $v['rate'] === '61700.00' && $v['order_btc'] === '0.00020000' && $v['amount_btc'] === '0.00024680',
    'Received: BTC paid updated; rate, order and requested total stay those of the invoice');
check($v['state'] === 'processing' && $v['processing_at'] !== null && $v['settled_at'] === null && $v['qr'] !== null, 'Public status Processing: received (0 conf), QR still shown');
check(BtcPayments::applyEvent($instance, $sA2, 'InvoiceSettled', 'INV0000000001') === false && row($p['id'])['state'] === 'processing', 'Event signed for another store ignored');
check(BtcPayments::applyEvent(hash('sha256', 'https://other.example.invalid'), $sA, 'InvoiceSettled', 'INV0000000001') === false, 'Event of another instance ignored');
check(BtcPayments::applyEvent($instance, $sA, 'InvoiceReceivedPayment', 'INV0000000001') === false, 'Partial payment event does not change the state');
check(BtcPayments::applyEvent($instance, $sA, 'InvoiceSettled', 'INV0000000001') === true && row($p['id'])['state'] === 'settled'
    && BtcPayments::applyEvent($instance, $sA, 'InvoiceSettled', 'INV0000000001') === false && BtcPayments::applyEvent($instance, $sA, 'InvoiceExpired', 'INV0000000001') === false,
    'InvoiceSettled: confirmed once; repeated or later events never go back');
$calls = count($fake->statusCalls);
$v = BtcPayments::view($session, $p['id'], $fake);
check($v['state'] === 'settled' && $v['settled_at'] !== null && $v['qr'] === null && $v['checkout_url'] === null && count($fake->statusCalls) === $calls, 'Settled: final, no more BTCPay reads, no QR');

// fattura che non corrisponde alla richiesta (importo diverso)
$fake->create = [['outcome' => 'created', 'invoice_id' => 'INV0000000002']]; $fake->status = [['fiat_digits' => '9999']];
[, $m] = BtcPayments::create($session, $oA['id'], $oA['instance_id'], $oA['generation'], 500, uuid(), $fake);
check($m['state'] === 'invalid' && $m['error'] === 'invoice_mismatch' && $m['qr'] === null && row($m['id'])['closed_at'] !== null, 'Invoice not matching the request (amount): invalid, never shown to the customer');
$fake->create = [['outcome' => 'created', 'invoice_id' => 'INV0000000003']]; $fake->status = [['order_id' => 'someone-else']];
[, $m] = BtcPayments::create($session, $oA['id'], $oA['instance_id'], $oA['generation'], 500, uuid(), $fake);
check($m['state'] === 'invalid' && $m['error'] === 'invoice_mismatch', 'Invoice with another orderId: invalid');

// rifiuto, esito ignoto, stato non leggibile alla creazione
$fake->create = [['outcome' => 'rejected', 'code' => 'invoice_rejected']];
[$s3, $f] = BtcPayments::create($session, $oA['id'], $oA['instance_id'], $oA['generation'], 700, uuid(), $fake);
check($s3 === 201 && $f['state'] === 'failed' && $f['error'] === 'invoice_rejected' && row($f['id'])['invoice_id'] === null && row($f['id'])['closed_at'] !== null, 'Invoice refused by BTCPay: failed with a code, closed');
$unknownKey = uuid();
$fake->create = [new PosInvoiceUnknown()];
[, $x] = BtcPayments::create($session, $oA['id'], $oA['instance_id'], $oA['generation'], 800, $unknownKey, $fake);
$posts = count($fake->posts);
[$s4, $x2] = BtcPayments::create($session, $oA['id'], $oA['instance_id'], $oA['generation'], 800, $unknownKey, $fake);
check($x['state'] === 'reconcile_required' && $x['error'] === 'invoice_outcome_unknown' && $s4 === 200 && $x2['state'] === 'reconcile_required' && count($fake->posts) === $posts,
    'Unknown outcome: reconcile_required, the replay never sends a second POST');
$fake->create = [['outcome' => 'created', 'invoice_id' => 'INV0000000004']]; $fake->status = [null];
[, $n] = BtcPayments::create($session, $oA['id'], $oA['instance_id'], $oA['generation'], 900, uuid(), $fake);
check($n['state'] === 'pending' && $n['btc_address'] === null && $n['qr'] === null && $n['checkout_url'] === $origin . '/i/INV0000000004', 'Status unreadable at creation: pending without QR (checkout link only)');
$fake->status = [[]];
$n = BtcPayments::view($session, $n['id'], $fake);
check($n['state'] === 'pending' && $n['btc_address'] !== null && $n['qr'] !== null, 'Next read fills address and QR');
check(BtcPayments::applyEvent($instance, $sA, 'InvoiceExpired', 'INV0000000004') === true && row($n['id'])['state'] === 'expired' && row($n['id'])['closed_at'] !== null, 'InvoiceExpired: closed as expired');

// sweep: creazione incerta oltre la scadenza chiusa; attesa scaduta riletta su BTCPay
$fake->create = [['outcome' => 'created', 'invoice_id' => 'INV0000000005']]; $fake->status = [[]];
[, $late] = BtcPayments::create($session, $oA['id'], $oA['instance_id'], $oA['generation'], 1000, uuid(), $fake);
check($late['rate'] === null && $late['order_btc'] === '0.00020000' && row($late['id'])['rate_cents'] === null, 'Rate inconsistent with euro and order satoshis: never stored or shown');
$db->createCommand('UPDATE pos_btc_payments SET created_at=created_at-2000, creation_started_at=creation_started_at-2000, expires_at=created_at+900 WHERE public_id IN (:a, :b)',
    [':a' => $x['id'], ':b' => $late['id']])->execute();
$fake->status = [['status' => 'expired']];
$swept = BtcPayments::sweep(100, $fake);
check($swept === ['checked' => 2, 'settled' => 0, 'closed' => 2] && row($x['id'])['state'] === 'failed' && row($x['id'])['last_error_code'] === 'invoice_outcome_unknown'
    && row($late['id'])['state'] === 'expired', 'Sweep: uncertain creation closed as failed, expired invoice read from BTCPay');
check(BtcPayments::sweep(100, $fake) === ['checked' => 0, 'settled' => 0, 'closed' => 0], 'Sweep is idle afterwards');

// vincoli: nessun DELETE logico, ogni riga coerente con lo schema
check((int) scalar("SELECT COUNT(*) FROM pos_btc_payments WHERE merchant_id=:m AND store_id=:s", [':m' => $merchantA, ':s' => $sA]) === (int) scalar('SELECT COUNT(*) FROM pos_btc_payments'),
    'All payments on the BTC store of the merchant');
// --- SIP-033 passo 5: storico del POS con TTS e BTC insieme e totali di oggi
$history = app\components\pos\PosHistory::forOpening($session, $oA['id']);
$items = array_column($history['payments'], null, 'id');
$btcRows = $db->createCommand("SELECT COUNT(*) n, SUM(amount_cents) c, SUM(COALESCE(paid_satoshis, amount_satoshis)) s FROM pos_btc_payments WHERE terminal_id=(SELECT terminal_id FROM pos_terminal_openings WHERE public_id=:o) AND state IN ('processing','settled')",
    [':o' => $oA['id']])->queryOne();
check(array_keys($history) === ['payments', 'totals'] && array_keys($history['payments'][0]) === app\components\pos\PosHistory::ITEM
    && $items[$p['id']]['method'] === 'btc' && $items[$p['id']]['state'] === 'settled' && $items[$p['id']]['amount'] === '12.34' && $items[$p['id']]['currency'] === 'EUR'
    && $items[$p['id']]['rate'] === '61700.00' && $items[$p['id']]['paid_btc'] === '0.00024680' && $items[$p['id']]['paid_at'] !== null && $items[$p['id']]['tx_hash'] === null
    && $items[$p['id']]['own'] === true && !str_contains(json_encode($history), 'bcrt1q') && !str_contains(json_encode($history), 'INV000'),
    'History: BTC items with method, state, rate and BTC paid; never address or invoice');
$created = array_column($history['payments'], 'created_at');
$sorted = $created; rsort($sorted);
check($created === $sorted, 'History newest first');
$sum = $history['totals']['btc'];
check(count($sum) === 1 && $sum[0]['currency'] === 'EUR' && $sum[0]['count'] === (int) $btcRows['n'] && $sum[0]['count'] >= 1
    && $sum[0]['amount'] === sprintf('%d.%02d', intdiv((int) $btcRows['c'], 100), (int) $btcRows['c'] % 100)
    && $sum[0]['btc'] === sprintf('%d.%08d', intdiv((int) $btcRows['s'], 100000000), (int) $btcRows['s'] % 100000000),
    'Totals of today: BTC received or confirmed per currency, in fiat and in BTC paid');
$tomorrow = app\components\pos\PosHistory::totals((int) scalar('SELECT terminal_id FROM pos_terminal_openings WHERE public_id=:o', [':o' => $oA['id']]), time() + 86400);
check($tomorrow['btc'] === [] && $tomorrow['tts']['count'] === 0, 'Totals start again at midnight (Rome): nothing counted for the next day');
$log = (string) scalar("SELECT GROUP_CONCAT(description) FROM logs WHERE description LIKE 'Richiesta di pagamento BTC%'");
$leak = false; foreach ($secrets as $secret) $leak = $leak || str_contains($log, $secret);
check($log !== '' && !$leak && !str_contains($log, 'app-btc-a') && !str_contains($log, 'INV0000000001'), 'Payment requests audited without tokens, remote IDs or invoice IDs');
echo "PASS $checks POS BTC payment checks\n";
