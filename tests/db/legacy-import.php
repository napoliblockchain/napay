<?php
// TIP-020 legacy association import against disposable DBs only: synthetic legacy fixture, no real data.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
require dirname(__DIR__, 2) . '/docker/bootstrap-common.php';
require_once dirname(__DIR__, 2) . '/components/legacy/LegacyImportException.php';
require_once dirname(__DIR__, 2) . '/components/legacy/LegacyImporter.php';
require_once dirname(__DIR__, 2) . '/components/btcpay/StoreSettingsPayload.php';
require_once dirname(__DIR__, 2) . '/components/merchant/MerchantLogo.php';
require_once dirname(__DIR__, 2) . '/components/legacy/LegacyMerchantImport.php';
use app\components\db\V2Bootstrap as Db;
use app\components\legacy\LegacyImporter as Importer;
use app\components\legacy\LegacyMerchantImport;
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
    $tables = ['users', 'member_profiles', 'association_roles', 'membership_fees', 'payment_methods', 'membership_payments', 'assembly_minutes', 'settings',
        'merchants', 'stores', 'storesettings', 'store_tts_wallets', 'merchant_logos', 'pos_terminals', 'legacy_pos_terminals'];
    $keys = ['storesettings' => 'store_id', 'store_tts_wallets' => 'store_id', 'merchant_logos' => 'merchant_id', 'legacy_pos_terminals' => 'legacy_id'];
    return hash('sha256', json_encode(array_map(fn($t) => Db::rows($db, 'SELECT ' . ($t === 'merchant_logos' ? 'merchant_id, sha256, width, height, updated_by, updated_at' : '*') . " FROM $t ORDER BY " . ($keys[$t] ?? 'id')), $tables), JSON_THROW_ON_ERROR));
}
function legacyChecksum() {
    global $legacy;
    return json_encode(Db::rows($legacy, 'CHECKSUM TABLE np_comuni_italiani, np_users_type, np_users, st_cariche, st_quote, st_tipo_pagamenti, st_pagamenti, st_verbali, np_settings_webapp, np_merchants, np_stores, np_settings_stores, np_mpk, np_wallets, np_pos EXTENDED'));
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
    // SIP-030 passo 5a: merchant, negozi, impostazioni chiave/valore, xpub e wallet (struttura della v1 di produzione)
    "CREATE TABLE np_merchants (id_merchant int NOT NULL AUTO_INCREMENT, id_user varchar(250) NOT NULL, denomination varchar(250) NOT NULL, vat varchar(250) NOT NULL, address varchar(250) NOT NULL, city varchar(250) NOT NULL, county varchar(250) NOT NULL, cap varchar(250) NOT NULL, deleted int NOT NULL DEFAULT '0', PRIMARY KEY (id_merchant)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3",
    "CREATE TABLE np_stores (id_store int NOT NULL AUTO_INCREMENT, id_merchant int NOT NULL, denomination varchar(250) NOT NULL, address varchar(250) NOT NULL, city varchar(250) NOT NULL, county varchar(250) NOT NULL, cap varchar(250) NOT NULL, vat varchar(250) NOT NULL, bps_storeid varchar(50) NOT NULL, deleted int NOT NULL DEFAULT '0', PRIMARY KEY (id_store)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3",
    "CREATE TABLE np_settings_stores (id_setting int NOT NULL AUTO_INCREMENT, id_store int NOT NULL, setting_name varchar(50) NOT NULL, setting_value varchar(2000) NOT NULL, PRIMARY KEY (id_setting)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3",
    "CREATE TABLE np_mpk (id_mpk int NOT NULL AUTO_INCREMENT, id_store int NOT NULL, asset varchar(20) NOT NULL, AddressType varchar(50) NOT NULL, DerivationScheme varchar(1000) NOT NULL, Addresses varchar(1500) NOT NULL, PRIMARY KEY (id_mpk)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3",
    "CREATE TABLE np_wallets (id_wallet int NOT NULL AUTO_INCREMENT, id_user int NOT NULL, wallet_address varchar(50) NOT NULL, blocknumber varchar(50) NOT NULL, PRIMARY KEY (id_wallet)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3",
    "CREATE TABLE np_pos (id_pos int NOT NULL AUTO_INCREMENT, denomination varchar(300) NOT NULL, id_store int NOT NULL, id_merchant int NOT NULL, pairingCode varchar(10) DEFAULT NULL, deleted int NOT NULL DEFAULT '0', PRIMARY KEY (id_pos)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3",
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
// SIP-030 passo 5a: merchant del titolare 12 (due negozi, uno eliminato), merchant di un utente non importato, store delle quote,
// merchant v1 di un titolare che nella v2 ne ha già uno (collegato); segreti v1 nelle impostazioni dei negozi mai importati
$storeBtc = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789AbCdEfGh';
$memberStore = 'MembershipStore0123456789abcdefghijklmnopq';
$xpub = 'xpub' . str_repeat('6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKrhko4egZHkf', 2) . 'AbCdEfGhJkLmNpQrStUvWxYz';
$wallet = '0x' . str_repeat('aB', 20);
foreach ([
    "INSERT INTO np_merchants VALUES (1,'12','Shop srl','01234567890','Via Shop 1','5','Italia','80100',0),(2,'99','Ghost srl','x','x','x','x','x',0),(3,'16','Legacy name','x','x','x','x','x',0)",
    "INSERT INTO np_stores VALUES (21,1,'Negozio Shop','Via Shop 1','5','Italia','80100','01234567890','$storeBtc',0),(22,1,'Negozio chiuso','x','5','Italia','x','x','',1),"
        . "(23,0,'Iscrizioni Napoli Blockchain','x','x','x','x','x','$memberStore',0),(24,2,'Negozio fantasma','x','x','x','x','x','',0),(25,3,'Negozio collegato','x','5','IT','x','x','',0)",
    "INSERT INTO np_settings_stores (id_store,setting_name,setting_value) VALUES (21,'store_website','www.shop.example'),(21,'CustomLogo','https://napay.napoliblockchain.it/custom/$storeBtc/CustomLogo.png'),"
        . "(21,'readKeySecret','must-not-import'),(21,'writeKeySecret','must-not-import'),(21,'securityToken','must-not-import'),(21,'bps_storeid','$storeBtc'),(22,'store_website','0')",
    "INSERT INTO np_mpk (id_store,asset,AddressType,DerivationScheme,Addresses) VALUES (21,'BTC','p2wpkh','$xpub',''),(25,'BTC','p2pkh','12XQnotAnExtendedKey','')",
    "INSERT INTO np_wallets (id_user,wallet_address,blocknumber) VALUES (12,'$wallet','1'),(16,'not-an-address','1')",
    // POS v1: attivo, eliminato, di negozio eliminato, di merchant scartato, senza nome (con codice di abbinamento v1)
    "INSERT INTO np_pos VALUES (1,'Cassa Shop',21,1,'',0),(2,'Vecchia cassa',21,1,'',1),(3,'Cassa chiuso',22,1,'',0),(4,'Cassa fantasma',24,2,'',0),(5,'  ',25,3,'w1RTz9A',0)",
] as $sql) $legacy->exec($sql);
@mkdir($files . '/custom/' . $storeBtc, 0700, true);
$logoImage = imagecreatetruecolor(900, 300); imagepng($logoImage, $files . '/custom/' . $storeBtc . '/CustomLogo.png'); imagedestroy($logoImage);
file_put_contents($files . '/custom/' . $storeBtc . '/CustomLogo.png', 'trailing-payload', FILE_APPEND);
$db->prepare("INSERT INTO merchants (user_id,description,email,vatNumber,phone,mobile,addressStreet,addressNumberHouse,addressCity,addressZip,addressProvince,addressCountry) "
    . "SELECT id,'V2 company','linked@example.invalid','','','','','','','','','IT' FROM users WHERE email='linked@example.invalid'")->execute();
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
check($report['merchants'] === ['merchants' => 2, 'merchants_linked' => 1, 'merchants_skipped' => 1, 'stores' => 3, 'stores_archived' => 1, 'stores_skipped' => 1,
    'btcpay_stores' => 1, 'websites' => 1, 'xpubs' => 1, 'xpubs_invalid' => 1, 'tts_wallets' => 1, 'tts_wallets_invalid' => 1, 'logos' => 1, 'logos_missing' => 0,
    'terminals' => 4, 'terminals_disabled' => 2, 'terminals_skipped' => 1, 'membership_store' => $memberStore], 'merchants report: imported, linked, skipped owner, archived store, membership store reported');
$merchant = one($db, 'SELECT * FROM merchants WHERE legacy_id=1');
check($merchant['user_id'] === $shop['id'] && $merchant['description'] === 'Shop srl' && $merchant['vatNumber'] === '01234567890' && $merchant['email'] === 'shop@example.invalid'
    && $merchant['addressCity'] === 'Napoli (NA)' && $merchant['addressCountry'] === 'IT', 'merchant: owner, company data, owner email, city name, country code');
check(one($db, 'SELECT description FROM merchants WHERE legacy_id=3')['description'] === 'V2 company' && (int) one($db, "SELECT COUNT(*) AS n FROM merchants WHERE email='linked@example.invalid'")['n'] === 1,
    'merchant already activated in v2 only linked, v2 data kept');
$store = one($db, 'SELECT s.*, ss.* FROM stores s JOIN storesettings ss ON ss.store_id=s.id WHERE s.legacy_id=21');
check($store['merchant_id'] === $merchant['id'] && $store['archived_at'] === null && $store['bps_storeid'] === $storeBtc && $store['website'] === 'https://www.shop.example'
    && $store['derivationScheme'] === $xpub && $store['monitoringExpiration'] === '86400' && $store['customLogo'] === null && $store['defaultLang'] === 'it-IT',
    'store: BTCPay store on the bridge, website, xpub, v2 standard settings, logo computed by the web');
check(one($db, 'SELECT archived_at FROM stores WHERE legacy_id=22')['archived_at'] !== null && one($db, 'SELECT id FROM stores WHERE legacy_id IN (23,24)') === null
    && one($db, 'SELECT derivationScheme FROM storesettings ss JOIN stores s ON s.id=ss.store_id WHERE s.legacy_id=25')['derivationScheme'] === null,
    'deleted store archived; membership store and store of a skipped merchant not imported; invalid xpub ignored');
check(one($db, 'SELECT address FROM store_tts_wallets w JOIN stores s ON s.id=w.store_id WHERE s.legacy_id=21')['address'] === strtolower($wallet)
    && one($db, 'SELECT w.store_id FROM store_tts_wallets w JOIN stores s ON s.id=w.store_id WHERE s.legacy_id IN (22,25)') === null, 'owner TTS wallet copied to active stores only, invalid address skipped');
$logoRow = one($db, 'SELECT * FROM merchant_logos WHERE merchant_id=?', [$merchant['id']]);
check($logoRow !== null && (int) $logoRow['width'] === 600 && (int) $logoRow['height'] === 200 && str_starts_with($logoRow['content'], "\x89PNG") && !str_contains($logoRow['content'], 'trailing-payload'),
    'logo of the first store becomes the merchant logo, re-encoded');
check(one($db, "SELECT COUNT(*) AS n FROM storesettings WHERE CONCAT_WS('|', website, bps_storeid, derivationScheme, customLogo, customCSS, htmlTitle) LIKE '%must-not-import%'")['n'] === '0',
    'v1 exchange keys and tokens never imported');
$tills = Db::rows($db, 'SELECT l.legacy_id, t.name, t.is_enabled, t.merchant_id, t.public_id, s.legacy_id AS store FROM legacy_pos_terminals l JOIN pos_terminals t ON t.id=l.terminal_id'
    . ' JOIN stores s ON s.id=t.store_id AND s.merchant_id=t.merchant_id ORDER BY l.legacy_id');
check(array_map(fn($t) => [(int) $t['legacy_id'], $t['name'], (int) $t['is_enabled'], (int) $t['store']], $tills)
    === [[1, 'Cassa Shop', 1, 21], [2, 'Vecchia cassa', 0, 21], [3, 'Cassa chiuso', 0, 22], [5, 'Cassa 5', 1, 25]],
    'v1 POS imported as tills of their store: deleted POS or archived store disabled, empty name replaced, POS of a skipped merchant not imported');
check($tills[3]['merchant_id'] === one($db, 'SELECT id FROM merchants WHERE legacy_id=3')['id'] && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $tills[0]['public_id']) === 1
    && one($db, 'SELECT COUNT(*) AS n FROM pos_store_operators')['n'] === '0', 'tills belong to the store merchant, new public ID, no operators granted');
