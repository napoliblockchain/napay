<?php
// SIP-022 B1: client HTTP del provisioning contro controfigure locali (TLS, proxy CONNECT), container senza rete.
// Credenziali fittizie generate qui; output solo etichette ed esiti, mai credenziali, header o corpi.
if (PHP_SAPI !== 'cli') exit(1);
ini_set('zend.exception_ignore_args', '0');
$root = dirname(__DIR__, 2);
foreach (['payments/Egress', 'btcpay/provisioning/ProvisioningHttpException', 'btcpay/provisioning/ProvisioningHttpClient'] as $file) require $root . '/components/' . $file . '.php';
use app\components\btcpay\provisioning\ProvisioningHttpClient as Client;
use app\components\btcpay\provisioning\ProvisioningHttpException as Failure;

$certs = getenv('NAPAY_HTTP_TEST_CERTS') ?: '/certs';
$work = sys_get_temp_dir() . '/napay-http-' . bin2hex(random_bytes(6));
mkdir($work, 0700);
$fixture = __DIR__ . '/btcpay-provisioning-http-fixture.php';
$processes = [];
$start = static function (array $args) use (&$processes, $fixture): void {
    $process = proc_open(array_merge([PHP_BINARY, $fixture], $args), [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Fixture unavailable.');
    $processes[] = $process;
};
$start(['server', '8443', "$certs/server.pem", "$certs/server.key", "$work/count-8443"]);
$start(['server', '8444', "$certs/rogue.pem", "$certs/rogue.key", "$work/count-8444"]);
$start(['server', '8445', "$certs/server.pem", "$certs/server.key", "$work/count-8445"]);
$start(['proxy', '3128', "$work/proxy.log"]);
$start(['proxy', '3129', "$work/silent.log", 'silent']);
foreach ([8443, 8444, 8445, 3128, 3129] as $port) {
    for ($i = 0; $i < 100 && !($socket = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $error, 1)); ++$i) usleep(50000);
    if (!$socket) throw new RuntimeException('Fixture did not start.');
    fclose($socket);
}
register_shutdown_function(static function () use (&$processes): void { foreach ($processes as $process) { proc_terminate($process); proc_close($process); } });
// L'ambiente non deve poter scavalcare il proxy obbligatorio.
foreach (['no_proxy=*', 'NO_PROXY=*', 'https_proxy=http://127.0.0.1:9', 'HTTPS_PROXY=http://127.0.0.1:9'] as $variable) putenv($variable);
putenv('PAYMENT_PROXY=http://127.0.0.1:3128');
file_put_contents("$work/proxy.log", '');

$checks = 0;
function check($condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    ++$checks; echo 'PASS: ' . $label . "\n";
}
/** Rifiuto con codice fisso, senza eccezione precedente; restituisce l'eccezione per altri controlli. */
function fails(callable $fn, string $code, string $label): ?Failure {
    try { $fn(); } catch (Failure $e) { check($e->getMessage() === $code && $e->getPrevious() === null && $e->notSent() === ($code !== Failure::OUTCOME_UNKNOWN), $label); return $e; }
    check(false, $label);
    return null;
}
$ca = "$certs/ca.pem";
$origin = 'https://btcpay.test:8443';
$client = new Client($origin, $ca);
$key = 'k' . bin2hex(random_bytes(20));
$user = 'm-' . bin2hex(random_bytes(16)) . '@btcpay-merchants.jamprojects.it';
$password = 'p' . bin2hex(random_bytes(20));
$proxyLog = static fn(): string => (string) file_get_contents("$work/proxy.log");
$count = static fn(int $port): int => (int) file_get_contents("$work/count-$port");

// --- Configurazione: rifiutata prima di qualunque connessione ---
foreach (['http://btcpay.test:8443', 'https://btcpay.test:8443/', 'https://btcpay.test/api', 'https://user@btcpay.test', 'https://btcpay.test?x=1',
    'https://BTCPAY.test', 'https://btcpay.test:0', 'https://btcpay.test:65536', 'https://-bad.test', 'https://btcpay.test:8443#f', ' https://btcpay.test'] as $bad) {
    fails(fn() => new Client($bad, $ca), Failure::INVALID_CONFIG, 'Origin refused: ' . json_encode($bad));
}
fails(fn() => new Client($origin, 'certs/ca.pem'), Failure::INVALID_CONFIG, 'Relative CA path refused');
fails(fn() => new Client($origin, '/nonexistent/ca.pem'), Failure::INVALID_CONFIG, 'Missing CA file refused');
foreach ([null => 'absent', 'https://127.0.0.1:3128' => 'HTTPS scheme', 'http://user:pw@127.0.0.1:3128' => 'with credentials', 'http://127.0.0.1' => 'without port'] as $proxy => $label) {
    putenv($proxy === '' ? 'PAYMENT_PROXY' : 'PAYMENT_PROXY=' . $proxy);
    fails(fn() => new Client($origin, $ca), Failure::INVALID_CONFIG, 'Mandatory egress proxy: ' . $label . ' refused');
}
putenv('PAYMENT_PROXY=http://127.0.0.1:3128');

// --- Richieste non valide: mai inviate ---
foreach (['PATCH', 'get', 'HEAD', 'POST '] as $method) fails(fn() => $client->withKey($method, '/api/v1/echo', null, $key), Failure::INVALID_REQUEST, 'Method refused: ' . json_encode($method));
foreach (['/api/v2/stores', '/api/v1', '/api/v1/', '/api/v1/../server/info', '/api/v1/stores/../users', '/api/v1/a/./b', '/api/v1/a//b', '/api/v1/a?x=1',
    '/api/v1/a#f', '/api/v1/%2e%2e', '/api/v1/a%2Fb', 'https://evil.test/api/v1/x', '//evil.test/api/v1/x', '/api/v1/a b', "/api/v1/a\r\nX-Injected: 1", '/api/v1/.hidden', '/api/v1/a/..'] as $path) {
    fails(fn() => $client->withKey('GET', $path, null, $key), Failure::INVALID_REQUEST, 'Path refused: ' . json_encode($path));
}
fails(fn() => $client->withKey('GET', '/api/v1/echo', ['x' => 1], $key), Failure::INVALID_REQUEST, 'GET with body refused');
foreach (['' => 'empty', 'a b' => 'space', "a\r\nX: y" => 'CRLF', str_repeat('k', 257) => 'too long', "k\u{00e8}" => 'non-ASCII'] as $badKey => $label) {
    fails(fn() => $client->withKey('GET', '/api/v1/echo', null, (string) $badKey), Failure::INVALID_REQUEST, 'API key refused: ' . $label);
}
foreach ([['', $password, 'empty user'], ['a:b', $password, 'colon in user'], ["a\nb", $password, 'newline in user'], [$user, '', 'empty password'], [$user, str_repeat('p', 257), 'long password']] as [$u, $p, $label]) {
    fails(fn() => $client->withBasic('POST', '/api/v1/api-keys', null, $u, $p), Failure::INVALID_REQUEST, 'Basic credentials refused: ' . $label);
}
fails(fn() => $client->withKey('POST', '/api/v1/echo', ['name' => "\xff"], $key), Failure::INVALID_REQUEST, 'Body not encodable as JSON refused');
fails(fn() => $client->withKey('POST', '/api/v1/echo', ['pad' => str_repeat('x', 1048576)], $key), Failure::INVALID_REQUEST, 'Body over 1 MiB refused before sending');
check($proxyLog() === '' && $count(8443) === 0, 'Nothing reached proxy or server for refused configuration and requests');

// --- Richieste valide, sempre tramite proxy CONNECT ---
$body = ['email' => $user, 'password' => $password, 'path' => '/a/b'];
$response = $client->withKey('POST', '/api/v1/users', $body, $key);
$echo = $response['json'];
check($response['status'] === 200 && $response['retry_after'] === null && $echo['method'] === 'POST' && $echo['path'] === '/api/v1/users', 'POST delivered to the configured origin and path');
check($echo['scheme'] === 'token' && $echo['authorization_sha256'] === hash('sha256', 'token ' . $key), 'API key sent only as Authorization token header');
check($echo['content_type'] === 'application/json' && $echo['body_sha256'] === hash('sha256', json_encode($body, JSON_UNESCAPED_SLASHES)) && $echo['expect'] === false, 'JSON body sent intact, no Expect handshake');
$response = $client->withBasic('POST', '/api/v1/api-keys', ['label' => 'napay-g2', 'permissions' => ['btcpay.store.canmodifystoresettings']], $user, $password);
check($response['json']['scheme'] === 'Basic' && $response['json']['authorization_sha256'] === hash('sha256', 'Basic ' . base64_encode($user . ':' . $password)), 'Technical password sent only as Basic authorization');
$response = $client->withKey('GET', '/api/v1/users/' . $user, null, $key);
check($response['json']['method'] === 'GET' && $response['json']['path'] === '/api/v1/users/' . $user && $response['json']['content_type'] === null, 'Technical e-mail accepted as path segment; GET without body');
check($client->withKey('DELETE', '/api/v1/empty', null, $key) === ['status' => 204, 'retry_after' => null, 'json' => null], 'Empty response returned without JSON');
$log = $proxyLog();
check(substr_count($log, "CONNECT btcpay.test:8443 HTTP/1.1 |") === 4 && !str_contains($log, '/api/') && !str_contains($log, 'authorization'), 'Every request tunnelled through the proxy despite no_proxy; proxy sees only host and port');
check(!str_contains($log, $key) && !str_contains($log, $password) && !str_contains($log, base64_encode($user . ':' . $password)), 'No credential reaches the proxy');

// --- Stati restituiti al chiamante, nessun ritentativo ---
foreach ([400, 401, 403, 404, 422, 500, 503] as $status) {
    $response = $client->withKey('POST', '/api/v1/status/' . $status, ['a' => 1], $key);
    check($response['status'] === $status && $response['json'] === ['code' => $status], "Status $status returned as is");
}
$before = $count(8443);
$limited = $client->withKey('POST', '/api/v1/limited', ['a' => 1], $key);
check($limited['status'] === 429 && $limited['retry_after'] === '120' && $count(8443) === $before + 1, '429 returned with Retry-After seconds after a single attempt');
check($client->withKey('POST', '/api/v1/limited-date', ['a' => 1], $key)['retry_after'] === 'Sun, 06 Nov 2044 08:49:37 GMT', 'Retry-After date returned verbatim');
check($client->withKey('POST', '/api/v1/limited-twice', ['a' => 1], $key)['retry_after'] === '120, 130', 'Repeated Retry-After returned as a list (unparsable for RetryPolicy)');
$before = $count(8443);
fails(fn() => $client->withKey('POST', '/api/v1/redirect', ['a' => 1], $key), Failure::OUTCOME_UNKNOWN, 'Redirect not followed: outcome unknown');
check($count(8443) === $before + 1 && !str_contains($proxyLog(), 'evil.test'), 'Redirect target never contacted, request sent once');

// --- Limiti e risposte rotte: esito ignoto, mai prova di mancata esecuzione ---
$exact = $client->withKey('GET', '/api/v1/exact-body', null, $key);
check($exact['status'] === 200 && strlen($exact['json'][0]) === 1048572, 'Body of exactly 1 MiB accepted');
foreach (['big-body' => 'Body over 1 MiB', 'big-header' => 'Headers over 16 KiB', 'truncated' => 'Truncated body', 'malformed' => 'Malformed JSON',
    'scalar' => 'JSON scalar', 'garbage' => 'Non-HTTP response', 'drop' => 'Connection closed without response'] as $path => $label) {
    fails(fn() => $client->withKey('POST', '/api/v1/' . $path, ['a' => 1], $key), Failure::OUTCOME_UNKNOWN, $label . ': outcome unknown');
}

// --- TLS: CA e hostname verificati (classificati comunque come esito ignoto) ---
$before = $count(8443) + $count(8444);
fails(fn() => (new Client('https://other.test:8443', $ca))->withKey('POST', '/api/v1/echo', ['a' => 1], $key), Failure::OUTCOME_UNKNOWN, 'Certificate for another hostname refused');
fails(fn() => (new Client('https://rogue.test:8443', $ca))->withKey('POST', '/api/v1/echo', ['a' => 1], $key), Failure::OUTCOME_UNKNOWN, 'Certificate from an untrusted CA refused');
fails(fn() => (new Client($origin))->withKey('POST', '/api/v1/echo', ['a' => 1], $key), Failure::OUTCOME_UNKNOWN, 'Local CA not trusted unless configured');
check($count(8443) + $count(8444) === $before, 'No request delivered over an unverified TLS session');
fails(fn() => (new Client('https://unlisted.test:8443', $ca))->withKey('POST', '/api/v1/echo', ['a' => 1], $key), Failure::OUTCOME_UNKNOWN, 'Proxy refusal of an unlisted host: outcome unknown');

// --- Timeout ---
$started = microtime(true);
fails(fn() => (new Client('https://slow.test:8443', $ca))->withKey('POST', '/api/v1/slow', ['a' => 1], $key), Failure::OUTCOME_UNKNOWN, 'Slow response: total timeout, outcome unknown');
$elapsed = microtime(true) - $started;
check($elapsed >= 14 && $elapsed < 17 && $count(8445) === 1, 'Total timeout 15 s after the request reached the server');
putenv('PAYMENT_PROXY=http://127.0.0.1:3129');
$started = microtime(true);
fails(fn() => (new Client($origin, $ca))->withKey('POST', '/api/v1/echo', ['a' => 1], $key), Failure::OUTCOME_UNKNOWN, 'Silent proxy: connect timeout, outcome unknown');
$elapsed = microtime(true) - $started;
check($elapsed >= 4 && $elapsed < 7, 'Connect timeout 5 s');
putenv('PAYMENT_PROXY=http://127.0.0.1:3128');

// --- Nessun segreto in eccezioni, trace o argomenti ---
$secrets = [$key, $password, base64_encode($user . ':' . $password), 'secret-in-body-' . bin2hex(random_bytes(8))];
$errors = [];
$errors[] = fails(fn() => $client->withKey('POST', '/api/v1/drop', ['password' => $secrets[3]], $key), Failure::OUTCOME_UNKNOWN, 'Failure with API key and secret body');
$errors[] = fails(fn() => $client->withBasic('POST', '/api/v1/drop', ['password' => $secrets[3]], $user, $password), Failure::OUTCOME_UNKNOWN, 'Failure with Basic credentials and secret body');
$errors[] = fails(fn() => $client->withKey('PATCH', '/api/v1/echo', ['password' => $secrets[3]], $key), Failure::INVALID_REQUEST, 'Refusal with API key and secret body');
// Il frame fails() del test riceve la closure che cattura le credenziali di prova: si controllano i frame del client.
$leak = false; $clientFrames = 0;
foreach ($errors as $error) {
    $frames = array_filter($error->getTrace(), fn($frame) => ($frame['class'] ?? '') === Client::class);
    $clientFrames += count($frames);
    $dump = $error->getMessage() . print_r($frames, true) . var_export($frames, true);
    foreach ($secrets as $secret) $leak = $leak || str_contains($dump, $secret);
}
check(!$leak && $clientFrames >= 4, 'Client frames in exceptions carry no credential or payload (SensitiveParameter)');
$source = file_get_contents($root . '/components/btcpay/provisioning/ProvisioningHttpClient.php');
check(preg_match('/getenv|\$_(SERVER|ENV)|Yii::|error_log|curl_error|file_put_contents|echo |print|CURLOPT_VERBOSE|CURLOPT_FOLLOWLOCATION => true/i', $source) === 0, 'No environment secrets, logging, cURL error text or redirects in the client');

echo "PASS $checks BTCPay provisioning HTTP checks\n";
