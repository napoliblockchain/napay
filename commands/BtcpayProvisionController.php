<?php
namespace app\commands;

use app\components\btcpay\provisioning\{ProvisioningHttpClient, ProvisioningJobs, ProvisioningLinkWorker, ProvisioningSettingsWorker, ProvisioningStore, ProvisioningWorker, WorkerSecrets};
use app\components\btcpay\provisioning\steps\{MerchantAccountStep, PosLinkStep, StoreArchiveStep, StoreLinkStep, StoreSettingsStep, StoreWalletStep};
use app\components\db\V2Bootstrap;
use yii\console\Controller;

/** One-shot executors (account, links, GET-only link reconciliation). No daemon, migrations or web application credentials. */
final class BtcpayProvisionController extends Controller
{
    public bool $once = false;
    public function options($actionID): array { return ['once']; }

    /** Lavori merchant_account (A, B, C). */
    public function actionRun(): int
    {
        [$db, $http, $instance] = $this->worker();
        $secrets = WorkerSecrets::fromFiles(env_required('BTCPAY_PROVISIONER_KEY_FILE'), env_required('BTCPAY_KEK_KEYRING_FILE'));
        $jobs = new ProvisioningJobs($db, self::owner());
        $store = new ProvisioningStore($db, $instance, $secrets->keyring(), $secrets->current);
        $step = new MerchantAccountStep($http->withKey(...), $http->withBasic(...));
        $worker = new ProvisioningWorker($db, $jobs, $store, $step, $instance, $secrets->provisionerKey());
        return $this->report($worker->runOnce());
    }

    /**
     * W3e: un lavoro store_link/pos_link (con la chiave corrente del merchant, mai quella del provisioner); se non ce ne
     * sono, un lavoro store_update, store_archive o store_wallet (SIP-022: impostazioni, archiviazione e xpub dello store).
     */
    public function actionLinks(): int
    {
        if ($this->linkWorker()->runOnce()) return $this->report(true);
        [$db, $http, $instance] = $this->worker();
        $secrets = WorkerSecrets::keyringOnly(env_required('BTCPAY_KEK_KEYRING_FILE'));
        $store = new ProvisioningStore($db, $instance, $secrets->keyring(), $secrets->current);
        $settings = new ProvisioningSettingsWorker($db, new ProvisioningJobs($db, self::owner()), $store, new StoreSettingsStep($http->withKey(...)), $instance,
            archiveStep: new StoreArchiveStep($http->withKey(...)), walletStep: new StoreWalletStep($http->withKey(...)));
        return $this->report($settings->runOnce());
    }

    /** W3e: una riconciliazione GET-only di store_link/pos_link. */
    public function actionReconcile(): int
    {
        return $this->report($this->linkWorker()->reconcileOnce());
    }

    private function linkWorker(): ProvisioningLinkWorker
    {
        [$db, $http, $instance] = $this->worker();
        $secrets = WorkerSecrets::keyringOnly(env_required('BTCPAY_KEK_KEYRING_FILE'));
        $store = new ProvisioningStore($db, $instance, $secrets->keyring(), $secrets->current);
        return new ProvisioningLinkWorker($db, new ProvisioningJobs($db, self::owner()), $store,
            new StoreLinkStep($http->withKey(...)), new PosLinkStep($http->withKey(...)), $instance);
    }

    /** Console dedicata, readiness ristretta e client HTTP; i segreti li legge ogni azione, solo quelli che le servono. */
    private function worker(): array
    {
        if (!$this->once || \Yii::$app->id !== 'napay-btcpay-worker' || env('NAPAY_DB_ROLE') !== 'worker'
            || env('NAPAY_DB_ROLE_MODE') !== 'true') throw new \RuntimeException('dedicated_worker_console_required');
        $db = \Yii::$app->db;
        V2Bootstrap::readyRestricted($db->getMasterPdo(), dirname(__DIR__), 'worker');
        $origin = env_required('BTCPAY_PROVISION_ORIGIN');
        $ca = env('BTCPAY_PROVISION_CA_FILE', '');
        $http = new ProvisioningHttpClient($origin, $ca === '' ? null : $ca);
        return [$db, $http, hash('sha256', $origin)];
    }

    private function report(bool $processed): int
    {
        $this->stdout($processed ? "job-processed\n" : "idle\n");
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
