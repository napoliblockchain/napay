<?php
namespace app\commands;

use app\components\pos\btc\BtcPayments;
use yii\console\Controller;
use yii\console\ExitCode;

/** SIP-033: comando one-shot per i pagamenti BTC del POS oltre la scadenza (stato da BTCPay, creazioni incerte chiuse). Nessun daemon. */
final class BtcPaymentsController extends Controller
{
    public bool $once = false;
    public int $limit = 100;
    public function options($actionID): array { return ['once', 'limit']; }

    public function actionSweep(): int
    {
        if (!$this->once) {
            $this->stderr("Usage: php yii btc-payments/sweep --once [--limit=100]\n");
            return ExitCode::USAGE;
        }
        try {
            $result = BtcPayments::sweep($this->limit);
        } catch (\Throwable $error) {
            // nessun dettaglio di rete, DB o configurazione in uscita
            $this->stderr("failed\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout(sprintf("checked=%d settled=%d closed=%d\n", $result['checked'], $result['settled'], $result['closed']));
        return ExitCode::OK;
    }
}
