<?php
// TIP-020 legacy association import against disposable DBs only: synthetic legacy fixture, no real data.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
require dirname(__DIR__, 2) . '/docker/bootstrap-common.php';
require_once dirname(__DIR__, 2) . '/components/legacy/LegacyImportException.php';
require_once dirname(__DIR__, 2) . '/components/legacy/LegacyImporter.php';
use app\components\db\V2Bootstrap as Db;
use app\components\legacy\LegacyImporter as Importer;
if (getenv('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_bootstrap') exit(1);
$config = napayBootstrapConfig();
$db = Db::connect($config['dsn'], $config['user'], $config['password']);
$legacy = Db::connect('mysql:host=mysql;port=3306;dbname=napay_test_legacy;charset=utf8mb4', $config['user'], $config['password']);
$checks = 0;
function check($condition, $label) {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks;
    echo 'PASS: ' . $label . "\n";
}
function rejected(callable $operation, $label) {
    try { $operation(); } catch (RuntimeException $error) { check(true, $label); return; }
    check(false, $label);
}
function one(PDO $pdo, $sql, array $parameters = []) { return Db::rows($pdo, $sql, $parameters)[0] ?? null; }
function user($email) { global $db; return one($db, 'SELECT u.*, p.level FROM users u JOIN privileges p ON p.id=u.privilege_id WHERE u.email=?', [$email]); }
function digest() {
    global $db;
    $tables = ['users', 'member_profiles', 'association_roles', 'membership_fees', 'payment_methods', 'membership_payments', 'assembly_minutes', 'settings'];
    return hash('sha256', json_encode(array_map(fn($t) => Db::rows($db, "SELECT * FROM $t ORDER BY id"), $tables), JSON_THROW_ON_ERROR));
}
function legacyChecksum() {
    global $legacy;
    return json_encode(Db::rows($legacy, 'CHECKSUM TABLE np_comuni_italiani, np_users_type, np_users, st_cariche, st_quote, st_tipo_pagamenti, st_pagamenti, st_verbali, np_settings_webapp EXTENDED'));
}
$files = '/tmp/napay-legacy-files';
function import($filesDir = 'default') { global $legacy, $db, $files; return Importer::run($legacy, $db, $filesDir === 'default' ? $files : $filesDir); }

// legacy fixture: same structure as the old Yii1 schema (np_/st_ tables), synthetic rows only
foreach ([
    "CREATE TABLE np_users_type (id_users_type int NOT NULL AUTO_INCREMENT, `desc` varchar(50) DEFAULT NULL, status varchar(50) NOT NULL DEFAULT '0', note varchar(255) NOT NULL, PRIMARY KEY (id_users_type)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3",
    "CREATE TABLE np_users (id_user int NOT NULL AUTO_INCREMENT, id_users_type int NOT NULL, id_carica int NOT NULL, email varchar(255) NOT NULL, password varchar(255) NOT NULL, ga_secret_key varchar(16) DEFAULT NULL, name varchar(255) NOT NULL, surname varchar(255) NOT NULL, corporate varchar(16) DEFAULT NULL, denomination varchar(250) DEFAULT NULL, vat varchar(250) NOT NULL, address varchar(250) NOT NULL, cap varchar(250) NOT NULL, city varchar(250) NOT NULL, country varchar(250) NOT NULL, activation_code varchar(50) NOT NULL, status_activation_code int NOT NULL, PRIMARY KEY (id_user)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3",
    "CREATE TABLE st_cariche (id_carica int NOT NULL AUTO_INCREMENT, id_users_type int NOT NULL, description varchar(50) NOT NULL, PRIMARY KEY (id_carica)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3",
    "CREATE TABLE st_quote (id_quota int NOT NULL AUTO_INCREMENT, description varchar(50) NOT NULL, extension int NOT NULL, importo float NOT NULL, PRIMARY KEY (id_quota)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3",
    "CREATE TABLE st_tipo_pagamenti (id_tipo_pagamento int NOT NULL AUTO_INCREMENT, permission int NOT NULL, description varchar(50) NOT NULL, PRIMARY KEY (id_tipo_pagamento)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3",
    "CREATE TABLE st_pagamenti (id_pagamento int NOT NULL AUTO_INCREMENT, id_user int NOT NULL, id_quota int NOT NULL, anno int NOT NULL DEFAULT '0', progressivo int NOT NULL DEFAULT '0', importo float NOT NULL, id_tipo_pagamento int NOT NULL, data_registrazione date NOT NULL DEFAULT '1901-01-01', data_inizio date NOT NULL DEFAULT '1901-01-01', data_scadenza date NOT NULL DEFAULT '1901-01-01', id_invoice_bps varchar(60) NOT NULL, paypal_txn_id varchar(60) NOT NULL DEFAULT '0', status varchar(250) NOT NULL, PRIMARY KEY (id_pagamento)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3",
    "CREATE TABLE st_verbali (id_verbali int NOT NULL AUTO_INCREMENT, data_verbale date NOT NULL, url_verbale varchar(250) NOT NULL, descrizione_verbale varchar(500) NOT NULL, PRIMARY KEY (id_verbali)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3",
    "CREATE TABLE np_comuni_italiani (id_comune int NOT NULL AUTO_INCREMENT, citta varchar(200) NOT NULL, provincia varchar(50) NOT NULL, sigla varchar(2) NOT NULL, PRIMARY KEY (id_comune)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3",
    "INSERT INTO np_comuni_italiani VALUES (5,'Napoli','Napoli','NA')",
    "CREATE TABLE np_settings_webapp (id_setting int NOT NULL AUTO_INCREMENT, setting_name varchar(50) NOT NULL, setting_value varchar(15000) NOT NULL, PRIMARY KEY (id_setting)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3",
    "INSERT INTO np_users_type VALUES (1,'POS','0','Profilo POS'),(2,'Commerciante','10','x'),(3,'Amministratore','20','x'),(5,'Socio','5','x'),(6,'Istituto','10','x')",
    "INSERT INTO st_cariche VALUES (1,3,'Presidente'),(4,3,'Segretario'),(5,5,'Socio ordinario')",
    "INSERT INTO st_quote VALUES (1,'Quota associativa annuale',1,20),(2,'Donazione',0,0)",
    "INSERT INTO st_tipo_pagamenti VALUES (1,0,'Bitcoin'),(2,0,'Paypal'),(9,20,'Contanti')",
    "INSERT INTO st_verbali VALUES (1,'2019-07-16','abc123.pdf','Assemblea 1'),(2,'2020-01-18','missing.pdf','Assemblea 2')",
    "INSERT INTO np_settings_webapp (setting_name,setting_value) VALUES ('quota_iscrizione_socio','20'),('gdpr_titolare','Associazione Test'),('gdpr_city','5'),('PAYPAL_CLIENT_SECRET','must-not-import'),('poa_sealerPrvKey','must-not-import')",
] as $sql) $legacy->exec($sql);
$addUser = $legacy->prepare("INSERT INTO np_users VALUES (?,?,?,?,?,NULL,?,?,?,?,'',' Via Test 1 ','80100',?,'Italia','',1)");
foreach ([
    [10, 3, 4, 'segretario@example.invalid', 'Segre', '0', null], [11, 5, 5, "socio@example.invalid\t", 'Socio', '0', null],
    [12, 2, 5, 'shop@example.invalid', 'Shop', '1', 'Shop srl'], [13, 1, 5, 'pos@example.invalid', 'Pos', '0', null],
    [14, 6, 5, 'ist@example.invalid', 'Ist', '0', null], [15, 5, 5, 'not-an-email', 'Bad', '0', null],
    [16, 5, 5, 'Linked@Example.invalid', 'Linked', '0', null],
] as [$id, $type, $role, $email, $name, $corporate, $denomination]) {
    // città = ID comune legacy; 99999 non esiste e resta com'è
    $addUser->execute([$id, $type, $role, $email, password_hash('legacy-pass-' . $id, PASSWORD_BCRYPT), $name, 'Fixture', $corporate, $denomination, $id === 11 ? '99999' : '5']);
}
$addPayment = $legacy->prepare("INSERT INTO st_pagamenti VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
foreach ([
    [100, 11, 1, 2021, 5, 20, 2, '2021-03-01', '2021-01-01', '2021-12-31', 'inv-100', 'PAYID-100', 'paid'],
    [101, 11, 1, 0, 0, 20.1000000014901, 1, '2022-02-01', '2022-01-01', '2022-12-31', 'inv-101', '0', 'new'],
    [102, 12, 1, 2021, 6, 20, 9, '2021-04-01', '2021-01-01', '2021-12-31', 'x', '', 'paid'],
    [103, 14, 1, 2021, 7, 20, 9, '2021-04-02', '2021-01-01', '2021-12-31', 'x', '0', 'paid'],
    [104, 15, 1, 2021, 8, 20, 9, '2021-04-03', '2021-01-01', '2021-12-31', 'x', '0', 'paid'],
    [105, 16, 1, 2020, 9, 20, 2, '2020-05-01', '2020-01-01', '2020-12-31', 'x', '0', 'expired'],
    [106, 10, 2, 2020, 0, 0, 1, '2020-06-01', '2020-01-01', '2020-12-31', 'inv-106', '0', 'complete'],
] as $row) $addPayment->execute($row);
@mkdir($files, 0700);
file_put_contents($files . '/abc123.pdf', "%PDF-1.4 fixture minutes\n");
$db->prepare("INSERT INTO users (username,email,password,first_name,last_name,oauth_provider,oauth_uid,authKey,accessToken,picture,privilege_id,is_merchant,is_active) VALUES ('v2-linked','linked@example.invalid',?,'V2','Owner','SELF','u','a','t','/favicon.ico',(SELECT id FROM privileges WHERE level=0),0,1)")
    ->execute([password_hash('v2-pass', PASSWORD_BCRYPT)]);
$legacyBefore = legacyChecksum();

check(!Importer::active(), 'import inactive without LEGACY_DB_DSN');
$report = import();
check($report['users'] === ['created' => 4, 'updated' => 0, 'linked' => 1, 'skipped_type' => 1, 'skipped_email' => 1, 'inactive_pos' => 1], 'users: created, linked, istituto and invalid email skipped');
check($report['payments'] === ['imported' => 5, 'skipped_user' => 2, 'removed' => 0], 'payments of skipped users not imported');
check($report['minutes'] === ['imported' => 2, 'files' => 1, 'files_missing' => 1, 'removed' => 0], 'minutes and files reported');
check($report['settings'] === 3 && $report['roles'] === 3 && $report['fees'] === 2 && $report['payment_methods'] === 3, 'reference tables and settings imported');
$socio = user('socio@example.invalid');
check($socio && $socio['username'] === 'socio@example.invalid' && password_verify('legacy-pass-11', $socio['password']), 'legacy email trimmed, username = email, legacy password still valid');
check($socio['level'] === '0' && $socio['is_active'] === '1' && $socio['oauth_provider'] === 'SELF', 'member is an active user');
$admin = user('segretario@example.invalid');
check($admin['level'] === '50' && one($db, 'SELECT r.description FROM member_profiles m JOIN association_roles r ON r.id=m.association_role_id WHERE m.user_id=?', [$admin['id']])['description'] === 'Segretario', 'Segretario imported as administrator with its role');
$shop = user('shop@example.invalid');
$shopProfile = one($db, 'SELECT * FROM member_profiles WHERE user_id=?', [$shop['id']]);
check($shop['level'] === '30' && $shop['is_merchant'] === '1' && $shopProfile['is_corporate'] === '1' && $shopProfile['denomination'] === 'Shop srl' && $shopProfile['address'] === 'Via Test 1', 'merchant privilege and company profile');
check($shopProfile['city'] === 'Napoli (NA)' && one($db, 'SELECT city FROM member_profiles WHERE legacy_user_id=11')['city'] === '99999'
    && one($db, "SELECT value FROM settings WHERE code='gdpr_city'")['value'] === 'Napoli (NA)', 'legacy city IDs resolved to municipality names, unknown kept');
check(user('pos@example.invalid')['is_active'] === '0' && one($db, 'SELECT legacy_user_type FROM member_profiles WHERE legacy_user_id=13')['legacy_user_type'] === '1', 'POS imported disabled, legacy type kept');
check(user('ist@example.invalid') === null && one($db, 'SELECT id FROM member_profiles WHERE legacy_user_id IN (14,15)') === null, 'istituto and invalid email not imported');
$linked = user('linked@example.invalid');
check($linked['username'] === 'v2-linked' && password_verify('v2-pass', $linked['password']) && one($db, 'SELECT user_imported FROM member_profiles WHERE legacy_user_id=16')['user_imported'] === '0', 'existing v2 user only linked, identity untouched');
$p101 = one($db, 'SELECT * FROM membership_payments WHERE legacy_id=101');
check($p101['amount'] === '20.10' && $p101['status'] === 'pending' && $p101['year'] === null && $p101['receipt_number'] === null && $p101['paypal_txn_id'] === null, 'float amount rounded, new -> pending, empty legacy values -> null');
check(one($db, 'SELECT status FROM membership_payments WHERE legacy_id=106')['status'] === 'complete', 'unknown legacy status kept as is');
check(one($db, 'SELECT user_id FROM membership_payments WHERE legacy_id=105')['user_id'] === $linked['id'], 'payments attached to the linked v2 user');
check(one($db, "SELECT value FROM settings WHERE code='quota_iscrizione_socio'")['value'] === '20'
    && one($db, "SELECT id FROM settings WHERE code IN ('PAYPAL_CLIENT_SECRET','poa_sealerPrvKey')") === null, 'non-secret settings imported, legacy secrets never');
check(one($db, 'SELECT file_sha256 FROM assembly_minutes WHERE legacy_id=1')['file_sha256'] === hash_file('sha256', $files . '/abc123.pdf')
    && one($db, 'SELECT file_content FROM assembly_minutes WHERE legacy_id=2')['file_content'] === null, 'minutes PDF stored, missing file left empty');

$before = digest();
$again = import();
check($again['users']['created'] === 0 && $again['users']['updated'] === 4 && $again['payments']['imported'] === 5, 'second run updates in place');
check(digest() === $before, 'second run is idempotent (no changes, no duplicates)');
check(import(null)['minutes']['files_missing'] === 1 && one($db, 'SELECT file_content FROM assembly_minutes WHERE legacy_id=1')['file_content'] !== null, 'stored file kept when the folder is no longer mounted');

$legacy->exec("UPDATE st_pagamenti SET status='paid' WHERE id_pagamento=101");
$legacy->exec("INSERT INTO st_pagamenti VALUES (107,11,1,2023,10,20,2,'2023-03-01','2023-01-01','2023-12-31','x','PAYID-107','paid')");
$legacy->exec('DELETE FROM st_pagamenti WHERE id_pagamento=102');
$legacy->exec("UPDATE np_users SET name='Socio Renamed' WHERE id_user=11");
$legacy->exec("UPDATE np_users SET password='" . password_hash('changed', PASSWORD_BCRYPT) . "' WHERE id_user=16");
$changed = import();
check($changed['payments']['removed'] === 1 && one($db, 'SELECT id FROM membership_payments WHERE legacy_id=102') === null, 'payment deleted in legacy removed from v2');
check(one($db, 'SELECT status FROM membership_payments WHERE legacy_id=101')['status'] === 'paid' && one($db, 'SELECT id FROM membership_payments WHERE legacy_id=107') !== null, 'legacy status change and new payment propagate');
check(user('socio@example.invalid')['first_name'] === 'Socio Renamed' && password_verify('v2-pass', user('linked@example.invalid')['password']), 'imported user follows legacy, linked v2 user does not');

$db->exec('UPDATE membership_payments SET amount=99 WHERE legacy_id=100');
rejected(fn() => Importer::reconcile($legacy, $db), 'tampered v2 totals detected by reconciliation');
import();
Importer::reconcile($legacy, $db);
check(true, 'next import restores legacy-authoritative values');

$payments = one($db, 'SELECT COUNT(*) AS total FROM membership_payments')['total'];
$before = digest();
$db->exec("INSERT INTO users (username,email,password,first_name,last_name,oauth_provider,oauth_uid,authKey,accessToken,picture,privilege_id,is_merchant,is_active) SELECT 'twin-1', 'twin@example.invalid', password, first_name, last_name, oauth_provider, oauth_uid, authKey, accessToken, picture, privilege_id, is_merchant, is_active FROM users WHERE email='linked@example.invalid'");
$db->exec("INSERT INTO users (username,email,password,first_name,last_name,oauth_provider,oauth_uid,authKey,accessToken,picture,privilege_id,is_merchant,is_active) SELECT 'twin-2', 'twin@example.invalid', password, first_name, last_name, oauth_provider, oauth_uid, authKey, accessToken, picture, privilege_id, is_merchant, is_active FROM users WHERE email='linked@example.invalid'");
$twins = digest();
$legacy->exec("INSERT INTO np_users VALUES (17,5,5,'twin@example.invalid','x',NULL,'Twin','Fixture','0',NULL,'','','','','','',1)");
$legacy->exec("INSERT INTO st_pagamenti VALUES (108,17,1,2023,11,20,2,'2023-04-01','2023-01-01','2023-12-31','x','0','paid')");
rejected(fn() => import(), 'ambiguous v2 email blocks the import');
check(digest() === $twins && one($db, 'SELECT COUNT(*) AS total FROM membership_payments')['total'] === $payments, 'failed import rolled back completely');
$legacy->exec('DELETE FROM np_users WHERE id_user=17');
$legacy->exec('DELETE FROM st_pagamenti WHERE id_pagamento=108');
$db->exec("DELETE FROM users WHERE email='twin@example.invalid'");
check(digest() === $before, 'fixture restored after ambiguity case');

$legacy->exec("INSERT INTO np_users_type VALUES (7,'Altro','0','x')");
rejected(fn() => import(), 'unknown legacy user type blocks the import');
$legacy->exec('DELETE FROM np_users_type WHERE id_users_type=7');

$owner = Db::connect($config['dsn'], $config['user'], $config['password']);
$lock = 'napay-legacy-import-' . substr(hash('sha256', 'napay_test_bootstrap'), 0, 40);
Db::rows($owner, 'SELECT GET_LOCK(?,0) AS acquired', [$lock]);
rejected(fn() => import(), 'concurrent import rejected by database lock');
Db::rows($owner, 'SELECT RELEASE_LOCK(?) AS released', [$lock]);

// the fixture changed above on purpose: compare against a fresh checksum after an import-only run
$legacyNow = legacyChecksum();
import();
check(legacyChecksum() === $legacyNow && $legacyBefore !== '', 'importer never writes to the legacy database');
echo "PASS {$checks} legacy association import checks\n";
exit(0);
