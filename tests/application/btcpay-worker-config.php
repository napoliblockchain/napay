<?php
// All files are fake per-run inputs on the sole writable fixture mount.
if (PHP_SAPI !== 'cli') exit(1);
ini_set('zend.exception_ignore_args', '0');
$root = dirname(__DIR__, 2);
if (($argv[1] ?? '') === '--config') {
    try {
        define('YII_ENABLE_ERROR_HANDLER', false);
        require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
        $config = require $root . '/config/btcpay-worker.php';
        if (isset($config['params']['secret_hash_key']) || ($config['components']['log']['targets'][0]['logVars'] ?? null) !== []
            || isset($config['components']['user']) || isset($config['components']['mailer'])) exit(2);
        $app = new yii\console\Application($config);
        if ($app->db->username !== 'napay_worker' || $app->db->password !== trim(file_get_contents('/run/secrets/napay_db_worker_password'))) exit(2);
        echo "ready\n"; exit(0);
    } catch (Throwable $e) {
        if ($e->getMessage() !== 'invalid_btcpay_worker_environment' || $e->getPrevious() !== null) exit(2);
        echo "rejected\n"; exit(42);
    }
}
require $root . '/components/db/DatabaseSecret.php';
require $root . '/components/btcpay/provisioning/WorkerSecrets.php';
use app\components\btcpay\provisioning\WorkerSecrets;
umask(0077);
$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); ++$checks; echo "PASS: $label\n"; }
$provisioner = bin2hex(random_bytes(32)); $key = random_bytes(32);
file_put_contents('/run/secrets/provisioner', $provisioner . "\n");
$valid = ['current' => 'k1', 'keys' => ['k1' => base64_encode($key)]];
$write = static fn($json) => file_put_contents('/run/secrets/ring', json_encode($json, JSON_THROW_ON_ERROR));
$write($valid);
$secrets = WorkerSecrets::fromFiles('/run/secrets/provisioner', '/run/secrets/ring');
check($secrets->current === 'k1' && $secrets->provisionerKey() === $provisioner && $secrets->keyring() === ['k1' => $key], 'Private worker secrets load without application keys');
$dump = print_r($secrets, true) . var_export($secrets, true) . json_encode($secrets);
ob_start(); var_dump($secrets); $dump .= ob_get_clean();
check(!str_contains($dump, $key) && !str_contains($dump, $provisioner) && !str_contains($dump, base64_encode($key)), 'Secret object dumps contain no provisioner or KEK');
$two = $valid; $two['current'] = 'k2'; $two['keys']['k2'] = base64_encode(random_bytes(32)); $write($two);
$loaded = WorkerSecrets::fromFiles('/run/secrets/provisioner', '/run/secrets/ring');
check($loaded->current === 'k2' && count($loaded->keyring()) === 2 && $loaded->keyring()['k1'] === $key, 'Multiple KEK versions retain old decryption keys without rotation');
$write($valid);
$reject = static function (string $label, string $file = '/run/secrets/ring', string $prov = '/run/secrets/provisioner') use ($key, $provisioner): void {
    $ok = false;
    try { WorkerSecrets::fromFiles($prov, $file); } catch (RuntimeException $e) {
        $trace = print_r($e->getTrace(), true);
        $ok = $e->getMessage() === 'invalid_btcpay_worker_secrets' && $e->getPrevious() === null
            && !str_contains($trace, $key) && !str_contains($trace, $provisioner) && !str_contains($trace, base64_encode($key));
    }
    check($ok, $label);
};
$reject('Missing ring refused', '/run/secrets/missing');
$reject('Path traversal refused', '/run/secrets/../secrets/ring');
$reject('Non-secret path refused', '/app/composer.json');
$reject('Same file cannot serve as provisioner and keyring', '/run/secrets/ring', '/run/secrets/ring');
$reject('Missing provisioner refused', '/run/secrets/ring', '/run/secrets/no_provisioner');
symlink('/run/secrets/provisioner', '/run/secrets/provisioner_link');
$reject('Symlink provisioner refused', '/run/secrets/ring', '/run/secrets/provisioner_link');
symlink('/run/secrets/ring', '/run/secrets/link'); $reject('Symlink ring refused', '/run/secrets/link');
chmod('/run/secrets/ring', 0644); $reject('Public ring refused'); chmod('/run/secrets/ring', 0600);
foreach ([[], ['current'=>'k2','keys'=>$valid['keys']], ['current'=>'k1','keys'=>[]], ['current'=>'bad space','keys'=>['bad space'=>base64_encode($key)]],
    ['current'=>'k1','keys'=>['k1'=>'short']], ['current'=>'k1','keys'=>['k1'=>rtrim(base64_encode($key),'=')]],
    ['current'=>'k1','keys'=>['k1'=>base64_encode($key)], 'extra'=>'x'], ['current'=>'k1','keys'=>[base64_encode($key)]]] as $bad) {
    $write($bad); $reject('Malformed keyring rejected without secret traces');
}
file_put_contents('/run/secrets/ring', '{broken'); $reject('Invalid JSON refused');
file_put_contents('/run/secrets/ring', str_repeat(' ', 8193)); $reject('Oversize ring refused');
$many = []; for ($i=0;$i<17;++$i) $many['k'.$i] = base64_encode(random_bytes(32));
$write(['current'=>'k0','keys'=>$many]); $reject('More than sixteen KEKs refused');
$write($valid); chmod('/run/secrets/provisioner', 0644); $reject('Public provisioner refused'); chmod('/run/secrets/provisioner', 0600);
file_put_contents('/run/secrets/provisioner', "short\n"); $reject('Short provisioner refused');
file_put_contents('/run/secrets/provisioner', str_repeat(' ',32)); $reject('Whitespace provisioner refused');
file_put_contents('/run/secrets/provisioner', $provisioner);
file_put_contents('/run/secrets/napay_db_worker_password', bin2hex(random_bytes(32)));
$base = ['NAPAY_DB_ROLE_MODE'=>'true','NAPAY_DB_ROLE'=>'worker','NAPAY_DB_PASSWORD_FILE'=>'/run/secrets/napay_db_worker_password',
    'DB_DSN'=>'mysql:host=mysql;dbname=fixture','DB_USERNAME'=>'napay_worker','APP_DEBUG'=>'false'];
