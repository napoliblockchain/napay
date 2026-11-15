<?php
// SIP-022 B1 W2: passi remoti (tabelle 3.1/3.2) con client HTTP finto. Test puri: nessun DB, rete o file.
// Ogni caso conta le chiamate: dopo un esito incerto nessun POST di creazione.
if (PHP_SAPI !== 'cli') exit(1);
ini_set('zend.exception_ignore_args', '0');
$root = dirname(__DIR__, 2);
foreach (['ProvisioningHttpException', 'steps/StepOutcome', 'steps/MerchantAccountStep', 'steps/StoreLinkStep', 'steps/PosLinkStep'] as $file) {
    require $root . '/components/btcpay/provisioning/' . $file . '.php';
}
use app\components\btcpay\provisioning\ProvisioningHttpException as Http;
use app\components\btcpay\provisioning\steps\{MerchantAccountStep, PosLinkStep, StepOutcome as O, StoreLinkStep};

$checks = 0;
function check($condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException('FAIL: ' . $label); ++$checks; echo 'PASS: ' . $label . "\n"; }
/** Client finto: coda di risposte (array) o eccezioni; registra metodo, percorso, corpo e credenziale. */
final class FakeClient
{
    public array $calls = []; public array $queue = [];
    public function key(): Closure { return function (string $method, string $path, ?array $body, string $key) { $this->calls[] = [$method, $path, $body, 'key:' . $key]; return $this->next(); }; }
    public function basic(): Closure { return function (string $method, string $path, ?array $body, string $user, string $password) { $this->calls[] = [$method, $path, $body, 'basic:' . $user . ':' . $password]; return $this->next(); }; }
    private function next(): array { $reply = array_shift($this->queue) ?? throw new RuntimeException('Unexpected call'); if ($reply instanceof Throwable) throw $reply; return $reply; }
    public function reset(array $queue): void { $this->calls = []; $this->queue = $queue; }
}
$r = static fn(int $status, $json = null, ?string $retry = null): array => ['status' => $status, 'retry_after' => $retry, 'json' => $json];
$unknown = new Http(Http::OUTCOME_UNKNOWN); $notSent = new Http(Http::INVALID_REQUEST);
$is = static fn(O $o, string $kind, ?string $code = null, array $data = []): bool => $o->kind === $kind && $o->code === $code && $o->data === $data;
$http = new FakeClient();
$account = new MerchantAccountStep($http->key(), $http->basic());
$ref = str_repeat('a', 32); $email = 'm-' . $ref . '@btcpay-merchants.jamprojects.it';
$pw = 'pw-' . bin2hex(random_bytes(12)); $prov = 'prov-' . bin2hex(random_bytes(12)); $newKey = 'key' . bin2hex(random_bytes(16));

// --- A: creazione utente (chiave provisioner)
foreach ([
    'created 201' => [[$r(201, ['id' => 'user-1', 'email' => $email])], O::DONE, null, ['remote_user_id' => 'user-1']],
    'created 200' => [[$r(200, ['id' => 'user-1'])], O::DONE, null, ['remote_user_id' => 'user-1']],
    'malformed success' => [[$r(201, ['id' => 'bad id!'])], O::RECONCILE, 'user_response_malformed', []],
    'duplicate e-mail 422' => [[$r(422, ['code' => 'x'])], O::RECONCILE, 'user_exists', []],
    'rate limited 429' => [[$r(429, null, '30')], O::RETRY, 'rate_limited', []],
    'unauthorized 401' => [[$r(401)], O::FAILED, 'provisioner_denied', []],
    'forbidden 403' => [[$r(403)], O::FAILED, 'provisioner_denied', []],
    'server error 500' => [[$r(500)], O::RECONCILE, 'user_outcome_unknown', []],
    'timeout / unknown' => [[$unknown], O::RECONCILE, 'user_outcome_unknown', []],
    'request not sent' => [[$notSent], O::FAILED, 'request_not_sent', []],
    'bad request 400' => [[$r(400)], O::FAILED, 'user_rejected', []],
] as $label => [$queue, $kind, $code, $data]) {
    $http->reset($queue);
    $o = $account->createUser($email, $pw, $prov);
    check($is($o, $kind, $code, $data) && count($http->calls) === 1, "A createUser $label -> $kind");
}
check($http->calls[0][0] === 'POST' && $http->calls[0][1] === '/api/v1/users' && $http->calls[0][2] === ['email' => $email, 'password' => $pw, 'isAdministrator' => false]
    && $http->calls[0][3] === 'key:' . $prov, 'A: POST /users with provisioner key, password from storage, never admin');
