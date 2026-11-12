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
localCheck($names === ['bootstrap', 'egress', 'mysql', 'web']);
localCheck(empty($services['web']['ports']) && empty($services['mysql']['ports']));
localCheck($config['networks']['database']['internal'] === true);
// browser network: internal, created outside Compose (start-localhost.sh / local.sh), shared only with napay-gateway
localCheck($config['networks']['napay_local_browser']['external'] === true
    && $config['networks']['napay_local_browser']['name'] === 'napay_local_browser');
$webNetworks = array_keys($services['web']['networks']); sort($webNetworks);
localCheck($webNetworks === ['database', 'egress', 'napay_local_browser']);
localCheck($services['web']['networks']['napay_local_browser']['aliases'] === ['napay-local-web']);
localCheck(array_keys($services['mysql']['networks']) === ['database']);
// proxy di uscita: unico con internet, web raggiunge solo lui (rete interna), nessuna porta pubblicata
$egress = $services['egress'];
$egressNetworks = array_keys($egress['networks']); sort($egressNetworks);
localCheck($egressNetworks === ['egress', 'internet'] && $config['networks']['egress']['internal'] === true && empty($config['networks']['internet']['internal']));
localCheck(empty($egress['ports']) && $egress['read_only'] === true && $egress['cap_drop'] === ['ALL']);
localCheck($services['web']['environment']['PAYMENT_PROXY'] === 'http://egress:3128');
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
echo "PASS {$checks} local sandbox configuration checks\n";