$config = static function (array $extra, int $expected): void { global $base;
    $process = proc_open([PHP_BINARY,__FILE__,'--config'], [0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','a']], $pipes, null, array_merge($base,$extra));
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); fclose($pipes[1]); $code=proc_close($process);
    check($code === $expected && $out === ($expected === 0 ? "ready\n" : "rejected\n"), $expected === 0 ? 'Minimal worker console works without web keys or admin identity' : 'Privileged or malformed worker environment refused');
};
$config([],0);
foreach (['DB_PASSWORD','SECRET_HASH_KEY','COOKIE_VALIDATION_KEY','NAPAY_WEB_DB_PASSWORD','NAPAY_MYSQL_ROOT_SECRET_FILE','NAPAY_DB_MIGRATOR_SECRET_FILE'] as $name) $config([$name=>'fixture-not-real'],42);
foreach ([['NAPAY_DB_ROLE'=>'web'],['NAPAY_DB_ROLE_MODE'=>'false'],['NAPAY_DB_PASSWORD_FILE'=>'/run/secrets/mysql_root_password'],['DB_USERNAME'=>'root'],['APP_DEBUG'=>'true']] as $bad) $config($bad,42);
file_put_contents('/run/secrets/mysql_root_password', bin2hex(random_bytes(32))); $config([],42); unlink('/run/secrets/mysql_root_password');
file_put_contents('/run/secrets/napay_db_migrator_password', bin2hex(random_bytes(32))); $config([],42); unlink('/run/secrets/napay_db_migrator_password');
echo "PASS $checks worker configuration/secret checks\n";
