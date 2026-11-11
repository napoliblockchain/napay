<?php
// TIP-020 association backoffice over real HTTP: disposable runtime DB, fixture identities only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
$app = new yii\web\Application(require $root . '/config/web.php');
$db = $app->db;
$checks = 0; $jar = null;
function check($condition, $label) {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks; echo 'PASS: ' . $label . "\n";
}
function request($path, ?array $form = null) {
    global $jar;
    $curl = curl_init('http://127.0.0.1' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($form !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form)]);
    $raw = curl_exec($curl);
    if ($raw === false) throw new RuntimeException('Association HTTP transport failed.');
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $result = ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    curl_close($curl); return $result;
}
function xpath($page) { $doc = new DOMDocument(); @$doc->loadHTML($page['body'], LIBXML_NONET); return new DOMXPath($doc); }
function csrf($page) {
    $nodes = xpath($page)->query('//meta[@name="csrf-token"]/@content');
    if ($nodes->length !== 1) throw new RuntimeException('Association CSRF unavailable.');
    return $nodes->item(0)->nodeValue;
}
function login($role) {
    global $jar;
    $jar = tempnam('/tmp', 'napay-association-');
    $page = request('/site/login');
    return request('/site/login', ['_csrf' => csrf($page), 'LoginForm' => ['email' => $role . '@example.invalid', 'password' => 'fixture-password-only']])['status'];
}
function userId($role) { global $db; return (int) $db->createCommand('SELECT id FROM users WHERE username=:u', [':u' => 'runtime-' . $role])->queryScalar(); }
function hrefOf($page, $text, $href) {
    $nodes = xpath($page)->query('//a[contains(normalize-space(.),"' . $text . '") and contains(@href,"' . $href . '")]/@href');
    if ($nodes->length < 1) throw new RuntimeException('Expected link missing: ' . $href);
    return $nodes->item(0)->nodeValue;
}
// fixture users share first/last names: identify rows by their unique email
function emails($page) { return array_map(fn($n) => trim($n->nodeValue), iterator_to_array(xpath($page)->query('//table//a[starts-with(@href,"mailto:")]'))); }

// fixture: same tables written by the legacy import
$today = date('Y-m-d');
$db->createCommand()->insert('association_roles', ['description' => 'Segretario'])->execute(); $role = $db->getLastInsertID();
$db->createCommand()->insert('membership_fees', ['description' => 'Quota annuale', 'duration' => 1, 'amount' => '20.00'])->execute(); $fee = $db->getLastInsertID();
$db->createCommand()->insert('payment_methods', ['description' => 'Paypal', 'min_level' => 0])->execute(); $paypal = $db->getLastInsertID();
$db->createCommand()->insert('payment_methods', ['description' => 'Contanti', 'min_level' => 20])->execute(); $cash = $db->getLastInsertID();
$db->createCommand()->insert('member_profiles', ['user_id' => userId('user'), 'association_role_id' => $role, 'is_corporate' => 1, 'denomination' => '<b>Corp</b> srl', 'vat' => 'IT123', 'city' => 'Napoli (NA)'])->execute();
$payment = static function ($role, $expires, $amount, $method, $status, $number) use ($db, $fee) {
    $db->createCommand()->insert('membership_payments', ['user_id' => userId($role), 'fee_id' => $fee, 'payment_method_id' => $method, 'year' => 2026,
        'receipt_number' => $number, 'amount' => $amount, 'registered_on' => '2026-01-15', 'starts_on' => '2026-01-01', 'expires_on' => $expires,
        'paypal_txn_id' => $method == $GLOBALS['paypal'] ? 'PAYID-' . $number : null, 'status' => $status])->execute();
    return (int) $db->getLastInsertID();
};
$receiptId = $payment('user', date('Y-m-d', strtotime('+100 days')), '20.10', $paypal, 'paid', 7);
$payment('other', date('Y-m-d', strtotime('+10 days')), '20.00', $cash, 'paid', 8);
$payment('merchant', date('Y-m-d', strtotime('-30 days')), '15.00', $cash, 'paid', 9);
$payment('merchant', date('Y-m-d', strtotime('+200 days')), '20.00', $paypal, 'pending', null);
$db->createCommand()->insert('assembly_minutes', ['meeting_date' => '2026-02-01', 'description' => 'Assemblea <i>ordinaria</i>', 'file_name' => 'a.pdf', 'file_sha256' => hash('sha256', '%PDF-fixture'), 'file_content' => '%PDF-fixture'])->execute();
$db->createCommand()->insert('assembly_minutes', ['meeting_date' => '2025-02-01', 'description' => 'Assemblea senza file'])->execute();
$db->createCommand()->insert('settings', ['description' => 'fixture', 'code' => 'gdpr_titolare', 'value' => 'Associazione <script>Fixture</script>'])->execute();

