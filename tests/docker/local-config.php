<?php
// Feed Compose JSON through stdin only: it contains private environment values.
if (PHP_SAPI !== 'cli') exit(1);
$config = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$checks = 0;
function localCheck(bool $condition): void {
    global $checks;
    if (!$condition) throw new RuntimeException('Local sandbox isolation check failed.');
    ++$checks;
}
$services = $config['services'];
$names = array_keys($services); sort($names);
$roles = ($services['web']['environment']['NAPAY_DB_ROLE_MODE'] ?? '') === 'true';
$expected = $roles ? ['bootstrap', 'cron', 'egress', 'mysql', 'web', 'worker-db-check'] : ['bootstrap', 'cron', 'egress', 'mysql', 'web'];
// SIP-022 B5: worker BTCPay opzionale (solo con la chiave del provisioner), sempre con i ruoli separati
$btcpayWorker = isset($services['btcpay-worker']);
if ($btcpayWorker) { $expected[] = 'btcpay-worker'; sort($expected); }
localCheck($names === $expected);
if ($btcpayWorker) {
    localCheck($roles);
    $bw = $services['btcpay-worker']; $bwEnv = $bw['environment'];
    $bwNetworks = array_keys($bw['networks']); sort($bwNetworks);
    $bwSecrets = array_column($bw['secrets'], 'source'); sort($bwSecrets);
    localCheck($bwNetworks === ['database', 'egress'] && $bwSecrets === ['btcpay_kek_keyring', 'btcpay_provisioner_key', 'btcpay_store_webhook_key', 'napay_db_worker_password']);
    localCheck($bwEnv['BTCPAY_STORE_WEBHOOK_URL'] === 'https://napay.local/api/btcpay/v1/store-webhook' && $bwEnv['BTCPAY_STORE_WEBHOOK_KEY_FILE'] === '/run/secrets/btcpay_store_webhook_key'
        && !isset($bwEnv['BTCPAY_STORE_WEBHOOK_KEY']) && preg_match('/^[0-9a-f]{64}$/D', (string) ($services['web']['environment']['BTCPAY_STORE_WEBHOOK_KEY'] ?? '')) === 1);
    localCheck(empty($bw['ports']) && $bw['read_only'] === true && $bw['cap_drop'] === ['ALL']);
    localCheck($bwEnv['NAPAY_DB_ROLE'] === 'worker' && $bwEnv['DB_DATABASE'] === 'napay_browser_local' && $bwEnv['PAYMENT_PROXY'] === 'http://egress:3128'
        && $bwEnv['BTCPAY_PROVISION_ORIGIN'] === 'https://btcpay.local' && $bwEnv['BTCPAY_PROVISION_CA_FILE'] === '/run/btcpay-ca.crt' && !isset($bwEnv['DB_PASSWORD']));
    foreach ($bw['volumes'] as $mount) {
        if ($mount['target'] === '/app') localCheck($mount['read_only'] === true);
        localCheck($mount['target'] !== '/import');
    }
    // il web vede solo il digest e l'origine pubblici, mai i segreti del worker
    localCheck(($services['web']['environment']['BTCPAY_PROVISION_INSTANCE'] ?? '') === hash('sha256', 'https://btcpay.local') && empty($services['web']['secrets']));
    // SIP-033: web e cron creano/leggono le fatture POS su BTCPay solo tramite il proxy, con la CA locale e l'origine del digest
    foreach (['web', 'cron'] as $service) {
        $env = $services[$service]['environment'];
        localCheck(($env['BTCPAY_PROVISION_ORIGIN'] ?? '') === 'https://btcpay.local' && ($env['BTCPAY_PROVISION_CA_FILE'] ?? '') === '/run/btcpay-ca.crt'
            && ($env['BTCPAY_PROVISION_INSTANCE'] ?? '') === hash('sha256', 'https://btcpay.local') && ($env['PAYMENT_PROXY'] ?? '') === 'http://egress:3128'
            && empty($services[$service]['secrets']) && !isset($env['BTCPAY_PROVISIONER_KEY_FILE']) && !isset($env['BTCPAY_KEK_KEYRING_FILE']));
    }
}
if ($roles) {
    localCheck(empty($services['web']['secrets']) && empty($services['web']['environment']['NAPAY_WEB_DB_PASSWORD']));
    // cron: stesso utente DB del web, nessun segreto
    localCheck(($services['cron']['environment']['NAPAY_DB_ROLE'] ?? '') === 'web' && empty($services['cron']['secrets']));
    localCheck(array_keys($services['worker-db-check']['networks']) === ['database']);
    localCheck(array_column($services['worker-db-check']['secrets'], 'source') === ['napay_db_worker_password']);
}
localCheck(empty($services['web']['ports']) && empty($services['mysql']['ports']));
localCheck($config['networks']['database']['internal'] === true);
// browser network: internal, created outside Compose (start-localhost.sh / local.sh), shared only with napay-gateway
localCheck($config['networks']['napay_local_browser']['external'] === true
    && $config['networks']['napay_local_browser']['name'] === 'napay_local_browser');