$http->reset([$r(429, null, 'Sun, 06 Nov 2044 08:49:37 GMT')]);
check($account->createUser($email, $pw, $prov)->retryAfter === 'Sun, 06 Nov 2044 08:49:37 GMT', '429 keeps the raw Retry-After for RetryPolicy');

// --- A-r: ricerca per email tecnica
foreach ([
    'found 200' => [[$r(200, ['id' => 'user-1', 'email' => $email])], O::FOUND, null, ['remote_user_id' => 'user-1']],
    'other e-mail in body' => [[$r(200, ['id' => 'user-1', 'email' => 'x@example.invalid'])], O::RECONCILE, 'user_lookup_malformed', []],
    'absent 404' => [[$r(404)], O::ABSENT, null, []],
    '429' => [[$r(429, null, '5')], O::RETRY, 'rate_limited', []],
    '403' => [[$r(403)], O::FAILED, 'provisioner_denied', []],
    '500' => [[$r(500)], O::RETRY, 'lookup_unavailable', []],
    'unknown' => [[$unknown], O::RETRY, 'lookup_unavailable', []],
] as $label => [$queue, $kind, $code, $data]) {
    $http->reset($queue);
    check($is($account->findUser($email, $prov), $kind, $code, $data) && count($http->calls) === 1 && $http->calls[0][0] === 'GET', "A-r findUser $label -> $kind");
}
check($http->calls[0][1] === '/api/v1/users/' . $email, 'A-r: GET by technical e-mail; never a reset or update');

// --- B: chiave propria (Basic con la password salvata), limite di generazioni
$perm = ['btcpay.store.canmodifystoresettings'];
$http->reset([$r(200, ['apiKey' => $newKey, 'label' => 'x', 'permissions' => $perm])]);
$o = $account->createKey($email, $pw, $ref, 2);
check($o->kind === O::DONE && $o->data === ['generation' => 2] && $o->secret() === $newKey, 'B 200 -> key returned as a secret, with its generation');
check($http->calls[0][0] === 'POST' && $http->calls[0][1] === '/api/v1/api-keys' && $http->calls[0][3] === 'basic:' . $email . ':' . $pw
    && $http->calls[0][2] === ['label' => 'napay-' . $ref . '-g2', 'permissions' => ['btcpay.store.canmodifystoresettings']], 'B: Basic with stored password, label napay-<ref>-g<n>, store permission only');
$dump = print_r($o, true) . var_export($o, true) . json_encode($o);
ob_start(); var_dump($o); $dump .= ob_get_clean();
check(!str_contains($dump, $newKey), 'Returned key invisible to print_r/var_dump/var_export/json');
foreach ([
    'malformed key' => [[$r(200, ['apiKey' => 'short', 'permissions' => $perm])], O::REPEAT, 'key_response_malformed'],
    'extra global permission' => [[$r(200, ['apiKey' => $newKey, 'permissions' => array_merge($perm, ['btcpay.server.canmodifyserversettings'])])], O::RECONCILE, 'key_permissions_unexpected'],
    'unrestricted key' => [[$r(200, ['apiKey' => $newKey, 'permissions' => ['unrestricted']])], O::RECONCILE, 'key_permissions_unexpected'],
    'permissions missing' => [[$r(200, ['apiKey' => $newKey])], O::RECONCILE, 'key_permissions_unexpected'],
    'no key field' => [[$r(200, [])], O::REPEAT, 'key_response_malformed'],
    'password rejected 401' => [[$r(401)], O::RECONCILE, 'password_rejected'],
    '429 deferred (B1 3.1)' => [[$r(429, null, '10')], O::DEFERRED, 'rate_limited'],
    '403' => [[$r(403)], O::FAILED, 'key_forbidden'],
    '500' => [[$r(500)], O::REPEAT, 'key_outcome_unknown'],
    'unknown' => [[$unknown], O::REPEAT, 'key_outcome_unknown'],
    '400' => [[$r(400)], O::FAILED, 'key_rejected'],
] as $label => [$queue, $kind, $code]) {
    $http->reset($queue);
    $o = $account->createKey($email, $pw, $ref, 1);
    check($is($o, $kind, $code) && $o->secret() === null && count($http->calls) === 1, "B createKey $label -> $kind");
}
$http->reset([]);
check($is($account->createKey($email, $pw, $ref, 4), O::RECONCILE, 'generations_exhausted') && $http->calls === [], 'B: beyond 3 generations no call, reconciliation');

