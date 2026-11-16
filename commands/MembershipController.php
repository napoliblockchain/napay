<?php
namespace app\commands;

use app\components\association\MembershipRequests;
use yii\console\Controller;
use yii\console\ExitCode;

/** SIP-030: comando one-shot che elimina gli account registrati dal sito e mai verificati da oltre 7 giorni. Nessun daemon. */
final class MembershipController extends Controller
{
    public bool $once = false;
    public function options($actionID): array { return ['once']; }

    public function actionPurgeUnverified(): int
    {
        if (!$this->once) {
            $this->stderr("Usage: php yii membership/purge-unverified --once\n");
            return ExitCode::USAGE;
        }
        try {
            $deleted = MembershipRequests::purgeUnverified(time());
        } catch (\Throwable $error) {
            $this->stderr("failed\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout(sprintf("deleted=%d\n", $deleted));
        return ExitCode::OK;
    }
}
