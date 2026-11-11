<?php
// Included by pos-sessions.php: real HTTP and disposable fixture DB only.
if (PHP_SAPI !== 'cli' || !isset($app, $db, $key, $rotated)
    || env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);

(static function () use ($app, $db, $key, $rotated, $root): void {
    $before = $GLOBALS['checks'];
    $secrets = [$key, $rotated];
    // Previous login tests deliberately invalidate their CLI configuration.
    putenv('POS_API_CLIENT_KEYS_SHA256=' . hash('sha256', $key) . ',' . hash('sha256', $rotated));
    putenv('POS_SESSION_TTL_SECONDS');
    fresh();
    $login = static function (string $client) use (&$secrets): string {
        $result = api(['email' => 'user@example.invalid', 'password' => 'fixture-password-only'], $client, op());
        check($result['status'] === 201, 'Logout fixture login succeeds');
        $token = $result['json']['access_token']; $secrets[] = $token;
        return $token;
    };
    $logout = static function ($token, $client, $body = null) {
        return api($body, $client, $token === null ? [] : ['Authorization: Bearer ' . $token], 'DELETE', '');
    };
    $row = static fn(string $token) => $db->createCommand('SELECT * FROM pos_operator_sessions WHERE token_sha256=:d', [':d' => hash('sha256', $token)])->queryOne();
    $token = $login($key); $other = $login($key); $foreign = $login($rotated);
    check($logout($token, null)['status'] === 401, 'Logout requires service key');
    check($logout($token, 'invalid-service-key')['status'] === 401, 'Wrong service key rejected');
    foreach ([null, 'bad', 'napos_' . str_repeat('A', 44)] as $invalid) {
        $r = $logout($invalid, $key);
        check($r['status'] === 401 && code($r) === 'invalid_token', 'Missing or malformed bearer rejected');
    }
    check($logout($token, $key, '{}')['status'] === 400, 'Logout body must be empty');
    check($row($token)['revoked_at'] === null, 'Invalid requests do not revoke session');
    $r = $logout($foreign, $key);
    check($r['status'] === 204 && $r['body'] === '' && $row($foreign)['revoked_at'] === null, 'Different client cannot revoke another client token; no existence oracle');
    $r = $logout($token, $key);
    check($r['status'] === 204 && $r['body'] === '', 'Logout returns empty 204');
    $revoked = $row($token);
    check($revoked['revoked_at'] !== null && $revoked['revoked_reason'] === 'logout', 'Logout persisted in real DB');
    check(app\components\pos\PosApi::findActiveSession($token, substr(hash('sha256', $key), 0, 16)) === null, 'Revoked token cannot authenticate');
    check($row($other)['revoked_at'] === null && $row($foreign)['revoked_at'] === null, 'Other operator sessions remain untouched');
    $r2 = $logout($token, $key);
    check($r2['status'] === 204 && $row($token)['revoked_at'] === $revoked['revoked_at'], 'Retry idempotent, original revocation unchanged');
    check($logout('napos_' . str_repeat('Z', 43), $key)['status'] === 204, 'Unknown well-formed token returns same 204');
    check(str_contains(strtolower($r['headers']), 'cache-control: no-store') && !preg_match('/^(?:set-cookie:|access-control-)/mi', $r['headers']), 'Logout no-store, no cookies or CORS');
    $expired = $login($key);
    $db->createCommand()->update('pos_operator_sessions', ['expires_at' => time() - 1], ['token_sha256' => hash('sha256', $expired)])->execute();
    check($logout($expired, $key)['status'] === 204 && $row($expired)['revoked_at'] !== null, 'Expired token can be permanently revoked');
    $changed = $login($key);
    $userId = (int) $row($changed)['user_id'];
    try {
        $db->createCommand()->update('users', ['is_active' => 0], ['id' => $userId])->execute();
        check($logout($changed, $key)['status'] === 204 && $row($changed)['revoked_at'] !== null, 'Disabled account can still revoke owned token');
    } finally {
        $db->createCommand()->update('users', ['is_active' => 1], ['id' => $userId])->execute();
    }
    $passwordChanged = $login($key);
    $originalHash = $db->createCommand('SELECT password FROM users WHERE id=:id', [':id'=>$userId])->queryScalar();
    try {
        $db->createCommand()->update('users', ['password'=>password_hash('logout-fixture-changed', PASSWORD_BCRYPT)], ['id'=>$userId])->execute();
        check($logout($passwordChanged, $key)['status'] === 204 && $row($passwordChanged)['revoked_at'] !== null, 'Changed password does not prevent permanent token revocation');
    } finally {
        $db->createCommand()->update('users', ['password'=>$originalHash], ['id'=>$userId])->execute();
    }
    // Concurrent DELETE requests exercise the same atomic conditional UPDATE.
    $concurrent = $login($key); $multi = curl_multi_init(); $handles = [];
    for ($i = 0; $i < 2; ++$i) {
        $c = curl_init('http://127.0.0.1/api/pos/v1/sessions');
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['X-POS-Client-Key: ' . $key, 'Authorization: Bearer ' . $concurrent]]);
        curl_multi_add_handle($multi, $c); $handles[] = $c;
    }
    do { $status = curl_multi_exec($multi, $running); if ($running) curl_multi_select($multi, 1); } while ($running && $status === CURLM_OK);
    foreach ($handles as $c) {
        check(curl_getinfo($c, CURLINFO_RESPONSE_CODE) === 204 && curl_multi_getcontent($c) === '', 'Concurrent logout succeeds idempotently');
        curl_multi_remove_handle($multi, $c); curl_close($c);
    }
    curl_multi_close($multi);
    check($row($concurrent)['revoked_at'] !== null, 'Concurrent logout persists revocation');
    try {
        $db->createCommand('RENAME TABLE pos_operator_sessions TO pos_operator_sessions_logout_failure')->execute();
        $failed = $logout($token, $key);
        check($failed['status'] === 503 && code($failed) === 'backend_unavailable' && !str_contains($failed['body'], $token), 'DB failure returns sanitized 503, never false success');
    } finally {
        $db->createCommand('RENAME TABLE pos_operator_sessions_logout_failure TO pos_operator_sessions')->execute();
    }
    fresh();
    for ($i = 0; $i < 60; ++$i) $logout($token, $key);
    $limited = $logout($token, $key);
    check($limited['status'] === 429 && code($limited) === 'rate_limited' && preg_match('/^retry-after: ([1-9]\d*)/mi', $limited['headers']), 'Logout client rate limit and Retry-After');
    check($logout($foreign, $rotated)['status'] === 204, 'Logout rate limit isolated by client');
    fresh();
    $logs = json_encode($db->createCommand('SELECT description FROM logs')->queryColumn());
    foreach (glob($root . '/runtime/logs/*.log') ?: [] as $file) $logs .= file_get_contents($file);
    check(count(array_filter($secrets, static fn($s) => str_contains($logs, $s))) === 0, 'No bearer or client secrets in logout logs');
    echo 'PASS ' . ($GLOBALS['checks'] - $before) . " POS logout HTTP/DB checks\n";
})();
