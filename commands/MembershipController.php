<?php
namespace app\commands;

use app\components\association\MembershipReminders;
use app\components\association\MembershipRequests;
use yii\console\Controller;
use yii\console\ExitCode;

/** SIP-030: comandi one-shot del container cron (account mai verificati, promemoria della quota). Nessun daemon. */
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

    /** Primo promemoria automatico della quota, una volta per scadenza (orario dal container cron). */
    public function actionSendReminders(): int
    {
        if (!$this->once) {
            $this->stderr("Usage: php yii membership/send-reminders --once\n");
            return ExitCode::USAGE;
        }
        try {
            $sent = MembershipReminders::sendAutomatic(date('Y-m-d'));
        } catch (\Throwable $error) {
            $this->stderr("failed\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout(sprintf("sent=%d\n", $sent));
        return ExitCode::OK;
    }
}
