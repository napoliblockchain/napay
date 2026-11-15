<?php
namespace app\commands;

use app\components\pos\tts\TtsPayments;
use yii\console\Controller;
use yii\console\ExitCode;

/** SIP-021 fase 3: comando one-shot per le richieste TTS scadute (verifica finale sulla chain, poi chiusura). Nessun daemon. */
final class TtsPaymentsController extends Controller
{
    public bool $once = false;
    public int $limit = 100;
    public function options($actionID): array { return ['once', 'limit']; }

    public function actionSweep(): int
    {
        if (!$this->once) {
            $this->stderr("Usage: php yii tts-payments/sweep --once [--limit=100]\n");
            return ExitCode::USAGE;
        }
        try {
            $result = TtsPayments::sweep($this->limit);
        } catch (\Throwable $error) {
            // nessun dettaglio di chain, DB o configurazione in uscita
            $this->stderr("failed\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout(sprintf("checked=%d paid=%d expired=%d\n", $result['checked'], $result['paid'], $result['expired']));
        return ExitCode::OK;
    }
}