// --- C: verifica della nuova chiave
foreach ([
    'stores listed' => [[$r(200, [['id' => 's1']])], O::DONE, null],
    'empty list' => [[$r(200, [])], O::DONE, null],
    'not a list' => [[$r(200, ['id' => 'x'])], O::RETRY, 'verify_response_malformed'],
    'list of strings' => [[$r(200, ['junk'])], O::RETRY, 'verify_response_malformed'],
    'object without valid id' => [[$r(200, [['id' => 'bad id']])], O::RETRY, 'verify_response_malformed'],
    'key not accepted 401' => [[$r(401)], O::RECONCILE, 'key_not_accepted'],
    '429' => [[$r(429)], O::RETRY, 'rate_limited'],
    'unknown' => [[$unknown], O::RETRY, 'verify_unavailable'],
] as $label => [$queue, $kind, $code]) {
    $http->reset($queue);
    check($is($account->verifyKey($newKey), $kind, $code) && $http->calls[0][0] === 'GET' && $http->calls[0][3] === 'key:' . $newKey, "C verifyKey $label -> $kind");
}

// --- Store: un solo POST, poi solo riconciliazione
$stores = new StoreLinkStep($http->key());
$marker = str_repeat('b', 32);
$snap = ['state' => 'pending', 'marker' => $marker, 'remote_store_id' => null, 'in_flight' => false, 'account_state' => 'synced', 'store_name' => "Negozio\nRoma"];
$name = 'Negozio Roma [napay-' . $marker . ']';
$http->reset([]);
check($is($stores->create(array_replace($snap, ['account_state' => 'key_saved']), $newKey), O::PENDING, 'account_not_ready') && $is($stores->create($snap, null), O::PENDING, 'account_not_ready') && $http->calls === [], 'Store: account not ready -> pending, no call');
check($is($stores->create(array_replace($snap, ['state' => 'synced', 'remote_store_id' => 'st-1']), $newKey), O::DONE, null, ['remote_store_id' => 'st-1']) && $http->calls === [], 'Store already synced: no call');
foreach (['suspended', 'failed'] as $state) check($is($stores->create(array_replace($snap, ['state' => $state, 'remote_store_id' => 'st-old']), $newKey), O::FAILED, 'link_' . $state) && $http->calls === [], "Store $state with an old ID is not done");
foreach ([['state' => 'weird'], ['state' => 'synced'], ['state' => 'synced', 'remote_store_id' => ''], ['state' => 'synced', 'remote_store_id' => '../x']] as $bad) {
    $refused = false; try { $stores->create(array_replace($snap, $bad), $newKey); } catch (InvalidArgumentException $e) { $refused = true; }
    check($refused && $http->calls === [], 'Store snapshot refused: ' . json_encode($bad));
}
$http->reset([$r(200, [['id' => 'st-1', 'name' => 'Negozio Roma [napay-' . $marker . ']']])]);
check($is($stores->create(array_replace($snap, ['remote_store_id' => 'st-1']), $newKey), O::DONE, null, ['remote_store_id' => 'st-1']) && count($http->calls) === 1 && $http->calls[0][0] === 'GET', 'Pending with a remote ID: only GET, never POST');
foreach ([
    'created' => [[$r(200, ['id' => 'st-1', 'name' => $name])], O::DONE, null, ['remote_store_id' => 'st-1']],
    'malformed' => [[$r(200, ['name' => $name])], O::RECONCILE, 'store_response_malformed', []],
    '429' => [[$r(429, null, '9')], O::RETRY, 'rate_limited', []],
    '403' => [[$r(403)], O::FAILED, 'merchant_key_denied', []],
    '500' => [[$r(500)], O::RECONCILE, 'store_outcome_unknown', []],
    'unknown' => [[$unknown], O::RECONCILE, 'store_outcome_unknown', []],
    '422' => [[$r(422)], O::FAILED, 'store_rejected', []],
] as $label => [$queue, $kind, $code, $data]) {
    $http->reset($queue);
    check($is($stores->create($snap, $newKey), $kind, $code, $data) && count($http->calls) === 1 && $http->calls[0][0] === 'POST', "Store create $label -> $kind");
}
check($http->calls[0][1] === '/api/v1/stores' && $http->calls[0][2] === ['name' => $name] && $http->calls[0][3] === 'key:' . $newKey, 'Store POST with merchant key, sanitized name ending with the exact marker');
foreach ([['in_flight' => true], ['state' => 'reconcile_required']] as $uncertain) {
    $http->reset([$r(200, [['id' => 'st-9', 'name' => $name]])]);
    $o = $stores->create(array_replace($snap, $uncertain), $newKey);
    check($is($o, O::DONE, null, ['remote_store_id' => 'st-9']) && count($http->calls) === 1 && $http->calls[0][0] === 'GET', 'Uncertain store (' . key($uncertain) . '): only GET, adopts the unique marker, never a second POST');
}
foreach ([
    'zero matches' => [[$r(200, [['id' => 'x', 'name' => 'Altro']])], O::RECONCILE, 'store_not_found'],
    'two matches' => [[$r(200, [['id' => 'a', 'name' => $name], ['id' => 'b', 'name' => 'Copia ' . $name]])], O::RECONCILE, 'store_ambiguous'],
    'marker only as prefix' => [[$r(200, [['id' => 'a', 'name' => $name . ' extra']])], O::RECONCILE, 'store_not_found'],
    'bad id' => [[$r(200, [['id' => 'bad id', 'name' => $name]])], O::RECONCILE, 'store_lookup_malformed'],
    'lookup 500' => [[$r(500)], O::RECONCILE, 'store_lookup_unavailable'],
    'lookup unknown' => [[$unknown], O::RECONCILE, 'store_lookup_unavailable'],
    'lookup 429' => [[$r(429, null, '3')], O::RETRY, 'rate_limited'],
] as $label => [$queue, $kind, $code]) {
    $http->reset($queue);
    check($is($stores->reconcile(array_replace($snap, ['in_flight' => true]), $newKey), $kind, $code) && count($http->calls) === 1 && $http->calls[0][0] === 'GET', "Store reconcile $label -> $kind (no POST)");
}
$http->reset([]);
check($is($stores->create(array_replace($snap, ['state' => 'failed']), $newKey), O::FAILED, 'link_failed') && $http->calls === [], 'Failed link not retried');