check(one($db, "SELECT COUNT(*) AS n FROM pos_terminals WHERE name LIKE '%w1RTz9A%'")['n'] === '0', 'v1 pairing code never imported');
check(one($db, 'SELECT file_sha256 FROM assembly_minutes WHERE legacy_id=1')['file_sha256'] === hash_file('sha256', $files . '/abc123.pdf')
    && one($db, 'SELECT file_content FROM assembly_minutes WHERE legacy_id=2')['file_content'] === null, 'minutes PDF stored, missing file left empty');

$before = digest();
$again = import();
foreach ([11=>['0','1'], 12=>['1','0']] as $legacyUserId=>[$originalType,$changedType]) {
    $typeBefore = digest();
    $legacy->prepare('UPDATE np_users SET corporate=? WHERE id_user=?')->execute([$changedType,$legacyUserId]);
    try {
        rejected(fn()=>import(), 'Legacy cannot convert registered PF/PG: ' . $legacyUserId);
        check(digest() === $typeBefore, 'Rejected member type conversion rolls back the entire import');
    } finally {
        $legacy->prepare('UPDATE np_users SET corporate=? WHERE id_user=?')->execute([$originalType,$legacyUserId]);
    }
}
check($again['users']['created'] === 0 && $again['users']['updated'] === 4 && $again['payments']['imported'] === 5, 'second run updates in place');
check(digest() === $before, 'second run is idempotent (no changes, no duplicates)');
// import una tantum: ciò che esiste nella v2 non viene mai riscritto da un import ripetuto
$legacy->exec("UPDATE np_merchants SET denomination='Changed in v1' WHERE id_merchant=1");
$legacy->exec("UPDATE np_settings_stores SET setting_value='www.changed.example' WHERE id_store=21 AND setting_name='store_website'");
$db->exec("UPDATE stores SET description='Renamed in v2' WHERE legacy_id=21");
$db->exec("UPDATE pos_terminals t JOIN legacy_pos_terminals l ON l.terminal_id=t.id SET t.name='Renamed till', t.is_enabled=0 WHERE l.legacy_id=1");
import();
check(one($db, 'SELECT t.name, t.is_enabled FROM pos_terminals t JOIN legacy_pos_terminals l ON l.terminal_id=t.id WHERE l.legacy_id=1') === ['name' => 'Renamed till', 'is_enabled' => '0'],
    'repeated import never rewrites tills already in v2');
