<?php

namespace app\components\association;

use Yii;
use yii\db\Query;
use app\components\Log;
use app\components\sendMail;
use app\models\MemberProfiles;
use app\models\Users;

/**
 * Richieste di iscrizione (SIP-030 punto 1): dopo la verifica dell'email l'admin approva o rifiuta.
 * Approvata = il socio entra e riceve le istruzioni per la quota; rifiutata = mail con la motivazione e account eliminato
 * (nel registro resta solo l'ID). Account mai verificati eliminati dopo 7 giorni (comando periodico).
 */
final class MembershipRequests
{
    public const UNVERIFIED_DAYS = 7;
    /** Carica assegnata all'approvazione a chi non ne ha una, come la registrazione del vecchio Napay. */
    public const DEFAULT_ROLE = 'Socio ordinario';

    /**
     * Richieste in lista: email verificata (account attivo) oppure socio v1 importato mai approvato,
     * che nella v1 non aveva la verifica dell'email. Gli account bloccati restano fuori.
     */
    public static function query(): Query
    {
        return (new Query())
            ->select(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.is_active', 'p.is_corporate', 'p.denomination', 'p.requested_at', 'p.legacy_user_id'])
            ->from(['p' => MemberProfiles::tableName()])
            ->innerJoin(['u' => Users::tableName()], 'u.id = p.user_id')
            ->where(['p.approval_state' => MemberProfiles::APPROVAL_PENDING])
            ->andWhere(['or', ['u.is_active' => Users::STATUS_ACTIVE], ['and', ['u.is_active' => Users::STATUS_INSERTED], ['not', ['p.legacy_user_id' => null]]]])
            ->orderBy(['p.requested_at' => SORT_ASC, 'u.id' => SORT_ASC]);
    }

    public static function count(): int
    {
        return (int) self::query()->count();
    }

    /** @return int richieste approvate tra quelle indicate (le altre sono già state gestite o non sono in lista) */
    public static function approve(array $userIds, int $adminId): int
    {
        $approved = 0;
        foreach (self::ids($userIds) as $userId) {
            $db = Yii::$app->db;
            $transaction = $db->beginTransaction();
            try {
                $row = self::query()->andWhere(['u.id' => $userId])->one();
                if ($row === false || $db->createCommand('SELECT id FROM {{%member_profiles}} WHERE user_id=:u AND approval_state=:s FOR UPDATE',
                        [':u' => $userId, ':s' => MemberProfiles::APPROVAL_PENDING])->queryScalar() === false) {
                    $transaction->rollBack();
                    continue;
                }
                $db->createCommand()->update('{{%member_profiles}}', ['approval_state' => MemberProfiles::APPROVAL_APPROVED, 'approved_at' => time(), 'approved_by' => $adminId],
                    ['user_id' => $userId, 'approval_state' => MemberProfiles::APPROVAL_PENDING])->execute();
                $role = (new Query())->select('id')->from('{{%association_roles}}')->where(['description' => self::DEFAULT_ROLE])->scalar();
                if ($role !== false) {
                    $db->createCommand()->update('{{%member_profiles}}', ['association_role_id' => (int) $role], ['user_id' => $userId, 'association_role_id' => null])->execute();
                }
                // socio v1 mai approvato: nella v1 l'approvazione attivava l'account
                if ((int) $row['is_active'] === Users::STATUS_INSERTED) {
                    $db->createCommand()->update('{{%users}}', ['is_active' => Users::STATUS_ACTIVE, 'activationCode' => null], ['id' => $userId, 'is_active' => Users::STATUS_INSERTED])->execute();
                }
                Log::event(sprintf('Iscrizione approvata: utente %d', $userId), Log::TYPE_DATA);
                $transaction->commit();
            } catch (\Throwable $error) {
                if ($transaction->isActive) $transaction->rollBack();
                Yii::error('Membership approval failure: ' . get_class($error), __METHOD__);
                continue;
            }
            $approved++;
            $user = Users::findOne($userId);
            if ($user !== null) sendMail::toUser($user, ['html' => 'membershipApproved', 'text' => 'membershipApproved-text'], Yii::t('app', 'Membership approved'));
        }
        return $approved;
    }

    /**
     * Rifiuto: account eliminato (profilo, consensi e sessioni a cascata), poi la mail con la motivazione ai dati
     * copiati prima dell'eliminazione. Un account con dati collegati che lo bloccano non viene toccato.
     * @return int richieste rifiutate
     */
    public static function reject(array $userIds, string $reason): int
    {
        $rejected = 0;
        foreach (self::ids($userIds) as $userId) {
            $db = Yii::$app->db;
            $transaction = $db->beginTransaction();
            try {
                $user = self::query()->andWhere(['u.id' => $userId])->one() === false ? null : Users::findOne($userId);
                if ($user === null || $db->createCommand('SELECT id FROM {{%member_profiles}} WHERE user_id=:u AND approval_state=:s FOR UPDATE',
                        [':u' => $userId, ':s' => MemberProfiles::APPROVAL_PENDING])->queryScalar() === false) {
                    $transaction->rollBack();
                    continue;
                }
                $recipient = new Users(['email' => $user->email, 'first_name' => $user->first_name, 'last_name' => $user->last_name, 'username' => $user->username]);
                $db->createCommand()->delete('{{%users}}', ['id' => $userId])->execute();
                Log::event(sprintf('Richiesta di iscrizione rifiutata: utente %d eliminato', $userId), Log::TYPE_DATA);
                $transaction->commit();
            } catch (\Throwable $error) {
                if ($transaction->isActive) $transaction->rollBack();
                Yii::error('Membership rejection failure: ' . get_class($error), __METHOD__);
                continue;
            }
            $rejected++;
            sendMail::toUser($recipient, ['html' => 'membershipRejected', 'text' => 'membershipRejected-text'], Yii::t('app', 'Membership request not accepted'), ['reason' => $reason]);
        }
        return $rejected;
    }

    /** Account registrati dal sito e mai verificati da più di 7 giorni: eliminati senza mail. @return int eliminati */
    public static function purgeUnverified(int $now): int
    {
        $ids = (new Query())->select('u.id')
            ->from(['p' => MemberProfiles::tableName()])
            ->innerJoin(['u' => Users::tableName()], 'u.id = p.user_id')
            ->where(['p.approval_state' => MemberProfiles::APPROVAL_PENDING, 'u.is_active' => Users::STATUS_INSERTED, 'u.oauth_provider' => 'SELF', 'p.legacy_user_id' => null])
            ->andWhere(['<', 'p.requested_at', $now - self::UNVERIFIED_DAYS * 86400])
            ->column();
        $deleted = 0;
        foreach ($ids as $id) {
            try {
                $deleted += Yii::$app->db->createCommand()->delete('{{%users}}', ['id' => (int) $id, 'is_active' => Users::STATUS_INSERTED])->execute();
            } catch (\Throwable $error) {
                Yii::error('Unverified account purge failure: ' . get_class($error), __METHOD__);
            }
        }
        if ($deleted > 0) Log::event(sprintf('Eliminati %d account mai verificati da oltre %d giorni', $deleted, self::UNVERIFIED_DAYS), Log::TYPE_DATA);
        return $deleted;
    }

    /** @return int[] ID positivi e distinti dal form */
    private static function ids(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if (is_string($value) && ctype_digit($value) && strlen($value) < 11 && (int) $value > 0) $ids[(int) $value] = (int) $value;
        }
        return array_values($ids);
    }
}
