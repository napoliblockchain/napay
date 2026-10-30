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
localCheck($names === ['bootstrap', 'gateway', 'mysql', 'web']);
localCheck(empty($services['web']['ports']) && empty($services['mysql']['ports']));
foreach (['browser', 'database'] as $network) localCheck($config['networks'][$network]['internal'] === true);
$webNetworks = array_keys($services['web']['networks']); sort($webNetworks);
localCheck($webNetworks === ['browser', 'database']);
$gatewayNetworks = array_keys($services['gateway']['networks']); sort($gatewayNetworks);
localCheck($gatewayNetworks === ['browser', 'ingress']);
localCheck(empty($config['networks']['ingress']['internal']));
localCheck(array_keys($services['mysql']['networks']) === ['database']);
$ports = $services['gateway']['ports'];
localCheck(count($ports) === 2);
foreach ($ports as $port) {
    localCheck($port['host_ip'] === '127.0.0.1' && in_array((string) $port['published'], ['18121', '18444'], true));
}
localCheck($config['volumes']['mysql_data']['external'] === true
    && $config['volumes']['mysql_data']['name'] === 'napay_browser_local_mysql_data');
foreach (['web', 'bootstrap'] as $service) {
    $env = $services[$service]['environment'];
    localCheck($env['DB_DATABASE'] === 'napay_browser_local' && $env['NAPAY_LOCAL_BROWSER'] === 'isolated-copy');
    localCheck($env['MAIL_TRANSPORT'] === 'file' && $env['HTTPS_ONLY'] === 'true');
    localCheck($env['WEBAPP_LINK'] === 'https://localhost:18444');
}
foreach ($services['web']['volumes'] as $mount) {
    if ($mount['target'] === '/var/www/html') localCheck($mount['read_only'] === true);
    localCheck($mount['target'] !== '/import');
}
localCheck($services['gateway']['depends_on']['web']['condition'] === 'service_healthy');
localCheck($services['web']['depends_on']['bootstrap']['condition'] === 'service_completed_successfully');
echo "PASS {$checks} local sandbox configuration checks\n";