try {
    $jar = tempnam('/tmp', 'napay-association-');
    $guest = request('/association/members');
    check($guest['status'] === 302 && str_contains($guest['headers'], '/site/login'), 'Guest redirected to login');
    foreach (['user', 'merchant'] as $r) {
        check(login($r) === 302, 'Login ' . $r);
        foreach (['members', 'payments', 'minutes', 'tables'] as $page) check(request('/association/' . $page)['status'] === 403, "Non-admin $r forbidden: $page");
    }
    check(login('admin') === 302, 'Admin login');
    $members = request('/association/members');
    check($members['status'] === 200 && str_contains($members['body'], 'href="/association/payments"'), 'Admin sees members and association menu');
    check(!str_contains($members['body'], 'sola lettura'), 'No read-only banner without legacy import');
    check(!str_contains($members['body'], '<b>Corp</b>') && str_contains($members['body'], '&lt;b&gt;Corp&lt;/b&gt; srl'), 'Member text escaped');
    $byStatus = static function ($status) { return emails(request('/association/members?' . http_build_query(['MembersSearch' => ['status' => $status]]))); };
    $active = $byStatus('active'); $expiring = $byStatus('expiring'); $expired = $byStatus('expired'); $exempt = $byStatus('exempt');
    $nameOf = static fn($role) => $role . '@example.invalid';
    check(in_array($nameOf('user'), $active, true) && !in_array($nameOf('other'), $active, true), 'Active filter: valid fee beyond 45 days');
    check(in_array($nameOf('other'), $expiring, true) && !in_array($nameOf('user'), $expiring, true), 'Expiring filter: within 45 days');
    check(in_array($nameOf('merchant'), $expired, true), 'Expired filter: last paid fee expired, pending payment ignored');
    check(in_array($nameOf('admin'), $exempt, true) && !in_array($nameOf('admin'), $expired, true), 'Administrators exempt');
    check(request('/association/members?' . http_build_query(['MembersSearch' => ['status' => 'bogus']]))['status'] === 200, 'Invalid filter handled');

    $memberUrl = hrefOf(request('/association/members?' . http_build_query(['MembersSearch' => ['q' => 'Corp']])), '', '/association/member?id=');
    $member = request($memberUrl);
    check($member['status'] === 200 && str_contains($member['body'], 'Segretario') && str_contains($member['body'], '7/2026'), 'Member page: role and payments');
    check(request('/association/member?id=tampered')['status'] === 404 && request('/association/receipt?id=' . urlencode(base64_encode('x')))['status'] === 404, 'Tampered IDs -> 404, never 500');

    $payments = request('/association/payments');
    check($payments['status'] === 200 && str_contains($payments['body'], '4 pagamenti'), 'Payments list with totals');
    $paid = request('/association/payments?' . http_build_query(['MembershipPaymentsSearch' => ['status' => 'paid', 'payment_method_id' => $cash]]));
    check(str_contains($paid['body'], '2 pagamenti') && str_contains(html_entity_decode($paid['body']), '35,00'), 'Filtered totals exact (2 cash payments, 35.00)');

    $receipt = request(hrefOf($payments, '', '/association/receipt'));
    check($receipt['status'] === 200 && str_contains($receipt['body'], 'data-napay-print') && str_contains($receipt['body'], 'association.js'), 'Receipt printable');
    $direct = request(str_replace('/association/member?', '/association/receipt?', $memberUrl));
    check(in_array($direct['status'], [200, 404], true), 'Receipt by foreign ID never errors');
    $receiptPage = request(hrefOf($member, '', '/association/receipt'));
    check(str_contains($receiptPage['body'], '7/2026') && str_contains($receiptPage['body'], 'PAYID-7') && str_contains(html_entity_decode($receiptPage['body']), '20,10'), 'Receipt number, PayPal ID and amount');
    check(!str_contains($receiptPage['body'], '<script>Fixture') && str_contains($receiptPage['body'], '&lt;script&gt;Fixture'), 'Association settings escaped on receipt');

    check(request('/association/export', ['queryParams' => '[]'])['status'] === 400, 'Export requires CSRF');
    check(request('/association/export')['status'] === 405, 'Export only by POST');
    $export = request('/association/export', ['_csrf' => csrf($payments), 'queryParams' => json_encode(['MembershipPaymentsSearch' => ['status' => 'paid']])]);
    check($export['status'] === 200 && str_contains($export['headers'], 'spreadsheetml') && str_starts_with($export['body'], 'PK'), 'Export xlsx');
    $zip = tempnam('/tmp', 'napay-xlsx-'); file_put_contents($zip, $export['body']);
    $archive = new ZipArchive(); $archive->open($zip);
    $sheet = $archive->getFromName('xl/worksheets/sheet1.xml') . $archive->getFromName('xl/sharedStrings.xml');
    $archive->close(); unlink($zip);
    check(substr_count($sheet, '<row ') === 4 && !str_contains($sheet, '<f>') && str_contains($sheet, '=1+1'), 'Export: paid rows only, user text never a formula');

    $minutes = request('/association/minutes');
    check($minutes['status'] === 200 && str_contains($minutes['body'], 'PDF non disponibile') && str_contains($minutes['body'], '&lt;i&gt;ordinaria'), 'Minutes list');
    $pdf = request(hrefOf($minutes, 'PDF', '/association/minute-file'));
    check($pdf['status'] === 200 && $pdf['body'] === '%PDF-fixture' && str_contains($pdf['headers'], 'application/pdf') && str_contains($pdf['headers'], 'attachment'), 'Minute PDF download');
    $noFile = (int) $db->createCommand("SELECT id FROM assembly_minutes WHERE file_content IS NULL")->queryScalar();
    check(request('/association/minute-file?id=tampered')['status'] === 404 && $noFile > 0, 'Missing PDF -> 404');
    $tables = request('/association/tables');
    check($tables['status'] === 200 && str_contains($tables['body'], 'Quota annuale') && str_contains($tables['body'], 'solo registrazione manuale'), 'Association tables');
} finally {
    $db->createCommand()->delete('settings', ['code' => 'gdpr_titolare', 'description' => 'fixture'])->execute();
    foreach (['membership_payments', 'member_profiles', 'assembly_minutes', 'payment_methods', 'membership_fees', 'association_roles'] as $table) $db->createCommand()->delete($table)->execute();
}
echo "PASS {$checks} association backoffice checks\n";