$db->exec("UPDATE pos_terminals t JOIN legacy_pos_terminals l ON l.terminal_id=t.id SET t.name='Cassa Shop', t.is_enabled=1 WHERE l.legacy_id=1");
check(one($db, 'SELECT description FROM merchants WHERE legacy_id=1')['description'] === 'Shop srl' && one($db, 'SELECT description FROM stores WHERE legacy_id=21')['description'] === 'Renamed in v2'
    && one($db, 'SELECT website FROM storesettings ss JOIN stores s ON s.id=ss.store_id WHERE s.legacy_id=21')['website'] === 'https://www.shop.example',
    'repeated import never rewrites merchants, stores or settings already in v2');
$legacy->exec("UPDATE np_merchants SET denomination='Shop srl' WHERE id_merchant=1");
$legacy->exec("UPDATE np_settings_stores SET setting_value='www.shop.example' WHERE id_store=21 AND setting_name='store_website'");
$db->exec("UPDATE stores SET description='Negozio Shop' WHERE legacy_id=21");
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

$legacy->exec("INSERT INTO np_pos VALUES (6,'Cassa senza import',21,1,'',0)");
rejected(fn() => LegacyMerchantImport::reconcile($legacy, $db), 'v1 POS without its till detected by reconciliation');
$legacy->exec('DELETE FROM np_pos WHERE id_pos=6');
$db->exec('UPDATE membership_payments SET amount=99 WHERE legacy_id=100');
rejected(fn() => Importer::reconcile($legacy, $db), 'tampered v2 totals detected by reconciliation');
import();
Importer::reconcile($legacy, $db);
check(true, 'next import restores legacy-authoritative values');

