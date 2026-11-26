<?php
// TIP-020 passo 5a: scritture del backoffice associazione via HTTP reale, DB runtime usa e getta.
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
function handle($path, ?array $form = null, ?string $cookieJar = null) {
    global $jar;
    $curl = curl_init('http://127.0.0.1' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $cookieJar ?? $jar, CURLOPT_COOKIEFILE => $cookieJar ?? $jar]);
    if ($form !== null) {
        $multipart = (bool) array_filter($form, fn($v) => $v instanceof CURLFile);
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $multipart ? $form : http_build_query($form)]);
    }
    return $curl;
}
function result($curl) {
    $raw = curl_multi_getcontent($curl) ?: curl_exec($curl);
    if ($raw === false || $raw === null) throw new RuntimeException('HTTP transport failed.');
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
}
function request($path, ?array $form = null) { $c = handle($path, $form); $r = result($c); curl_close($c); return $r; }
function xp($page) { $d = new DOMDocument(); @$d->loadHTML($page['body'], LIBXML_NONET); return new DOMXPath($d); }
function csrf($page) {
    $n = xp($page)->query('//meta[@name="csrf-token"]/@content');
    if ($n->length !== 1) throw new RuntimeException('CSRF unavailable.');
    return $n->item(0)->nodeValue;
}
function href($page, $query) {
    $n = xp($page)->query($query);
    if ($n->length < 1) throw new RuntimeException('Link missing: ' . $query);
    return $n->item(0)->nodeValue;
}
function login($role, ?string &$cookieJar = null) {
    global $jar;
    $cookieJar = tempnam('/tmp', 'napay-assoc-w-');
    $jar = $cookieJar;
    $page = request('/site/login');
    return request('/site/login', ['_csrf' => csrf($page), 'LoginForm' => ['email' => $role . '@example.invalid', 'password' => 'fixture-password-only']])['status'];
}
function post($path, array $fields, $formPage = null) {
    $page = $formPage ?? request($path);
    return request($path, ['_csrf' => csrf($page)] + $fields);
}
function userId($role) { global $db; return (int) $db->createCommand('SELECT id FROM users WHERE username=:u', [':u' => 'runtime-' . $role])->queryScalar(); }
function scalar($sql, array $p = []) { global $db; return $db->createCommand($sql, $p)->queryScalar(); }
function profileEditUrl(string $email): string {
    $list = request('/association/members?' . http_build_query(['MembersSearch'=>['q'=>$email]]));
    $member = request(href($list, '//a[contains(@href,"/association/member?id=")]/@href'));
    return href($member, '//a[contains(@href,"member-update")]/@href');
}
$pdf = tempnam('/tmp', 'napay-pdf-'); file_put_contents($pdf, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
$fake = tempnam('/tmp', 'napay-fake-'); file_put_contents($fake, "<?php echo 'not a pdf';");
$png = tempnam('/tmp', 'napay-png-'); file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
// Types originate from registration/import, never from the profile editor.
$db->createCommand()->insert('member_profiles', ['user_id'=>userId('user'), 'is_corporate'=>0])->execute();
$db->createCommand()->insert('member_profiles', ['user_id'=>userId('merchant'), 'is_corporate'=>1, 'denomination'=>'PG fixture'])->execute();

try {
    // accessi
    $jar = tempnam('/tmp', 'napay-assoc-w-');
    check(request('/association/payment-create')['status'] === 302, 'Guest redirected to login');
    foreach (['user', 'merchant'] as $role) {
        check(login($role) === 302, "Login $role");
        foreach (['/association/payment-create', '/association/table-edit?type=fee', '/association/minute-create'] as $path) {
            check(request($path)['status'] === 403, "Non-admin $role forbidden: $path");
        }
    }
    check(login('admin', $adminJar) === 302, 'Admin login');
    check(request('/association/table-edit?type=fee', ['MembershipFees' => ['description' => 'x', 'duration' => 1, 'amount' => 1]])['status'] === 400, 'Write without CSRF rejected');
    check(request('/association/table-edit?type=bogus')['status'] === 404, 'Unknown table type -> 404');
    check(request('/association/payment-delete?id=x')['status'] === 405, 'Delete only by POST');

    // tabelle: crea, modifica, cancella; voce in uso non cancellabile
    check(post('/association/table-edit?type=fee', ['MembershipFees' => ['description' => 'Quota test', 'duration' => 1, 'amount' => '20.00']])['status'] === 302, 'Fee created');
    check(post('/association/table-edit?type=method', ['PaymentMethods' => ['description' => 'Contanti test', 'min_level' => 20]])['status'] === 302, 'Payment method created');
    check(post('/association/table-edit?type=role', ['AssociationRoles' => ['description' => '<script>Segretario</script>']])['status'] === 302, 'Role created');
    $invalidFee = post('/association/table-edit?type=fee', ['MembershipFees' => ['description' => '', 'duration' => 99, 'amount' => '-5']]);
    check($invalidFee['status'] === 200 && (int) scalar("SELECT COUNT(*) FROM membership_fees WHERE description=''") === 0, 'Invalid fee rejected');
    check(post('/association/table-edit?type=method', ['PaymentMethods' => ['description' => 'X', 'min_level' => 99]])['status'] === 200, 'min_level outside allowlist rejected');
    $tables = request('/association/tables');
    check(!str_contains($tables['body'], '<script>Segretario') && str_contains($tables['body'], '&lt;script&gt;Segretario'), 'Table values escaped');
    $feeId = (int) scalar("SELECT id FROM membership_fees WHERE description='Quota test'");
    $methodId = (int) scalar("SELECT id FROM payment_methods WHERE description='Contanti test'");
    $roleId = (int) scalar("SELECT id FROM association_roles WHERE description='<script>Segretario</script>'");
    $feeEdit = href($tables, '//tr[td[contains(.,"Quota test")]]//a[contains(@href,"table-edit")]/@href');
    check(post($feeEdit, ['MembershipFees' => ['description' => 'Quota test', 'duration' => 1, 'amount' => '25.00']])['status'] === 302 && scalar('SELECT amount FROM membership_fees WHERE id=:i', [':i' => $feeId]) === '25.00', 'Fee updated');

    // pagamenti: numero ricevuta progressivo per anno, mai dal form
    $db->createCommand()->insert('membership_payments', ['user_id' => userId('other'), 'fee_id' => $feeId, 'payment_method_id' => $methodId, 'year' => 2026, 'receipt_number' => 41,
        'amount' => '20.00', 'registered_on' => '2026-02-01', 'starts_on' => '2026-01-01', 'expires_on' => '2026-12-31', 'status' => 'paid'])->execute();
    $base = ['user_id' => userId('user'), 'fee_id' => $feeId, 'payment_method_id' => $methodId, 'amount' => '20.00',
        'registered_on' => '2026-03-01', 'starts_on' => '2026-01-01', 'expires_on' => '2026-12-31', 'status' => 'paid'];
    check(post('/association/payment-create', ['MembershipPayments' => $base + ['receipt_number' => 999, 'year' => 1999, 'legacy_id' => 7]])['status'] === 302, 'Paid payment registered');
    $p1 = $db->createCommand('SELECT * FROM membership_payments WHERE user_id=:u ORDER BY id DESC', [':u' => userId('user')])->queryOne();
    check((int) $p1['receipt_number'] === 42 && (int) $p1['year'] === 2026 && $p1['legacy_id'] === null, 'Receipt 42/2026 assigned by server; form cannot set number, year or legacy id');
    check(post('/association/payment-create', ['MembershipPayments' => ['status' => 'pending'] + $base])['status'] === 302, 'Pending payment registered');
    $p2 = $db->createCommand('SELECT * FROM membership_payments WHERE user_id=:u ORDER BY id DESC', [':u' => userId('user')])->queryOne();
    check($p2['receipt_number'] === null, 'Pending payment has no receipt number');
    foreach ([
        'expiry before start' => ['expires_on' => '2025-12-31'], 'negative amount' => ['amount' => '-1'], 'unknown fee' => ['fee_id' => 999999],
        'unknown user' => ['user_id' => 999999], 'unknown status' => ['status' => 'stolen'], 'bad date' => ['registered_on' => '31/12/2026'],
    ] as $case => $override) {
        $before = (int) scalar('SELECT COUNT(*) FROM membership_payments');
        $r = post('/association/payment-create', ['MembershipPayments' => $override + $base]);
        check($r['status'] === 200 && (int) scalar('SELECT COUNT(*) FROM membership_payments') === $before, "Invalid payment rejected: $case");
    }

    // modifica: pending -> paid riceve il numero successivo
    $memberPage = request(href(request('/association/members?' . http_build_query(['MembersSearch' => ['q' => 'user@example.invalid']])), '//a[contains(@href,"/association/member?id=")]/@href'));
    $editLinks = xp($memberPage)->query('//a[contains(@href,"payment-update")]/@href');
    check($editLinks->length >= 2, 'Edit links on member page');
    $pendingEdit = null;
    foreach ($editLinks as $link) {
        $form = request($link->nodeValue);
        if (str_contains($form['body'], 'value="pending" selected')) $pendingEdit = [$link->nodeValue, $form];
    }
    check($pendingEdit !== null, 'Pending payment edit form found');
    check(post($pendingEdit[0], ['MembershipPayments' => $base], $pendingEdit[1])['status'] === 302, 'Pending payment marked as paid');
    check((int) scalar('SELECT receipt_number FROM membership_payments WHERE id=:i', [':i' => $p2['id']]) === 43, 'Next receipt number on payment');
    check(str_contains((string) scalar("SELECT description FROM logs WHERE description LIKE :d ORDER BY id DESC", [':d' => 'Modificato Pagamento quota #' . $p2['id'] . '%']), 'status'), 'Update audited with field names');

    // due registrazioni concorrenti da due sessioni admin: numeri diversi
    login('admin', $jarA); $formA = request('/association/payment-create');
    login('admin', $jarB); $formB = request('/association/payment-create', null);
    $multi = curl_multi_init();
    $ha = handle('/association/payment-create', ['_csrf' => csrf($formA), 'MembershipPayments' => $base], $jarA);
    $hb = handle('/association/payment-create', ['_csrf' => csrf($formB), 'MembershipPayments' => $base], $jarB);
    curl_multi_add_handle($multi, $ha); curl_multi_add_handle($multi, $hb);
    do { curl_multi_exec($multi, $running); curl_multi_select($multi); } while ($running > 0);
    check(result($ha)['status'] === 302 && result($hb)['status'] === 302, 'Concurrent registrations both saved');
    $numbers = $db->createCommand('SELECT receipt_number FROM membership_payments WHERE year=2026 AND receipt_number IS NOT NULL ORDER BY receipt_number')->queryColumn();
    check(count($numbers) === count(array_unique($numbers)) && end($numbers) == 45, 'Concurrent receipts unique and progressive');
    $jar = $adminJar;

    // cancellazione pagamento (POST + CSRF) e voce in uso
    $deleteForm = request($pendingEdit[0]);
    $deleteAction = href($deleteForm, '//form[contains(@action,"payment-delete")]/@action');
    check(request(html_entity_decode($deleteAction), ['_csrf' => csrf($deleteForm)])['status'] === 302 && scalar('SELECT COUNT(*) FROM membership_payments WHERE id=:i', [':i' => $p2['id']]) == 0, 'Payment deleted');
    check(scalar("SELECT COUNT(*) FROM logs WHERE description=:d", [':d' => 'Eliminato Pagamento quota #' . $p2['id']]) == 1, 'Deletion audited');
    $tables = request('/association/tables');
    $methodDelete = href($tables, '//tr[td[contains(.,"Contanti test")]]//form[contains(@action,"table-delete")]/@action');
    request(html_entity_decode($methodDelete), ['_csrf' => csrf($tables)]);
    check(scalar('SELECT COUNT(*) FROM payment_methods WHERE id=:i', [':i' => $methodId]) == 1 && str_contains(request('/association/tables')['body'], 'Voce in uso'), 'Method in use cannot be deleted');
    check(post('/association/table-edit?type=fee', ['MembershipFees' => ['description' => 'Quota da eliminare', 'duration' => 0, 'amount' => '0']])['status'] === 302, 'Unused fee created');
    $tables = request('/association/tables');
    request(html_entity_decode(href($tables, '//tr[td[contains(.,"Quota da eliminare")]]//form[contains(@action,"table-delete")]/@action')), ['_csrf' => csrf($tables)]);
    check(scalar("SELECT COUNT(*) FROM membership_fees WHERE description='Quota da eliminare'") == 0, 'Unused fee deleted');

    // profilo socio
    $profileUrl = href($memberPage, '//a[contains(@href,"member-update")]/@href');
    $profileForm = request($profileUrl);
    check(xp($profileForm)->query('//input[@name="MemberProfiles[is_corporate]"]')->length === 0, 'Even administrator has no editable member type input');
    check(xp($profileForm)->query('//*[@id="member-profile-type" and @data-is-corporate="0"]')->length === 1, 'PF type displayed read-only');
    check(str_contains($profileForm['body'], 'type.getAttribute'), 'Client required validator uses immutable displayed type');
    foreach (['', '   '] as $emptyDenomination) {
        check(post($profileUrl, ['MemberProfiles'=>['denomination'=>$emptyDenomination]])['status'] === 302, 'PF accepts an empty denomination');
        $savedPf = $db->createCommand('SELECT is_corporate, denomination FROM member_profiles WHERE user_id=:u', [':u'=>userId('user')])->queryOne();
        check((int)$savedPf['is_corporate'] === 0 && $savedPf['denomination'] === null, 'PF blank denomination stored as null');
    }
    $pgUrl = profileEditUrl('merchant@example.invalid');
    check(post($pgUrl, ['MemberProfiles'=>['denomination'=>'']])['status'] === 200, 'PG requires a denomination without relying on JavaScript');
    check(post($pgUrl, ['MemberProfiles'=>['denomination'=>'   ']])['status'] === 200, 'PG whitespace denomination rejected server-side');
    check(post($profileUrl, ['MemberProfiles'=>['is_corporate'=>1, 'denomination'=>'Forged PG']])['status'] === 400, 'Administrator cannot change PF to PG');
    check(post($pgUrl, ['MemberProfiles'=>['is_corporate'=>0, 'denomination'=>'']])['status'] === 400, 'Administrator cannot change PG to PF');
    check(post($pgUrl, ['MemberProfiles'=>['is_corporate'=>['0']]])['status'] === 400, 'Malformed posted type rejected');
    check(scalar('SELECT denomination FROM member_profiles WHERE user_id=:u', [':u'=>userId('merchant')]) === 'PG fixture', 'Rejected PG changes preserve the existing denomination');
    $unclassifiedUrl = profileEditUrl('other@example.invalid');
    check(request($unclassifiedUrl)['status'] === 404, 'Editor cannot invent a PF profile for an unclassified account');
    foreach (['user'=>1, 'merchant'=>0] as $typeOwner=>$changedType) {
        $model = app\models\MemberProfiles::findOne(['user_id'=>userId($typeOwner)]);
        $model->is_corporate = $changedType;
        check(!$model->validate(['is_corporate']) && $model->hasErrors('is_corporate'), 'Model validation denies member type conversion: ' . $typeOwner);
        check(!$model->save(false), 'Skipping validation cannot change member type: ' . $typeOwner);
    }
    foreach (['user', 'merchant', 'operator'] as $profileActor) {
        login($profileActor);
        check(request($profileUrl)['status'] === 403, 'Non-admin cannot see corporate checkbox: ' . $profileActor);
        check(post($profileUrl, ['MemberProfiles'=>['is_corporate'=>1, 'denomination'=>'Forbidden PG']])['status'] === 403, 'Non-admin cannot change member type with crafted POST: ' . $profileActor);
    }
    login('admin');
    $profileUrl = profileEditUrl('user@example.invalid');
    $pgUrl = profileEditUrl('merchant@example.invalid');
    check((int)scalar('SELECT is_corporate FROM member_profiles WHERE user_id=:u', [':u'=>userId('user')]) === 0, 'Rejected PG posts leave PF unchanged');
    check(post($profileUrl, ['MemberProfiles' => ['association_role_id' => $roleId, 'denomination' => '', 'city' => 'Napoli (NA)', 'user_id' => userId('admin'), 'legacy_user_id' => 5]])['status'] === 302, 'PF profile details saved without changing type');
    $profile = $db->createCommand('SELECT * FROM member_profiles WHERE user_id=:u', [':u' => userId('user')])->queryOne();
    check($profile && (int) $profile['association_role_id'] === $roleId && $profile['legacy_user_id'] === null, 'Profile saved for the URL user; user_id and legacy id not assignable');
    check(post($profileUrl, ['MemberProfiles' => ['association_role_id' => 999999]])['status'] === 200, 'Unknown role rejected');
    check(post($pgUrl, ['MemberProfiles'=>['denomination'=>'PG aggiornata']])['status'] === 302, 'PG denomination remains editable without changing type');

    // verbali: solo PDF veri
    $minuteForm = request('/association/minute-create');
    $up = static fn($file, $name) => request('/association/minute-create', ['_csrf' => csrf($minuteForm), 'AssemblyMinutes[meeting_date]' => '2026-05-10', 'AssemblyMinutes[description]' => 'Assemblea test', 'minute_file' => new CURLFile($file, 'application/pdf', $name)]);
    foreach (['php script' => [$fake, 'verbale.pdf'], 'png image' => [$png, 'verbale.pdf']] as $case => [$file, $name]) {
        $r = $up($file, $name);
        check($r['status'] === 200 && str_contains($r['body'], 'deve essere un PDF') && scalar("SELECT COUNT(*) FROM assembly_minutes WHERE description='Assemblea test'") == 0, "Upload refused: $case");
    }
    check(request('/association/minute-create', ['_csrf' => csrf($minuteForm), 'AssemblyMinutes' => ['meeting_date' => '2026-05-10', 'description' => 'Assemblea test']])['status'] === 200, 'New minutes require a PDF');
    check($up($pdf, '../../etc/passwd.pdf')['status'] === 302, 'Real PDF uploaded');
    $minute = $db->createCommand("SELECT * FROM assembly_minutes WHERE description='Assemblea test'")->queryOne();
    check($minute['file_sha256'] === hash_file('sha256', $pdf) && $minute['file_name'] === 'verbale-2026-05-10.pdf', 'PDF stored with server-generated name, client name ignored');
    $minutes = request('/association/minutes');
    $download = request(href($minutes, '//tr[td[contains(.,"Assemblea test")]]//a[contains(@href,"minute-file")]/@href'));
    check($download['status'] === 200 && $download['body'] === file_get_contents($pdf), 'Uploaded PDF downloaded intact');
    $minuteEdit = href($minutes, '//tr[td[contains(.,"Assemblea test")]]//a[contains(@href,"minute-update")]/@href');
    check(post($minuteEdit, ['AssemblyMinutes' => ['meeting_date' => '2026-05-11', 'description' => 'Assemblea test']])['status'] === 302
        && scalar("SELECT file_sha256 FROM assembly_minutes WHERE id=:i", [':i' => $minute['id']]) === $minute['file_sha256'], 'Edit without new file keeps the PDF');
    $editPage = request($minuteEdit);
    request(html_entity_decode(href($editPage, '//form[contains(@action,"minute-delete")]/@action')), ['_csrf' => csrf($editPage)]);
    check(scalar('SELECT COUNT(*) FROM assembly_minutes WHERE id=:i', [':i' => $minute['id']]) == 0, 'Minutes deleted');

    // sola lettura con import legacy attivo
    putenv('LEGACY_DB_DSN=mysql:host=legacy;dbname=legacy');
    $blocked = false;
    try { app\controllers\AssociationController::ensureWritable(); } catch (yii\web\ForbiddenHttpException $e) { $blocked = true; }
    check($blocked, 'Writes blocked while the legacy import is active');
    putenv('LEGACY_DB_DSN');
    app\controllers\AssociationController::ensureWritable();
    check(true, 'Writes allowed once the import is switched off');
} finally {
    foreach ([$pdf, $fake, $png] as $file) @unlink($file);
    foreach (['membership_payments', 'member_profiles', 'assembly_minutes', 'payment_methods', 'membership_fees', 'association_roles'] as $table) $db->createCommand()->delete($table)->execute();
}
echo "PASS {$checks} association write checks\n";
