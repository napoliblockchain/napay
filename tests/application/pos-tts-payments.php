<?php
// SIP-021 fase 3 (PAGAMENTO-TTS-CONTRATTO): pagamenti TTS POS, HTTP reale + DB e chain usa e getta. Mai DB o chain condivisi.
// Fasi: setup (fixture, richieste, piano dei transfer) -> transfer reali dal runner -> verify -> offline (chain spenta).
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
$app = new yii\web\Application(require $root . '/config/web.php');
$db = $app->db;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);
$phase = $argv[1] ?? '';
if (!in_array($phase, ['setup', 'verify', 'offline'], true)) exit(1);
$stateFile = '/tmp/napay-tts-state.json';
$key = env_required('NAPAY_TEST_POS_CLIENT_KEY');
$checks = 0; $jar = null; $secrets = [$key, 'fixture-password-only'];

use app\components\Crypt;
use app\components\pos\tts\TtsAddress;
use app\components\pos\tts\TtsChain;
use app\components\pos\tts\TtsChainException;

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
function result($curl, ?string $raw): array {
    if (!is_string($raw)) throw new RuntimeException('HTTP transport failed.');
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $r = ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    $r['json'] = json_decode($r['body'], true);
    return $r;
}
function api(string $method, string $path, ?string $token, ?array $body = null, ?string $idempotency = null, ?string $rawBody = null): array {
    global $key;
    $headers = ['Accept: application/json', 'X-POS-Client-Key: ' . $key];
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    if ($idempotency !== null) $headers[] = 'Idempotency-Key: ' . $idempotency;
    $payload = $rawBody ?? ($body === null ? null : json_encode($body));
    if ($payload !== null) $headers[] = 'Content-Type: application/json';
    $curl = curl_init('http://127.0.0.1/api/pos/v1/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30, CURLOPT_PROXY => '',
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
    if ($payload !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
    $r = result($curl, curl_exec($curl) ?: null);
    curl_close($curl);
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
function noLeak(array $r): bool {
    global $secrets;
    foreach ($secrets as $secret) if (str_contains($r['body'], $secret)) return false;
    return !str_contains($r['body'], 'session') && str_contains(strtolower($r['headers']), 'cache-control: no-store');
}
function pay(string $token, array $opening, int $cents, ?string $idempotency = null): array {
    return api('POST', 'openings/' . $opening['id'] . '/payments', $token,
        ['instance_id' => $opening['instance_id'], 'generation' => $opening['generation'], 'amount_cents' => $cents], $idempotency ?? uuid());
}
function scalar(string $sql, array $p = []) { global $db; return $db->createCommand($sql, $p)->queryScalar(); }
function payment(string $publicId): array { global $db; return $db->createCommand('SELECT * FROM pos_tts_payments WHERE public_id=:p', [':p' => $publicId])->queryOne(); }
function web(string $path, ?array $form = null): array {
    global $jar;
    $curl = curl_init('http://127.0.0.1' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20,
        CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($form !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form)]);
    $r = result($curl, curl_exec($curl) ?: null);
    curl_close($curl);
    return $r;
}
function csrf(array $page): string {
    if (!preg_match('/<meta name="csrf-token" content="([^"]+)"/', $page['body'], $m)) throw new RuntimeException('CSRF unavailable.');
    return html_entity_decode($m[1]);
}
function webLogin(string $email): void {
    global $jar;
    $jar = tempnam(sys_get_temp_dir(), 'napay-tts-');
    fresh();
    $page = web('/site/login');
    if (web('/site/login', ['_csrf' => csrf($page), 'LoginForm' => ['email' => $email, 'password' => 'fixture-password-only']])['status'] !== 302) throw new RuntimeException('Web login failed.');
}
// ID cifrati legati alla sessione (Crypt): la chiave del negozio si legge dal selettore della pagina, come fa l'utente
function walletUrl(?string $key, string $action = 'index'): string { return '/store-wallet/' . $action . ($key === null ? '' : '?store=' . urlencode($key)); }
function storeKey(string $name): string {
    if (!preg_match('~<option value="([^"]+)"[^>]*>' . preg_quote($name, '~') . '</option>~', web('/store-wallet/index')['body'], $m)) throw new RuntimeException('Store not listed: ' . $name);
    return html_entity_decode($m[1]);
}
function saveWallet(string $address, ?string $store = null): array { $page = web(walletUrl($store)); return web(walletUrl($store), ['_csrf' => csrf($page), 'TtsWalletForm' => ['address' => $address]]); }
function storeWallet(int $store) { return scalar('SELECT address FROM store_tts_wallets WHERE store_id=:s', [':s' => $store]); }
function logged(string $needle): int { return (int) scalar('SELECT COUNT(*) FROM logs WHERE description LIKE :d', [':d' => '%' . $needle . '%']); }

if ($phase === 'setup') {
    // --- Indirizzi EIP-55 (vettore ufficiale) e configurazione della chain
    $vector = '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed';
    check(TtsAddress::normalize($vector) === $vector && TtsAddress::normalize(strtolower($vector)) === $vector
        && TtsAddress::normalize('0x' . strtoupper(substr($vector, 2))) === $vector, 'EIP-55: checksum accepted, single-case input normalized');
    foreach (['0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAeD' => 'wrong checksum', '0x' . str_repeat('0', 40) => 'zero address',
        '5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed' => 'missing 0x', '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAe' => 'short', $vector . "\n" => 'trailing newline'] as $bad => $label) {
        check(TtsAddress::normalize($bad) === null, 'EIP-55 rejects: ' . $label);
    }
    $chain = TtsChain::fromEnv();
    check($chain !== null && $chain->blockNumber() > 0, 'Disposable chain reachable with configured chain id');
    putenv('TTS_CHAIN_ID=1');
    try { TtsChain::fromEnv()->blockNumber(); check(false, 'Wrong chain id refused'); } catch (TtsChainException $e) { check(true, 'Wrong chain id refused'); }
    putenv('TTS_CHAIN_ID'); putenv('TTS_TOKEN_ADDRESS=0x1234');
    try { TtsChain::fromEnv(); check(false, 'Invalid token address is a configuration error'); } catch (yii\base\InvalidConfigException $e) { check(true, 'Invalid token address is a configuration error'); }
    putenv('TTS_TOKEN_ADDRESS');
    check(TtsChain::fromEnv() !== null, 'Container configuration restored');

    // --- Fixture: merchant A (gestore runtime-merchant) con due negozi, wallet solo sul primo; merchant B senza wallet
    $hash = password_hash('fixture-password-only', PASSWORD_BCRYPT);
    $level = fn(int $l) => (int) scalar('SELECT id FROM privileges WHERE level=:l ORDER BY id LIMIT 1', [':l' => $l]);
    $user = function (string $name, int $privilegeLevel) use ($db, $level, $hash): int {
        $db->createCommand()->insert('users', ['username' => $name, 'email' => $name . '@example.invalid', 'password' => $hash, 'first_name' => 'TT', 'last_name' => $name,
            'oauth_provider' => 'fixture', 'oauth_uid' => $name, 'authKey' => 'fixture-' . $name, 'accessToken' => 'fixture-' . $name, 'picture' => '/favicon.ico',
            'privilege_id' => $level($privilegeLevel), 'is_merchant' => 0, 'is_active' => 1])->execute();
        return (int) $db->lastInsertID;
    };
    $base = ['email' => 'fixture@example.invalid', 'phone' => '', 'mobile' => '', 'addressStreet' => '', 'addressNumberHouse' => '', 'addressCity' => '', 'addressZip' => '', 'addressProvince' => '', 'addressCountry' => ''];
    $now = time(); $on = ['is_enabled' => 1, 'created_at' => $now, 'updated_at' => $now];
    $managerA = (int) scalar("SELECT id FROM users WHERE username='runtime-merchant'");
    $merchantA = (int) scalar("SELECT id FROM merchants WHERE description='Runtime company merchant'");
    $db->createCommand()->insert('member_profiles', ['user_id' => $managerA, 'is_corporate' => 1, 'denomination' => 'TTS PG A'])->execute();
    $managerB = $user('tt-manager-b', 30);
    $db->createCommand()->insert('member_profiles', ['user_id' => $managerB, 'is_corporate' => 1, 'denomination' => 'TTS PG B'])->execute();
    $db->createCommand()->insert('merchants', ['user_id' => $managerB, 'description' => 'TTS merchant B', 'vatNumber' => '12345678901'] + $base)->execute();
    $merchantB = (int) $db->lastInsertID;
    $store = function (int $merchantId, string $name) use ($db, $base): int {
        $db->createCommand()->insert('stores', ['merchant_id' => $merchantId, 'description' => $name] + $base)->execute();
        $id = (int) $db->lastInsertID;
        // come la creazione dall'app: impostazioni del negozio senza collegamento BTCPay
        $db->createCommand()->insert('storesettings', ['store_id' => $id, 'defaultCurrency' => 'EUR', 'speedPolicy' => 'MediumSpeed', 'requiresRefundEmail' => 1,
            'receipt' => json_encode(['enabled' => 1, 'showQR' => 1, 'showPayments' => 1])])->execute();
        return $id;
    };
    $storeA = $store($merchantA, 'TTS A1'); $storeA2 = $store($merchantA, 'TTS A2'); $storeB = $store($merchantB, 'TTS B1');
    $opU = $user('tt-op-u', 0); $opB = $user('tt-op-b', 0);
    foreach ([[$opU, $merchantA, [$storeA, $storeA2]], [$opB, $merchantB, [$storeB]]] as [$u, $m, $stores]) {
        $db->createCommand()->insert('pos_operators', ['user_id' => $u, 'merchant_id' => $m] + $on)->execute();
        foreach ($stores as $s) $db->createCommand()->insert('pos_store_operators', ['user_id' => $u, 'merchant_id' => $m, 'store_id' => $s] + $on)->execute();
    }
    $terminal = function (int $merchantId, int $storeId, string $name) use ($db, $now): string {
        $id = uuid();
        $db->createCommand()->insert('pos_terminals', ['public_id' => $id, 'merchant_id' => $merchantId, 'store_id' => $storeId, 'name' => $name, 'is_enabled' => 1, 'created_at' => $now, 'updated_at' => $now])->execute();
        return $id;
    };
    $tA = $terminal($merchantA, $storeA, 'Cassa TTS A'); $tA2 = $terminal($merchantA, $storeA2, 'Cassa TTS A2'); $tB = $terminal($merchantB, $storeB, 'Cassa TTS B');
    check((int) scalar('SELECT COUNT(*) FROM pos_tts_payments') === 0 && (int) scalar('SELECT COUNT(*) FROM store_tts_wallets') === 0, 'Schema present, no payments or wallets provisioned');
    check($db->getTableSchema('merchant_tts_wallets', true) === null, 'Merchant-level wallet table removed by the migration');

    // --- Backoffice: wallet TTS per negozio (gestore sui negozi del proprio merchant)
    $jar = tempnam(sys_get_temp_dir(), 'napay-tts-');
    $foreign = Crypt::encrypt($storeA); // cifrato fuori dalla sessione del browser: non valido per nessuna pagina
    check(web(walletUrl($foreign))['status'] === 302, 'Anonymous visitor redirected to login');
    webLogin('tt-op-u@example.invalid');
    check(web(walletUrl($foreign))['status'] === 403 && web(walletUrl(null))['status'] === 403, 'Operator cannot manage store wallets');
    webLogin('tt-manager-b@example.invalid');
    check(web(walletUrl($foreign))['status'] === 404 && web(walletUrl('not-encrypted'))['status'] === 404 && !str_contains(web(walletUrl(null))['body'], 'TTS A1')
        && storeWallet($storeA) === false, 'Manager B: stores of merchant A not listed, foreign or malformed store key 404');
    check(saveWallet(strtolower(TtsAddress::normalize('0x' . bin2hex(random_bytes(20)))))['status'] === 302 && storeWallet($storeB) !== false
        && $db->createCommand()->delete('store_tts_wallets', ['store_id' => $storeB])->execute() === 1, 'Single store opened by default: wallet stored on store B (removed again for the no-wallet case)');
    webLogin('merchant@example.invalid');
    $choose = web(walletUrl(null));
    check($choose['status'] === 200 && str_contains($choose['body'], 'name="store"') && str_contains($choose['body'], 'TTS A2') && !str_contains($choose['body'], 'TTS B1')
        && !str_contains($choose['body'], 'TtsWalletForm[address]'), 'Manager with two stores chooses the store first, only own stores listed');
    $keyA = storeKey('TTS A1');
    check(web(walletUrl($keyA))['status'] === 200, 'Manager opens the TTS wallet of a store');
    $wallet = TtsAddress::normalize('0x' . bin2hex(random_bytes(20)));
    $badCase = $wallet; $pos = strcspn(substr($badCase, 2), 'abcdefABCDEF') + 2; $badCase[$pos] = ctype_upper($badCase[$pos]) ? strtolower($badCase[$pos]) : strtoupper($badCase[$pos]);
    foreach (['not-an-address' => 'malformed', $badCase => 'checksum mismatch', '0x' . str_repeat('0', 40) => 'zero address'] as $bad => $label) {
        $r = saveWallet($bad, $keyA);
        check($r['status'] === 200 && (int) scalar('SELECT COUNT(*) FROM store_tts_wallets') === 0, 'Wallet refused: ' . $label);
    }
    $r = saveWallet(strtolower($wallet), $keyA);
    check($r['status'] === 302 && storeWallet($storeA) === $wallet && storeWallet($storeA2) === false
        && (int) scalar('SELECT updated_by FROM store_tts_wallets WHERE store_id=:s', [':s' => $storeA]) === $managerA, 'Lowercase address stored in EIP-55 form on that store only, with author');
    $page = web(walletUrl($keyA))['body'];
    check(str_contains($page, $wallet) && str_contains($page, 'data-copy-target') && !str_contains($page, 'TtsWalletForm[address]')
        && str_contains($page, '/store-wallet/delete'), 'Saved address shown read-only with copy, edit and delete');
    check(str_contains(web(walletUrl($keyA) . '&edit=1')['body'], 'TtsWalletForm[address]'), 'Edit shows the form again');
    check(logged('Wallet TTS del negozio ' . $storeA) === 1, 'Wallet change audited');
    $storePage = web('/stores/view?id=' . urlencode($keyA))['body'];
    check(str_contains($storePage, 'data-store-tts-wallet') && str_contains($storePage, $wallet) && str_contains($storePage, '/store-wallet/index?store='), 'Store page shows its TTS wallet with the manage link');

    // --- API: sessioni e aperture
    $u = apiLogin('tt-op-u@example.invalid'); $u2 = apiLogin('tt-op-u@example.invalid'); $b = apiLogin('tt-op-b@example.invalid');
    fresh();
    $open = fn(string $token, string $terminalId) => api('POST', 'terminals/' . $terminalId . '/openings', $token, ['instance_id' => uuid()], uuid())['json'];
    $oA = $open($u, $tA); $oB = $open($b, $tB); $oA2 = $open($u2, $tA2);
    check(($oA['state'] ?? null) === 'active' && ($oB['state'] ?? null) === 'active' && ($oA2['state'] ?? null) === 'active', 'Terminals opened for both merchants');
    $r = pay($b, $oB, 1000);
    check($r['status'] === 503 && code($r) === 'payment_unavailable' && noLeak($r), 'Store without wallet: 503 payment_unavailable');
    $r = pay($u2, $oA2, 1000);
    check($r['status'] === 503 && code($r) === 'payment_unavailable', 'Second store of the same merchant without wallet: 503 (the other store wallet is not used)');

    // --- Validazione: nulla viene creato
    foreach ([0, -5, 100001, '1000', 10.5, null] as $bad) {
        $r = api('POST', 'openings/' . $oA['id'] . '/payments', $u, ['instance_id' => $oA['instance_id'], 'generation' => $oA['generation'], 'amount_cents' => $bad], uuid());
        check($r['status'] === 400 && code($r) === 'validation_failed', 'Invalid amount refused: ' . json_encode($bad));
    }
    check(api('POST', 'openings/' . $oA['id'] . '/payments', $u, ['instance_id' => $oA['instance_id'], 'generation' => $oA['generation'], 'amount_cents' => 100])['status'] === 400, 'Missing Idempotency-Key refused');
    check(code(api('POST', 'openings/' . $oA['id'] . '/payments', $u, ['instance_id' => $oA['instance_id'], 'generation' => $oA['generation'], 'amount_cents' => 100, 'to' => $wallet], uuid())) === 'validation_failed', 'Client cannot choose the destination');
    check(code(pay($u, ['id' => $oA['id'], 'instance_id' => $oA['instance_id'], 'generation' => '99'], 100)) === 'opening_invalid', 'Wrong generation: 409 opening_invalid');
    check(code(pay($u2, $oA, 100)) === 'not_found', 'Opening of another session: 404');
    check(code(pay($b, $oA, 100)) === 'not_found', 'Opening of another merchant: 404');
    check(code(api('POST', 'openings/' . $oA['id'] . '/payments', null, ['amount_cents' => 1], uuid())) === 'invalid_token', 'Bearer required');
    check((int) scalar('SELECT COUNT(*) FROM pos_tts_payments') === 0, 'Refused requests created nothing');

    // --- Creazione, replay, proiezione
    $idem = uuid();
    $r = pay($u, $oA, 1234, $idem);
    $p = $r['json'];
    check($r['status'] === 201 && array_keys($p) === ['id', 'opening_id', 'amount', 'currency', 'state', 'to_address', 'expires_at', 'qr', 'tx_hash', 'paid_at']
        && $p['amount'] === '12.34' && $p['currency'] === 'TTS' && $p['state'] === 'pending' && $p['to_address'] === $wallet && $p['opening_id'] === $oA['id']
        && $p['tx_hash'] === null && noLeak($r), 'Payment created: 201, exact projection, destination resolved by backend');
    $row = payment($p['id']);
    check($p['qr'] === $wallet . '&amount=12.34&message=napay:' . $row['reference'] && preg_match('/^[0-9a-f]{32}$/', $row['reference']) === 1
        && (int) $row['expires_at'] - (int) $row['created_at'] === 900 && (int) $row['start_block'] > 0, 'QR in wallet format with random reference, 15 minutes validity, start block recorded');
    check(pay($u, $oA, 1234, $idem)['json'] === $p && pay($u, $oA, 1234, $idem)['status'] === 200, 'Idempotent replay returns the same payment');
    check(code(pay($u, $oA, 999, $idem)) === 'idempotency_conflict', 'Same key with different amount: 409');
    $view = api('GET', 'payments/' . $p['id'], $u);
    check($view['status'] === 200 && $view['json']['state'] === 'pending' && noLeak($view), 'Unpaid payment stays pending');
    check(code(api('GET', 'payments/' . $p['id'], $u2)) === 'not_found' && code(api('GET', 'payments/' . $p['id'], $b)) === 'not_found', 'Payment of another session is 404');
    check(code(api('GET', 'payments/not-a-uuid', $u)) === 'not_found' && api('DELETE', 'payments/' . $p['id'], $u)['status'] === 405, 'Malformed id 404, other methods 405');

    $created = ['ok' => $p];
    foreach (['ok2' => 1234, 'wrong_amount' => 500, 'no_ref' => 700, 'late' => 800, 'other_to' => 900, 'cancel' => 100] as $label => $cents) {
        $r = pay($u, $oA, $cents);
        check($r['status'] === 201, 'Payment created: ' . $label);
        $created[$label] = $r['json'];
    }
    $proof = ['instance_id' => $oA['instance_id'], 'generation' => $oA['generation']];
    check(code(api('POST', 'payments/' . $created['cancel']['id'] . '/cancel', $u, ['instance_id' => $oA['instance_id'], 'generation' => '7'])) === 'opening_invalid', 'Cancel needs the opening proof');
    $r = api('POST', 'payments/' . $created['cancel']['id'] . '/cancel', $u, $proof);
    check($r['status'] === 200 && $r['json']['state'] === 'cancelled' && payment($created['cancel']['id'])['closed_at'] !== null, 'Pending payment cancelled by its opening');
    // --- Storico del terminale: più recenti prima, mai QR, riferimento o destinatario
    $r = api('GET', 'openings/' . $oA['id'] . '/payments', $u);
    $list = $r['json']['payments'] ?? [];
    check($r['status'] === 200 && array_keys($r['json']) === ['payments'] && count($list) === 7 && array_column($list, 'id') === array_reverse(array_column($created, 'id'))
        && array_keys($list[0]) === ['id', 'amount', 'currency', 'state', 'created_at', 'paid_at', 'tx_hash', 'own'] && noLeak($r), 'History: seven payments of the terminal, newest first, exact projection');
    check($list[0]['state'] === 'cancelled' && $list[6]['amount'] === '12.34' && $list[6]['own'] === true && !str_contains($r['body'], payment($created['ok']['id'])['reference']) && !str_contains($r['body'], $wallet),
        'History items: state and amount, own flag, no reference or destination');
    check(code(api('GET', 'openings/' . $oA['id'] . '/payments', $b)) === 'not_found' && code(api('GET', 'openings/' . $oA['id'] . '/payments', $u2)) === 'not_found',
        'History of an opening of another session or merchant: 404');
    check(code(api('GET', 'openings/' . $oA['id'] . '/payments?terminal=1', $u)) === 'validation_failed' && code(api('GET', 'openings/not-a-uuid/payments', $u)) === 'not_found',
        'History: no extra parameters, malformed opening 404');
    // "late": la richiesta risulta già scaduta quando arriva il transfer
    $db->createCommand('UPDATE pos_tts_payments SET created_at=created_at-1000, expires_at=created_at+1 WHERE public_id=:p', [':p' => $created['late']['id']])->execute();
    $other = TtsAddress::normalize('0x' . bin2hex(random_bytes(20)));
    $ref = fn(string $label) => payment($created[$label]['id'])['reference'];
    $plan = [
        ['label' => 'ok', 'to' => $wallet, 'cents' => 1234, 'ref' => $ref('ok')],
        ['label' => 'ok2', 'to' => $wallet, 'cents' => 1234, 'ref' => $ref('ok2')],
        ['label' => 'wrong_amount', 'to' => $wallet, 'cents' => 501, 'ref' => $ref('wrong_amount')],
        ['label' => 'no_ref', 'to' => $wallet, 'cents' => 700, 'ref' => null],
        ['label' => 'late', 'to' => $wallet, 'cents' => 800, 'ref' => $ref('late')],
        ['label' => 'other_to', 'to' => $other, 'cents' => 900, 'ref' => $ref('other_to')],
        ['label' => 'cancel', 'to' => $wallet, 'cents' => 100, 'ref' => $ref('cancel')],
    ];
    file_put_contents($stateFile, json_encode(['u' => $u, 'manager' => 'merchant@example.invalid', 'terminal' => $tA, 'opening' => $oA, 'payments' => $created, 'plan' => $plan, 'wallet' => $wallet, 'store' => $storeA]));
    file_put_contents('/tmp/napay-tts-plan.json', json_encode($plan));
    printf("PASS %d TTS payment setup checks\n", $checks);
    exit(0);
}

$state = json_decode((string) file_get_contents($stateFile), true);
[$u, $p, $oA] = [$state['u'], $state['payments'], $state['opening']];
$secrets[] = $u;
$view = fn(string $label) => api('GET', 'payments/' . $p[$label]['id'], $u);

if ($phase === 'verify') {
    $paid = json_decode((string) file_get_contents('/tmp/napay-tts-paid.json'), true);
    $tx = array_column($paid, 'tx', 'label');
    check(count($tx) === 7, 'Seven real transfers mined on the disposable chain');
    fresh();
    $r = $view('ok');
    check($r['status'] === 200 && $r['json']['state'] === 'paid' && $r['json']['tx_hash'] === $tx['ok'] && is_int($r['json']['paid_at']) && noLeak($r), 'Exact transfer with reference: paid, tx hash recorded');
    $row = payment($p['ok']['id']);
    check((int) $row['paid_block'] >= (int) $row['start_block'] && (int) $row['paid_at'] <= (int) $row['expires_at'], 'Paid block after start block and before expiry');
    check($view('ok2')['json']['tx_hash'] === $tx['ok2'], 'Same amount, same merchant: each payment matched by its own reference');
    check($view('wrong_amount')['json']['state'] === 'pending' && logged('transazione ' . $tx['wrong_amount'] . ' con riferimento ma importo') >= 1, 'Wrong amount with reference: not paid, anomaly logged');
    check($view('no_ref')['json']['state'] === 'pending', 'Exact amount without reference: not paid');
    $r = $view('late');
    check($r['json']['state'] === 'expired' && $r['json']['tx_hash'] === null && logged('transazione ' . $tx['late'] . ' arrivata dopo la scadenza') >= 1, 'Transfer after expiry: expired, anomaly logged');
    check($view('other_to')['json']['state'] === 'pending', 'Reference sent to another address: not paid');
    check($view('cancel')['json']['state'] === 'cancelled', 'Cancelled payment stays cancelled even if paid later');
    $r = api('POST', 'payments/' . $p['ok']['id'] . '/cancel', $u, ['instance_id' => $oA['instance_id'], 'generation' => $oA['generation']]);
    check($r['status'] === 409 && code($r) === 'payment_not_pending', 'Paid payment cannot be cancelled');
    try {
        $db->createCommand('UPDATE pos_tts_payments SET tx_hash=:t WHERE public_id=:p', [':t' => $tx['ok'], ':p' => $p['ok2']['id']])->execute();
        check(false, 'One transaction pays at most one request');
    } catch (yii\db\IntegrityException $e) { check(true, 'One transaction pays at most one request'); }
    // manager sblocca il terminale: nuove richieste rifiutate, le esistenti restano leggibili
    webLogin($state['manager']);
    $page = web('/pos-management/index');
    web('/pos-management/terminal-unlock?id=' . $state['terminal'], ['_csrf' => csrf($page)]);
    $r = pay($u, $oA, 300);
    check($r['status'] === 409 && code($r) === 'opening_invalid', 'Revoked opening cannot create payments');
    check($view('ok')['json']['state'] === 'paid', 'Existing payments stay readable after revocation');
    check(code(api('GET', 'openings/' . $oA['id'] . '/payments', $u)) === 'opening_invalid', 'History needs a usable opening');
    $u2 = apiLogin('tt-op-u@example.invalid');
    $o2 = api('POST', 'terminals/' . $state['terminal'] . '/openings', $u2, ['instance_id' => uuid()], uuid())['json'];
    $r = api('GET', 'openings/' . $o2['id'] . '/payments', $u2);
    $byId = array_column($r['json']['payments'] ?? [], null, 'id');
    check($r['status'] === 200 && count($byId) === 7 && $byId[$p['ok']['id']]['state'] === 'paid' && $byId[$p['ok']['id']]['tx_hash'] === $tx['ok']
        && $byId[$p['late']['id']]['state'] === 'expired' && $byId[$p['ok']['id']]['own'] === false, 'Next opening of the terminal sees its history, not owned');
    $db->createCommand("UPDATE pos_tts_payments SET created_at=created_at-2000, expires_at=created_at+10 WHERE public_id IN (:a,:b)", [':a' => $p['wrong_amount']['id'], ':b' => $p['no_ref']['id']])->execute();
    check((int) scalar("SELECT COUNT(*) FROM pos_tts_payments WHERE state='pending' AND expires_at < UNIX_TIMESTAMP()") === 2, 'Two pending payments left past expiry for the one-shot sweep');
    // --- pagina Incassi del backoffice (gestore A loggato)
    $list = web('/pos-payments/index');
    $ids = array_column($p, 'id');
    $shown = array_filter($ids, static fn($id) => str_contains($list['body'], '/pos-payments/view?id=' . $id));
    $refs = array_map(static fn($id) => payment($id)['reference'], $ids);
    check($list['status'] === 200 && count($shown) === count($ids) && str_contains($list['body'], 'Cassa TTS A') && str_contains($list['body'], '12,34 TTS')
        && !array_filter($refs, static fn($ref) => str_contains($list['body'], $ref)), 'Payments page lists the merchant payments, never the reference');
    $paidOnly = web('/pos-payments/index?state=paid')['body'];
    check(substr_count($paidOnly, '/pos-payments/view?id=') === (int) scalar("SELECT COUNT(*) FROM pos_tts_payments WHERE state='paid'") && str_contains($paidOnly, '/pos-payments/view?id=' . $p['ok']['id'])
        && !str_contains($paidOnly, '/pos-payments/view?id=' . $p['cancel']['id']), 'Status filter');
    check(substr_count(web('/pos-payments/index?from=2000-01-01&to=2000-01-02')['body'], '/pos-payments/view?id=') === 0
        && substr_count(web('/pos-payments/index?from=bad&state=nope')['body'], '/pos-payments/view?id=') === count($ids), 'Date filter; invalid filters ignored');
    $detail = web('/pos-payments/view?id=' . $p['ok']['id']);
    check($detail['status'] === 200 && str_contains($detail['body'], $tx['ok']) && str_contains($detail['body'], $state['wallet']) && !str_contains($detail['body'], payment($p['ok']['id'])['reference']),
        'Payment detail with transaction and destination wallet');
    $csv = web('/pos-payments/export?state=paid');
    check($csv['status'] === 200 && str_contains(strtolower($csv['headers']), 'text/csv') && str_starts_with($csv['body'], "\xEF\xBB\xBF")
        && count(array_filter(explode("\n", trim($csv['body'])))) === 1 + (int) scalar("SELECT COUNT(*) FROM pos_tts_payments WHERE state='paid'")
        && str_contains($csv['body'], $tx['ok']) && !array_filter($refs, static fn($ref) => str_contains($csv['body'], $ref)), 'CSV export with the same filters, no reference');
    $paidCents = (int) scalar("SELECT SUM(amount_cents) FROM pos_tts_payments WHERE state='paid'");
    $euro = static fn(int $cents) => number_format($cents / 100, 2, ',', '.') . ' TTS';
    check(preg_match('~data-total-paid>' . preg_quote($euro($paidCents), '~') . '<~', $list['body']) === 1
        && preg_match('~data-total-paid>' . preg_quote($euro(0), '~') . '<~', web('/pos-payments/index?state=pending')['body']) === 1, 'Payments page totals follow the filters');
    // dashboard: incassato oggi e saldo reale del wallet sulla chain (trasferimenti del test verso il wallet)
    $toWallet = array_sum(array_map(static fn($t) => $t['to'] === $state['wallet'] ? $t['cents'] : 0, $state['plan']));
    $home = web('/site/index')['body'];
    check(preg_match('~data-dashboard="today"[\s\S]*?' . preg_quote($euro($paidCents), '~') . '~', $home) === 1
        && preg_match('~data-dashboard="balance"[\s\S]*?' . preg_quote($euro($toWallet), '~') . '~', $home) === 1, 'Dashboard: collected today and on-chain wallet balance kept separate');
    $sidebar = preg_match('~<aside class="navbar navbar-vertical[\s\S]*?</aside>~', $home, $aside) === 1 ? $aside[0] : '';
    check(!str_contains($home, 'data-napay-tts-wallet-link') && preg_match('~data-dashboard="balance" ?[^>]*~', $home) === 1 && str_contains($home, '/store-wallet/index')
        && $sidebar !== '' && str_contains($sidebar, '/pos-payments/index'), 'TTS wallet no longer in the profile menu: balance tile links to the store wallets, payments in the sidebar');
    webLogin('tt-op-u@example.invalid');
    check(web('/pos-payments/index')['status'] === 403, 'Operator cannot open the payments page');
    webLogin('tt-manager-b@example.invalid');
    check(substr_count(web('/pos-payments/index')['body'], '/pos-payments/view?id=') === 0 && web('/pos-payments/view?id=' . $p['ok']['id'])['status'] === 404
        && web('/pos-payments/view?id=not-a-uuid')['status'] === 404, 'Other merchant sees none of these payments (404 on detail)');
    webLogin($state['manager']);

    // rimozione del wallet: nuove richieste rifiutate, quelle create tengono l'indirizzo congelato
    $keyA = storeKey('TTS A1');
    $page = web(walletUrl($keyA));
    check(web(walletUrl($keyA, 'delete'), [])['status'] === 400 && web(walletUrl($keyA, 'delete'))['status'] === 405, 'Wallet removal needs POST with CSRF');
    $r = web(walletUrl($keyA, 'delete'), ['_csrf' => csrf($page)]);
    check($r['status'] === 302 && (int) scalar('SELECT COUNT(*) FROM store_tts_wallets') === 0 && logged('Wallet TTS del negozio ' . $state['store'] . ' rimosso (era ' . $state['wallet']) === 1
        && payment($p['ok']['id'])['to_address'] === $state['wallet'], 'Manager removes the wallet: audited, existing payments keep their address');
    check(str_contains(web(walletUrl($keyA))['body'], 'TtsWalletForm[address]'), 'Without a wallet the page shows the form');
    printf("PASS %d TTS payment verify checks\n", $checks);
    exit(0);
}

// offline: chain spenta dal runner dopo il comando one-shot
fresh();
check($view('wrong_amount')['json']['state'] === 'expired' && $view('no_ref')['json']['state'] === 'expired', 'One-shot sweep expired the unpaid payments');
$r = $view('other_to');
check($r['status'] === 200 && $r['json']['state'] === 'pending', 'Chain unreachable: pending payment unchanged, never expired by guess');
$r = api('POST', 'payments/' . $p['other_to']['id'] . '/cancel', $u, ['instance_id' => $oA['instance_id'], 'generation' => $oA['generation']]);
check($r['status'] === 503 && code($r) === 'payment_unavailable' && payment($p['other_to']['id'])['state'] === 'pending', 'Chain unreachable: cancel refused, no blind cancellation');
printf("PASS %d TTS payment offline checks\n", $checks);
