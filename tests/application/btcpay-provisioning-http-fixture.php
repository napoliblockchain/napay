<?php
// SIP-022 B1: controfigure locali per il client HTTP del provisioning, solo dentro il container di test senza rete.
//   server <porta> <cert> <chiave> <contatore>: HTTPS su 127.0.0.1, comportamento scelto dal percorso
//   proxy <porta> <log> [silent]: proxy CONNECT con allowlist di nomi -> porte locali; silent = non risponde mai
// Nessun dato reale: le richieste ricevute sono riassunte con digest, mai con credenziali o corpi in chiaro.
if (PHP_SAPI !== 'cli') exit(1);
set_time_limit(0);
[$mode, $port] = [$argv[1] ?? '', (int) ($argv[2] ?? 0)];

function readHead($connection): ?string
{
    $head = '';
    while (!str_contains($head, "\r\n\r\n")) {
        $chunk = fread($connection, 1);
        if ($chunk === '' || $chunk === false) return null;
        $head .= $chunk;
        if (strlen($head) > 65536) return null;
    }
    return $head;
}

function respond($connection, int $status, array $headers, string $body): void
{
    $text = "HTTP/1.1 $status X\r\n";
    foreach ($headers as $header) $text .= $header . "\r\n";
    fwrite($connection, $text . 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
}

if ($mode === 'server') {
    [, , , $cert, $key, $counter] = $argv;
    $context = stream_context_create(['ssl' => ['local_cert' => $cert, 'local_pk' => $key, 'verify_peer' => false]]);
    $server = stream_socket_server('ssl://127.0.0.1:' . $port, $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
    if (!$server) exit(2);
    file_put_contents($counter, '0');
    for (;;) {
        $connection = @stream_socket_accept($server, -1);
        if (!$connection) continue;
        stream_set_timeout($connection, 5);
        $head = readHead($connection);
        if ($head === null) { fclose($connection); continue; }
        file_put_contents($counter, (string) ((int) file_get_contents($counter) + 1));
        $lines = explode("\r\n", $head);
        [$method, $path] = explode(' ', $lines[0]) + ['', ''];
        $headers = [];
        foreach (array_slice($lines, 1) as $line) if (($colon = strpos($line, ':')) !== false) $headers[strtolower(substr($line, 0, $colon))] = trim(substr($line, $colon + 1));
        $body = '';
        $length = (int) ($headers['content-length'] ?? 0);
        while (strlen($body) < $length && ($chunk = fread($connection, $length - strlen($body))) !== '' && $chunk !== false) $body .= $chunk;
        $json = ['Content-Type: application/json'];
        switch (true) {
            case $path === '/api/v1/empty': respond($connection, 204, [], ''); break;
            case preg_match('#^/api/v1/status/([0-9]{3})$#', $path, $m) === 1: respond($connection, (int) $m[1], $json, '{"code":' . $m[1] . '}'); break;
            case $path === '/api/v1/limited': respond($connection, 429, array_merge($json, ['Retry-After: 120']), '{"code":"rate-limited"}'); break;
            case $path === '/api/v1/limited-date': respond($connection, 429, array_merge($json, ['Retry-After: Sun, 06 Nov 2044 08:49:37 GMT']), '{}'); break;
            case $path === '/api/v1/limited-twice': respond($connection, 429, array_merge($json, ['Retry-After: 120', 'Retry-After: 130']), '{}'); break;
            case $path === '/api/v1/redirect': respond($connection, 302, ['Location: https://evil.test:8443/api/v1/echo'], ''); break;
            case $path === '/api/v1/exact-body': respond($connection, 200, $json, '["' . str_repeat('a', 1048572) . '"]'); break;
            case $path === '/api/v1/big-body': respond($connection, 200, $json, '["' . str_repeat('a', 1048573) . '"]'); break;
            case $path === '/api/v1/big-header': respond($connection, 200, array_merge($json, ['X-Pad: ' . str_repeat('p', 17000)]), '{}'); break;
            case $path === '/api/v1/truncated': fwrite($connection, "HTTP/1.1 200 X\r\nContent-Type: application/json\r\nContent-Length: 100\r\n\r\n{\"a\":1"); break;
            case $path === '/api/v1/malformed': respond($connection, 200, $json, '{not json'); break;
            case $path === '/api/v1/scalar': respond($connection, 200, $json, '42'); break;
            case $path === '/api/v1/garbage': fwrite($connection, "NOT HTTP AT ALL\r\n\r\n"); break;
            case $path === '/api/v1/drop': break;
            case $path === '/api/v1/slow': sleep(17); respond($connection, 200, $json, '{}'); break;
            default:
                // Eco riassunto: schema e digest dell'autorizzazione e del corpo, mai i valori.
                $authorization = $headers['authorization'] ?? '';
                respond($connection, 200, $json, json_encode([
                    'method' => $method, 'path' => $path, 'scheme' => strtok($authorization, ' '), 'authorization_sha256' => hash('sha256', $authorization),
                    'content_type' => $headers['content-type'] ?? null, 'body_sha256' => hash('sha256', $body), 'expect' => isset($headers['expect']),
                    'count' => (int) file_get_contents($counter),
                ]));
        }
        fclose($connection);
    }
}

if ($mode === 'proxy') {
    [, , , $log] = $argv;
    $silent = ($argv[4] ?? '') === 'silent';
    $routes = ['btcpay.test:8443' => 8443, 'other.test:8443' => 8443, 'rogue.test:8443' => 8444, 'slow.test:8443' => 8445];
    $server = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);
    if (!$server) exit(2);
    for (;;) {
        $client = @stream_socket_accept($server, -1);
        if (!$client) continue;
        stream_set_timeout($client, 5);
        $head = readHead($client);
        if ($head === null) { fclose($client); continue; }
        $first = strtok($head, "\r\n");
        // Solo la riga di richiesta e i nomi degli header: verifica che nel proxy non transitino credenziali.
        $names = [];
        foreach (array_slice(explode("\r\n", trim($head)), 1) as $line) $names[] = strtolower(strtok($line, ':'));
        file_put_contents($log, $first . ' | ' . implode(',', $names) . "\n", FILE_APPEND);
        if ($silent) { sleep(10); fclose($client); continue; }
        if (preg_match('#^CONNECT ([a-z0-9.-]+:[0-9]+) HTTP/1\.[01]$#', $first, $m) !== 1 || !isset($routes[$m[1]])) {
            fwrite($client, "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n"); fclose($client); continue;
        }
        $upstream = @stream_socket_client('tcp://127.0.0.1:' . $routes[$m[1]], $errno, $error, 2);
        if (!$upstream) { fwrite($client, "HTTP/1.1 502 Bad Gateway\r\nContent-Length: 0\r\n\r\n"); fclose($client); continue; }
        fwrite($client, "HTTP/1.1 200 Connection established\r\n\r\n");
        // Socket bloccanti: select per sapere chi ha dati, scritture sempre complete.
        for ($open = true; $open;) {
            $read = [$client, $upstream]; $write = $except = null;
            if (stream_select($read, $write, $except, 30) < 1) break;
            foreach ($read as $stream) {
                $data = fread($stream, 65536);
                if ($data === '' || $data === false) { $open = false; break; }
                fwrite($stream === $client ? $upstream : $client, $data);
            }
        }
        fclose($upstream); fclose($client);
    }
}
exit(1);
