<?php
namespace app\commands;

use app\components\btcpay\BridgePosApps;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * SIP-030 passo 5: comando one-shot del cron per i negozi v1 sul ponte BTCPay (webhook e app POS dei terminali, vedi
 * BridgePosApps). Nessun daemon; senza chiave ponte o istanza la funzione è spenta. Output solo conteggi.
 */
final class BtcpayBridgeController extends Controller
{
    public bool $once = false;
    public int $limit = 25;
    public function options($actionID): array { return ['once', 'limit']; }

    public function actionSync(): int
    {
        if (!$this->once) {
            $this->stderr("Usage: php yii btcpay-bridge/sync --once [--limit=25]\n");
            return ExitCode::USAGE;
        }
        try {
            $bridge = BridgePosApps::fromEnv(\Yii::$app->db);
            if ($bridge === null) {
                $this->stdout("disabled\n");
                return ExitCode::OK;
            }
            $report = $bridge->run($this->limit);
        } catch (\Throwable $error) {
            // nessun dettaglio di rete, DB, configurazione o chiave in uscita
            $this->stderr("failed\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout(implode(' ', array_map(fn($k, $v) => $k . '=' . $v, array_keys($report), $report)) . "\n");
        return ExitCode::OK;
    }
}
