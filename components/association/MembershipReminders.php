<?php

namespace app\components\association;

use Yii;
use yii\db\Expression;
use yii\db\Query;
use app\components\Log;
use app\components\sendMail;
use app\models\MemberProfiles;
use app\models\Users;

/**
 * Promemoria della quota associativa (SIP-030 punto 2). Il primo parte da solo 30 giorni prima della scadenza, una
 * volta per scadenza (comando giornaliero del container cron); i successivi li invia l'admin dalla lista, con almeno
 * 7 giorni tra due invii allo stesso socio. Esclusi: amministratori (esenti), account non attivi o in attesa di
 * approvazione, soci che non hanno mai pagato, decaduti da oltre un anno e chi ha disattivato i promemoria.
 */
final class MembershipReminders
{
    public const AUTO_DAYS = 30;
    public const LIST_DAYS = 45;
    public const LAPSED_DAYS = 365;
    public const PAUSE_DAYS = 7;
    private const TABLE = '{{%membership_reminders}}';

    /** Soci in lista: quota in scadenza entro 45 giorni o scaduta da meno di un anno. */
    public static function query(string $today): Query
    {
        $expiry = new Expression("(SELECT MAX(mp.expires_on) FROM {{%membership_payments}} mp WHERE mp.user_id = u.id AND mp.status = 'paid')");
        $inner = (new Query())
            ->select(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'p.is_corporate', 'p.denomination', 'p.reminders_opt_out', 'last_expiry' => $expiry])
            ->from(['u' => Users::tableName()])
            ->innerJoin(['p' => MemberProfiles::tableName()], 'p.user_id = u.id')
            ->innerJoin(['pr' => '{{%privileges}}'], 'pr.id = u.privilege_id')
            ->where(['u.is_active' => Users::STATUS_ACTIVE, 'p.approval_state' => MemberProfiles::APPROVAL_APPROVED])
            ->andWhere(['<', 'pr.level', 50]);
        return (new Query())->from(['m' => $inner])
            ->where(['not', ['m.last_expiry' => null]])
            ->andWhere(['>=', 'm.last_expiry', self::shift($today, -self::LAPSED_DAYS)])
            ->andWhere(['<=', 'm.last_expiry', self::shift($today, self::LIST_DAYS)])
            ->orderBy(['m.last_expiry' => SORT_ASC, 'm.id' => SORT_ASC]);
    }

    /** Righe della lista con stato, invii nel periodo e ultimo invio. */
    public static function rows(string $today): array
    {
        $rows = self::query($today)->all();
        foreach ($rows as &$row) {
            $sent = (new Query())->from(self::TABLE)->where(['user_id' => $row['id'], 'expires_on' => $row['last_expiry']]);
            $row['reminders'] = (int) (clone $sent)->count();
            $row['last_sent'] = (clone $sent)->max('sent_at');
            $row['status'] = $row['last_expiry'] < $today ? 'expired' : 'expiring';
        }
        unset($row);
        return $rows;
    }

    /** Comando giornaliero: primo promemoria a chi scade entro 30 giorni, una sola volta per scadenza. @return int inviati */
    public static function sendAutomatic(string $today): int
    {
        $sent = 0;
        $due = self::query($today)
            ->andWhere(['>=', 'm.last_expiry', $today])
            ->andWhere(['<=', 'm.last_expiry', self::shift($today, self::AUTO_DAYS)])
            ->andWhere(['m.reminders_opt_out' => 0])
            ->all();
        foreach ($due as $row) {
            if ((new Query())->from(self::TABLE)->where(['user_id' => $row['id'], 'expires_on' => $row['last_expiry'], 'kind' => 'auto'])->exists()) continue;
            if (self::send($row, 'auto', null)) $sent++;
        }
        if ($sent > 0) Log::event(sprintf('Promemoria quota automatici inviati: %d', $sent), Log::TYPE_ACTIVITY);
        return $sent;
    }

    /**
     * Invio manuale dalla lista: solo soci in lista, che non hanno disattivato i promemoria e senza invii negli ultimi 7 giorni.
     * @return array{sent:int, skipped:int}
     */
    public static function sendManual(array $userIds, int $adminId, string $today, int $now): array
    {
        $result = ['sent' => 0, 'skipped' => 0];
        $rows = [];
        foreach (self::query($today)->all() as $row) $rows[(int) $row['id']] = $row;
        foreach (array_unique(array_map('intval', array_filter($userIds, static fn($v) => is_string($v) && ctype_digit($v) && strlen($v) < 11))) as $id) {
            $row = $rows[$id] ?? null;
            $recent = $row !== null && (new Query())->from(self::TABLE)->where(['user_id' => $id])->andWhere(['>', 'sent_at', $now - self::PAUSE_DAYS * 86400])->exists();
            if ($row === null || (int) $row['reminders_opt_out'] === 1 || $recent || !self::send($row, 'manual', $adminId)) {
                $result['skipped']++;
                continue;
            }
            $result['sent']++;
        }
        if ($result['sent'] > 0) Log::event(sprintf('Promemoria quota inviati a mano: %d', $result['sent']), Log::TYPE_ACTIVITY);
        return $result;
    }

    private static function send(array $row, string $kind, ?int $adminId): bool
    {
        $user = Users::findOne((int) $row['id']);
        if ($user === null) return false;
        $sent = sendMail::toUser($user, ['html' => 'membershipReminder', 'text' => 'membershipReminder-text'], Yii::t('app', 'Membership fee reminder'),
            ['expiresOn' => $row['last_expiry']]);
        if (!$sent) return false;
        Yii::$app->db->createCommand()->insert(self::TABLE, ['user_id' => (int) $row['id'], 'expires_on' => $row['last_expiry'], 'kind' => $kind,
            'sent_at' => time(), 'sent_by' => $adminId])->execute();
        return true;
    }

    private static function shift(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }
}
