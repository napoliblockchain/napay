<?php
namespace app\commands;

use app\components\btcpay\provisioning\{ProvisioningHttpClient, ProvisioningJobs, ProvisioningStore, ProvisioningWorker, WorkerSecrets};
use app\components\btcpay\provisioning\steps\MerchantAccountStep;
use app\components\db\V2Bootstrap;
use yii\console\Controller;

/** Initial one-shot account executor only. No daemon, migrations or web application credentials. */
final class BtcpayProvisionController extends Controller
{
    public bool $once = false;
    public function options($actionID): array { return ['once']; }

    public function actionRun(): int
    {
        if (!$this->once || \Yii::$app->id !== 'napay-btcpay-worker' || env('NAPAY_DB_ROLE') !== 'worker'
            || env('NAPAY_DB_ROLE_MODE') !== 'true') throw new \RuntimeException('dedicated_worker_console_required');
        $db = \Yii::$app->db;
        V2Bootstrap::readyRestricted($db->getMasterPdo(), dirname(__DIR__), 'worker');
        $origin = env_required('BTCPAY_PROVISION_ORIGIN');
        $ca = env('BTCPAY_PROVISION_CA_FILE', '');
        $http = new ProvisioningHttpClient($origin, $ca === '' ? null : $ca);
        $secrets = WorkerSecrets::fromFiles(env_required('BTCPAY_PROVISIONER_KEY_FILE'), env_required('BTCPAY_KEK_KEYRING_FILE'));
        $instance = hash('sha256', $origin);
        $jobs = new ProvisioningJobs($db, self::owner());
        $store = new ProvisioningStore($db, $instance, $secrets->keyring(), $secrets->current);
        $step = new MerchantAccountStep($http->withKey(...), $http->withBasic(...));
        $worker = new ProvisioningWorker($db, $jobs, $store, $step, $instance, $secrets->provisionerKey());
        $this->stdout($worker->runOnce() ? "job-processed\n" : "idle\n");
        return 0;
    }

    private static function owner(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