$payments = one($db, 'SELECT COUNT(*) AS total FROM membership_payments')['total'];
$before = digest();
// Reproduce a pre-R2 corrupt fixture only in this disposable DB, then restore the UNIQUE index.
if (one($db, 'SELECT DATABASE() AS name')['name'] !== 'napay_test_bootstrap') exit(1);
$db->exec('ALTER TABLE users DROP INDEX `uq-users-email-normalized`');
try {
$db->exec("INSERT INTO users (username,email,password,first_name,last_name,oauth_provider,oauth_uid,authKey,accessToken,picture,privilege_id,is_merchant,is_active) SELECT 'twin-1', 'twin@example.invalid', password, first_name, last_name, oauth_provider, oauth_uid, authKey, accessToken, picture, privilege_id, is_merchant, is_active FROM users WHERE email='linked@example.invalid'");
$db->exec("INSERT INTO users (username,email,password,first_name,last_name,oauth_provider,oauth_uid,authKey,accessToken,picture,privilege_id,is_merchant,is_active) SELECT 'twin-2', 'twin@example.invalid', password, first_name, last_name, oauth_provider, oauth_uid, authKey, accessToken, picture, privilege_id, is_merchant, is_active FROM users WHERE email='linked@example.invalid'");
$twins = digest();
$legacy->exec("INSERT INTO np_users VALUES (17,5,5,'twin@example.invalid','x',NULL,'Twin','Fixture','0',NULL,'','','','','','',1)");
$legacy->exec("INSERT INTO st_pagamenti VALUES (108,17,1,2023,11,20,2,'2023-04-01','2023-01-01','2023-12-31','x','0','paid')");
rejected(fn() => import(), 'ambiguous v2 email blocks the import');
check(digest() === $twins && one($db, 'SELECT COUNT(*) AS total FROM membership_payments')['total'] === $payments, 'failed import rolled back completely');
} finally {
    $legacy->exec('DELETE FROM np_users WHERE id_user=17');
    $legacy->exec('DELETE FROM st_pagamenti WHERE id_pagamento=108');
    $db->exec("DELETE FROM users WHERE username IN ('twin-1','twin-2') AND email='twin@example.invalid'");
    $db->exec('ALTER TABLE users ADD UNIQUE INDEX `uq-users-email-normalized` (email_normalized)');
}
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