// --- App POS: dopo lo store, stesse regole
$apps = new PosLinkStep($http->key());
$psnap = ['state' => 'pending', 'marker' => $marker, 'remote_app_id' => null, 'in_flight' => false, 'account_state' => 'synced', 'remote_store_id' => 'st-1', 'terminal_name' => 'Cassa 1'];
$appName = 'Cassa 1 [napay-' . $marker . ']';
$http->reset([]);
check($is($apps->create(array_replace($psnap, ['remote_store_id' => null]), $newKey), O::PENDING, 'store_not_ready')
    && $is($apps->create(array_replace($psnap, ['remote_store_id' => '../x']), $newKey), O::PENDING, 'store_not_ready') && $http->calls === [], 'App: store not synced (or bad id) -> pending, no call');
foreach ([
    'created' => [[$r(200, ['id' => 'app-1', 'appName' => $appName, 'appType' => 'PointOfSale'])], O::DONE, null, ['remote_app_id' => 'app-1']],
    'created but Crowdfund' => [[$r(200, ['id' => 'app-1', 'appName' => $appName, 'appType' => 'Crowdfund'])], O::RECONCILE, 'app_response_malformed', []],
    'created without type' => [[$r(200, ['id' => 'app-1', 'appName' => $appName])], O::RECONCILE, 'app_response_malformed', []],
    '500' => [[$r(500)], O::RECONCILE, 'app_outcome_unknown', []],
    'unknown' => [[$unknown], O::RECONCILE, 'app_outcome_unknown', []],
    '403' => [[$r(403)], O::FAILED, 'merchant_key_denied', []],
] as $label => [$queue, $kind, $code, $data]) {
    $http->reset($queue);
    check($is($apps->create($psnap, $newKey), $kind, $code, $data) && count($http->calls) === 1, "App create $label -> $kind");
}
check($http->calls[0][1] === '/api/v1/stores/st-1/apps/pos' && $http->calls[0][2] === ['appName' => $appName], 'App POST in the linked store with the marker name');
$http->reset([$r(200, [['id' => 'app-6', 'appName' => $appName, 'appType' => 'Crowdfund'], ['id' => 'app-7', 'appName' => $appName, 'appType' => 'PointOfSale'], ['id' => 'app-8', 'appName' => 'Altra', 'appType' => 'PointOfSale']])]);
check($is($apps->create(array_replace($psnap, ['in_flight' => true]), $newKey), O::DONE, null, ['remote_app_id' => 'app-7']) && count($http->calls) === 1
    && $http->calls[0][0] === 'GET' && $http->calls[0][1] === '/api/v1/stores/st-1/apps', 'Uncertain app: only GET of the store apps, unique marker adopted');