$webNetworks = array_keys($services['web']['networks']); sort($webNetworks);
localCheck($webNetworks === ['database', 'egress', 'napay_local_browser', 'napay_local_chain']);
// chain di sviluppo: rete interna esterna al compose, condivisa solo con wallet-besu
localCheck($config['networks']['napay_local_chain']['external'] === true && $config['networks']['napay_local_chain']['name'] === 'napay_local_chain');
localCheck($services['web']['networks']['napay_local_browser']['aliases'] === ['napay-local-web']);
localCheck(array_keys($services['mysql']['networks']) === ['database']);
// proxy di uscita: unico con internet, web raggiunge solo lui (rete interna), nessuna porta pubblicata
$egress = $services['egress'];
$egressNetworks = array_keys($egress['networks']); sort($egressNetworks);
localCheck($egressNetworks === ['btcpay_regtest_client', 'egress', 'internet'] && $config['networks']['egress']['internal'] === true && empty($config['networks']['internet']['internal']));
localCheck($config['networks']['btcpay_regtest_client']['external'] === true
    && $config['networks']['btcpay_regtest_client']['name'] === 'btcpay_regtest_client');
localCheck(empty($egress['ports']) && $egress['read_only'] === true && $egress['cap_drop'] === ['ALL']);
localCheck($services['web']['environment']['PAYMENT_PROXY'] === 'http://egress:3128');
// senza launcher dell'orchestratore: pagamenti TTS spenti (tutte vuote) oppure configurazione completa
$tts = array_map(fn($name) => (string) ($services['web']['environment'][$name] ?? ''), ['TTS_RPC_URL', 'TTS_CHAIN_ID', 'TTS_TOKEN_ADDRESS']);
localCheck($tts === ['', '', ''] || ($tts[0] === 'http://wallet-besu:8545' && $tts[1] === '2118' && preg_match('/^0x[0-9a-fA-F]{40}$/D', $tts[2]) === 1));
localCheck($config['volumes']['mysql_data']['external'] === true
    && $config['volumes']['mysql_data']['name'] === 'napay_browser_local_mysql_data');
foreach (['web', 'bootstrap'] as $service) {
    $env = $services[$service]['environment'];
    localCheck($env['DB_DATABASE'] === 'napay_browser_local' && $env['NAPAY_LOCAL_BROWSER'] === 'isolated-copy');
    localCheck($env['MAIL_TRANSPORT'] === 'file' && $env['HTTPS_ONLY'] === 'true');
    localCheck($env['WEBAPP_LINK'] === 'https://napay.local');
}
foreach ($services['web']['volumes'] as $mount) {
    if ($mount['target'] === '/var/www/html') localCheck($mount['read_only'] === true);
    localCheck($mount['target'] !== '/import');
}
localCheck($services['web']['depends_on']['bootstrap']['condition'] === 'service_completed_successfully');
// cron: niente porte né Internet (database e chain di sviluppo; con il worker BTCPay anche il proxy di uscita, rete interna,
// per la scadenza dei pagamenti BTC - SIP-033), codice in sola lettura, stessa sandbox del web
$cron = $services['cron'];
$cronNetworks = array_keys($cron['networks']); sort($cronNetworks);
localCheck(empty($cron['ports']) && $cronNetworks === ($btcpayWorker ? ['database', 'egress', 'napay_local_chain'] : ['database', 'napay_local_chain']));
localCheck($cron['environment']['DB_DATABASE'] === 'napay_browser_local' && $cron['environment']['MAIL_TRANSPORT'] === 'file'
    && $cron['environment']['WEBAPP_LINK'] === 'https://napay.local');
foreach ($cron['volumes'] as $mount) {
    if ($mount['target'] === '/var/www/html') localCheck($mount['read_only'] === true);
    localCheck($mount['target'] !== '/import');
}
echo "PASS {$checks} local sandbox configuration checks\n";
