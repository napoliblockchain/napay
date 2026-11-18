<?php
// Disposable CLI proof only: enqueue an ineligible/nonexistent merchant, never call BTCPay.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
try {
    require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
    $app = new yii\console\Application(require $root . '/config/console.php');
    $db = $app->db;
    $instance = hash('sha256', 'https://btcpay.regtest.invalid');
    $merchant = 2147483647;
    if ($db->createCommand('SELECT COUNT(*) FROM merchants WHERE id=:id', [':id'=>$merchant])->queryScalar() != 0) exit(1);
    if (($argv[1] ?? '') === '--seed') {
        $db->createCommand()->insert('btcpay_provisioning_jobs', ['kind'=>'merchant_account','instance'=>$instance,'merchant_id'=>$merchant,'entity_id'=>$merchant,
            'generation'=>1,'created_at'=>time(),'updated_at'=>time()])->execute();
    } elseif (($argv[1] ?? '') === '--verify') {
        $job = $db->createCommand('SELECT state,last_error_code FROM btcpay_provisioning_jobs WHERE instance=:i AND merchant_id=:m', [':i'=>$instance,':m'=>$merchant])->queryOne();
        if ($job === false || $job['state'] !== 'failed' || $job['last_error_code'] !== 'merchant_ineligible'
            || $db->createCommand('SELECT COUNT(*) FROM btcpay_merchant_accounts WHERE merchant_id=:m', [':m'=>$merchant])->queryScalar() != 0) exit(1);
    } else exit(1);
} catch (Throwable $error) { fwrite(STDERR,"CLI fixture failed.\n"); exit(1); }