$http->reset([$r(200, [])]);
check($is($apps->reconcile($psnap, $newKey), O::RECONCILE, 'app_not_found'), 'Zero apps: stays in reconciliation');
$http->reset([$r(200, [['id' => 'app-6', 'appName' => $appName, 'appType' => 'Crowdfund']])]);
check($is($apps->reconcile($psnap, $newKey), O::RECONCILE, 'app_not_found'), 'A non-POS app with the marker is never adopted');
$http->reset([]);
check($is($apps->create(array_replace($psnap, ['state' => 'suspended', 'remote_app_id' => 'app-old']), $newKey), O::FAILED, 'link_suspended') && $http->calls === [], 'Suspended app with an old ID is not done');

// --- Input e segreti
foreach ([fn() => $account->createUser('evil@example.invalid', $pw, $prov), fn() => $account->createKey($email, $pw, 'xyz', 1), fn() => $stores->create(['state' => 'pending', 'marker' => 'x'], $newKey)] as $bad) {
    $refused = false; try { $bad(); } catch (InvalidArgumentException $e) { $refused = true; }
    check($refused, 'Malformed step input refused before any call');
}
$secrets = [$pw, $prov, $newKey];
$traces = '';
foreach ([fn() => $account->createUser('evil@example.invalid', $pw, $prov), fn() => $account->createKey('x', $pw, $ref, 1), fn() => $stores->create(['state' => 'pending'], $newKey)] as $bad) {
    try { $bad(); } catch (InvalidArgumentException $e) {
        $frames = array_filter($e->getTrace(), fn($f) => str_starts_with($f['class'] ?? '', 'app\\components\\btcpay\\provisioning\\steps\\'));
        $traces .= print_r($frames, true) . var_export($frames, true);
    }
}
$leak = false; foreach ($secrets as $secret) $leak = $leak || str_contains($traces, $secret);
check($traces !== '' && !$leak, 'Passwords, provisioner and merchant keys hidden from step frames (SensitiveParameter)');
// Un'eccezione inattesa dal trasporto risale senza segreti nel frame di StepOutcome::call.
$http->reset([new LogicException('boom')]);
$frames = '';
try { $account->createUser($email, $pw, $prov); } catch (LogicException $e) { $frames = print_r(array_filter($e->getTrace(), fn($f) => ($f['class'] ?? '') === O::class), true); }
check($frames !== '' && !str_contains($frames, $pw) && !str_contains($frames, $prov), 'Transport closure (capturing secrets) hidden in StepOutcome::call frames');
$source = '';
foreach (glob($root . '/components/btcpay/provisioning/steps/*.php') as $file) $source .= file_get_contents($file);
check(preg_match('/Yii::|error_log|\becho\b|\bprint(_r)?\b|curl_|->db\b|Connection|createCommand|sleep\(|while\s*\(|for\s*\(/i', $source) === 0, 'Steps: no DB, logging, cURL, loops or sleeps');
echo "PASS $checks BTCPay provisioning step checks\n";
