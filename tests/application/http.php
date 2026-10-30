<?php
// HTTP smoke through real Apache/Yii. Never print response bodies or credentials.
if (PHP_SAPI !== 'cli' || getenv('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture'
    || getenv('DB_DATABASE') !== 'napay_test_runtime') exit(1);
$checks = 0;
function check($condition, $label) {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks;
    echo 'PASS: ' . $label . "\n";
}
function request($path, ?array $form = null): array {
    global $jar;
    $curl = curl_init('http://127.0.0.1' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 15, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_PROXY => '', CURLOPT_HEADER => true]);
    if ($form !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form)]);
    $raw = curl_exec($curl);
    if ($raw === false) throw new RuntimeException('HTTP transport failed.');
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $result = ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    curl_close($curl);
    return $result;
}
function token($html): string {
    $doc = new DOMDocument();
    @$doc->loadHTML($html, LIBXML_NONET);
    $nodes = (new DOMXPath($doc))->query('//meta[@name="csrf-token"]/@content');
    if ($nodes->length !== 1) throw new RuntimeException('Login CSRF token unavailable.');
    return $nodes->item(0)->nodeValue;
}
function profilePath($html, $username = null): string {
    $doc = new DOMDocument();
    @$doc->loadHTML($html, LIBXML_NONET);
    $query = $username === null ? '//header//a[@data-napay-profile-link]/@href'
        : '//tr[td[text()="' . $username . '"]]//a[contains(@href,"/users/view")]/@href';
    $nodes = (new DOMXPath($doc))->query($query);
    if ($nodes->length !== 1) throw new RuntimeException('Fixture profile link unavailable.');
    return $nodes->item(0)->nodeValue;
}
function login($role, $password = 'fixture-password-only'): array {
    $page = request('/site/login');
    check($page['status'] === 200, 'Login page: ' . $role);
    return request('/site/login', ['_csrf' => token($page['body']), 'LoginForm' => ['username' => 'runtime-' . $role, 'password' => $password]]);
}
function listNames($html): array {
    $doc = new DOMDocument();
    @$doc->loadHTML($html, LIBXML_NONET);
    $names = [];
    foreach ((new DOMXPath($doc))->query('//tr[@data-key]/td[2]') as $cell) $names[] = $cell->textContent;
    return $names;
}
function exportNames($response): array {
    check($response['status'] === 200 && str_starts_with($response['body'], 'PK'), 'Export returns XLSX');
    check(stripos($response['headers'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') !== false, 'XLSX MIME type');
    check(stripos($response['headers'], 'no-store') !== false, 'Export is not cached');
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
    $file = tempnam(sys_get_temp_dir(), 'napay-export-');
    $book = null;
    try {
        file_put_contents($file, $response['body']);
        $book = PhpOffice\PhpSpreadsheet\IOFactory::load($file);
        $sheet = $book->getActiveSheet();
        $names = [];
        foreach ($sheet->toArray(null, false, false, false) as $index => $row) {
            if ($index === 0) continue;
            $names[] = $row[1];
            if ($row[1] === 'runtime-other') {
                check($row[3] === '=1+1' && $sheet->getCell('D' . ($index + 1))->getDataType() === PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, 'Untrusted Excel formula remains literal text');
            }
        }
        return $names;
    } finally {
        if ($book !== null) $book->disconnectWorksheets();
        @unlink($file);
    }
}
$jar = tempnam(sys_get_temp_dir(), 'napay-http-');
$jars = [$jar];
try {
    check(request('/')['status'] === 302, 'Anonymous home redirects');
    foreach (['/debug/default/index', '/gii/default/index'] as $path) {
        check(request($path)['status'] === 404, 'Developer module unavailable to anonymous user');
    }
    check(request('/users/index')['status'] === 302, 'Anonymous protected list redirects');
    check(request('/settings/index')['status'] === 302, 'Anonymous settings deep link redirects');
    check(request('/users/view?id=invalid')['status'] === 302, 'Anonymous profile deep link requires login');
    $page = request('/site/login');
    check($page['status'] === 200 && str_contains($page['body'], 'login-form'), 'Pretty URL login rendered');
    check(token($page['body']) !== '', 'CSRF token generated');
    preg_match_all('~(?:src|href)="(/assets/[^"?]+)~', $page['body'], $matches);
    check(count($matches[1]) > 0, 'Composer assets published');
    foreach (array_unique($matches[1]) as $asset) check(request($asset)['status'] === 200, 'Published login asset loads');
    check(request('/site/login', ['LoginForm' => ['username' => 'runtime-admin', 'password' => 'fixture-password-only']])['status'] === 400, 'Missing CSRF rejected');
    check(login('admin', 'wrong-password')['status'] === 200 && request('/')['status'] === 302, 'Invalid password cannot authenticate');
    check(login('disabled')['status'] === 200 && request('/')['status'] === 302, 'Disabled user cannot authenticate');
    check(login('blocked')['status'] === 200 && request('/')['status'] === 302, 'Blocked status 2 cannot authenticate');
    check(login('admin')['status'] === 302, 'Admin authenticates');
    foreach (['/debug/default/index', '/gii/default/index'] as $path) {
        check(request($path)['status'] === 404, 'Developer module unavailable to administrator');
    }
    $dashboard = request('/');
    check($dashboard['status'] === 200 && str_contains($dashboard['body'], 'napay-app'), 'Real Tabler dashboard renders');
    $list = request('/users/index');
    check($list['status'] === 200, 'Admin reads restored users list');
    check(!in_array('runtime-higher', listNames($list['body']), true), 'Admin list excludes higher privilege');
    $panel = request('/admin/index');
    check($panel['status'] === 200 && !in_array('runtime-higher', listNames($panel['body']), true), 'Admin panel user card respects privilege boundary');
    $panel = request('/admin/index?' . http_build_query(['UsersSearch' => ['username' => 'runtime-higher']]));
    check($panel['status'] === 200 && !in_array('runtime-higher', listNames($panel['body']), true), 'Admin panel filters cannot bypass user scope');
    check(request('/users/index?sort=id')['status'] === 200, 'Scoped list retains ID sorting');
    $filter = request('/users/index?' . http_build_query(['UsersSearch' => ['username' => 'runtime-higher']]));
    check($filter['status'] === 200 && listNames($filter['body']) === [], 'Admin filter cannot reveal higher privilege');
    $exportCsrf = token($list['body']);
    check(request('/users/export')['status'] === 405, 'GET export denied');
    check(request('/users/export', ['queryParams' => '{}'])['status'] === 400, 'Export requires CSRF');
    foreach ([null, '{invalid', 'null', '"scalar"', ['invalid'], str_repeat('x', 65537)] as $invalid) {
        $form = ['_csrf' => $exportCsrf];
        if ($invalid !== null) $form['queryParams'] = $invalid;
        check(request('/users/export', $form)['status'] === 400, 'Malformed export filters rejected');
    }
    $names = exportNames(request('/users/export', ['_csrf' => $exportCsrf, 'queryParams' => '{}']));
    check(in_array('runtime-user', $names, true) && !in_array('runtime-higher', $names, true), 'Admin export respects privilege boundary');
    $userPath = profilePath($list['body'], 'runtime-user');
    $adminPath = profilePath($dashboard['body']);
    $profile = request($userPath);
    check($profile['status'] === 200, 'Admin reads another permitted profile');
    check(!str_contains($profile['body'], '<svg onload=') && str_contains($profile['body'], '&lt;svg'), 'Profile names encoded against stored XSS');
    check(str_contains($profile['body'], 'type="submit"') && str_contains($profile['body'], '/users/update'), 'Status edit form submits to update endpoint');
    check(request($adminPath)['status'] === 200, 'Admin reads own profile');
    check(request('/users/view?id=invalid!')['status'] === 404, 'Altered encrypted ID returns controlled 404');
    check(request('/users/view?id[]=1')['status'] === 404, 'Array ID rejected');
    $csrf = token($profile['body']);
    foreach (['enable', 'disable', 'update'] as $action) {
        $path = str_replace('/users/view', '/users/' . $action, $userPath);
        check(request($path)['status'] === 405, 'GET status mutation rejected: ' . $action);
        check(request($path, [])['status'] === 400, 'CSRF required for status mutation: ' . $action);
    }
    $update = str_replace('/users/view', '/users/update', $userPath);
    foreach ([[], ['is_active' => '2'], ['is_active' => ['1']], ['is_active' => '0', 'privilege_id' => '1'], ['is_active' => '0', 'password' => 'injected'], ['is_active' => '0', 'authKey' => 'injected']] as $input) {
        check(request($update, ['_csrf' => $csrf, 'Users' => $input])['status'] === 400, 'Invalid or mass-assigned status update rejected');
    }
    check(request(str_replace('/users/view', '/users/disable', $adminPath), ['_csrf' => $csrf])['status'] === 403, 'Admin cannot disable own account');
    check(request(str_replace('/users/view', '/users/update', $adminPath), ['_csrf' => $csrf, 'Users' => ['is_active' => '0']])['status'] === 403, 'Admin cannot mutate own status through form');
    $adminJar = $jar;
    $jar = tempnam(sys_get_temp_dir(), 'napay-http-'); $jars[] = $jar;
    check(login('user')['status'] === 302, 'User session exists before disable');
    check(request('/')['status'] === 200, 'User session initially active');
    $oldJar = tempnam(sys_get_temp_dir(), 'napay-http-'); $jars[] = $oldJar;
    copy($jar, $oldJar);
    $userJar = $jar; $jar = $adminJar;
    check(request(str_replace('/users/view', '/users/disable', $userPath), ['_csrf' => $csrf])['status'] === 302, 'Admin disables local account without AWS');
    $jar = $userJar;
    check(request('/')['status'] === 302, 'Existing disabled session revoked');
    $jar = $adminJar;
    check(request(str_replace('/users/view', '/users/enable', $userPath), ['_csrf' => $csrf])['status'] === 302, 'Admin enables local account without AWS');
    $jar = $oldJar;
    check(request('/')['status'] === 302, 'Old session and remember-me credentials stay invalid after enable');
    $jar = $adminJar;
    check(request($update, ['_csrf' => $csrf, 'Users' => ['is_active' => '0']])['status'] === 302, 'Status form updates local DB');
    check(request($update, ['_csrf' => $csrf, 'Users' => ['is_active' => '1']])['status'] === 302, 'Status form restores enabled state');
    check(request('/settings/index')['status'] === 200, 'Admin reads restored settings list');
    $jar = tempnam(sys_get_temp_dir(), 'napay-http-'); $jars[] = $jar;
    check(login('user')['status'] === 302, 'Basic user authenticates');
    $basicDashboard = request('/');
    check($basicDashboard['status'] === 200, 'Basic user dashboard renders');
    $own = profilePath($basicDashboard['body']);
    check(request($own)['status'] === 200, 'Basic user own profile renders');
    $csrf = token($basicDashboard['body']);
    foreach (['enable', 'disable', 'update'] as $action) {
        check(request(str_replace('/users/view', '/users/' . $action, $own), ['_csrf' => $csrf, 'Users' => ['is_active' => '1']])['status'] === 403, 'Basic user cannot mutate status: ' . $action);
    }
    check(request('/users/change-collapsed')['status'] === 405, 'Legacy sidebar GET mutation denied');
    check(request('/users/change-collapsed', [])['status'] === 400, 'Legacy sidebar mutation requires CSRF');
    $cookie = request('/users/change-collapsed', ['_csrf' => $csrf]);
    check($cookie['status'] === 200 && stripos($cookie['headers'], 'httponly') !== false, 'Legacy sidebar uses signed HttpOnly cookie');
    check(request('/users/change-collapsed', ['_csrf' => $csrf])['status'] === 200, 'Legacy sidebar signed cookie toggles back');
    check(request('/users/index')['status'] === 403, 'Basic user denied admin list');
    check(request('/settings/index')['status'] === 403, 'Basic user denied settings deep link');
    check(request('/users/export', ['_csrf' => $csrf, 'queryParams' => '{}'])['status'] === 403, 'Basic user cannot export users');
    $basicJar = $jar;
    $jar = tempnam(sys_get_temp_dir(), 'napay-http-'); $jars[] = $jar;
    check(login('operator')['status'] === 302, 'Level-40 operator authenticates');
    $operatorList = request('/users/index');
    check($operatorList['status'] === 200, 'Level-40 operator reads scoped list');
    $names = listNames($operatorList['body']);
    check(in_array('runtime-user', $names, true) && !in_array('runtime-admin', $names, true) && !in_array('runtime-higher', $names, true), 'Level-40 list excludes all higher levels');
    $filtered = request('/users/index?' . http_build_query(['UsersSearch' => ['username' => 'runtime-admin']]));
    check($filtered['status'] === 200 && listNames($filtered['body']) === [], 'Operator filters cannot bypass scope');
    $names = exportNames(request('/users/export', ['_csrf' => token($operatorList['body']), 'queryParams' => '{}']));
    check(in_array('runtime-user', $names, true) && !in_array('runtime-admin', $names, true) && !in_array('runtime-higher', $names, true), 'Level-40 export excludes all higher levels');
    $names = exportNames(request('/users/export', ['_csrf' => token($operatorList['body']), 'queryParams' => json_encode(['UsersSearch' => ['username' => 'runtime-admin']])]));
    check($names === [], 'Filtered export cannot bypass privilege scope');
    $jar = $basicJar;
    check(request('/index-test.php')['status'] === 403, 'Apache denies test entrypoint');
    foreach (['/.env', '/composer.json', '/docker/bootstrap.php', '/vendor/autoload.php'] as $path) {
        $response = request($path);
        check(in_array($response['status'], [403, 404], true), 'Private path unavailable: ' . $path);
        check(!str_contains($response['body'], 'Stack trace:') && !str_contains($response['body'], 'DB_PASSWORD='), 'Error response does not disclose internals');
    }
    echo "PASS {$checks} HTTP runtime checks\n";
} finally { foreach ($jars as $file) @unlink($file); }
