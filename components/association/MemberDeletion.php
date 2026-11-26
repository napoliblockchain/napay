<?php

namespace app\components\association;

use Yii;
use app\components\Log;
use app\models\Members;
use app\models\MembershipPayments;
use app\models\Merchants;
use yii\db\IntegrityException;
use yii\db\Query;

final class MemberDeletion
{
    public static function canDelete(Members $member, int $actorId): bool
    {
        $id = (int) $member->id;
        return $id !== $actorId && $member->profile !== null
            && (int) ($member->privilege->level ?? -1) === 0 && (int) $member->is_merchant === 0
            && !MembershipPayments::find()->where(['user_id' => $id])->exists()
            && !Merchants::find()->where(['user_id' => $id])->exists()
            && !(new Query())->from('{{%pos_operators}}')->where(['user_id' => $id])->exists()
            && !(new Query())->from('{{%pos_operator_sessions}}')->where(['user_id' => $id])->exists();
    }

    public static function delete(int $id, int $actorId): bool
    {
        $db = Yii::$app->db;
        try {
            return $db->transaction(static function () use ($db, $id, $actorId): bool {
                // The parent lock serializes status updates and new FK-backed dependents.
                if ($db->createCommand('SELECT id FROM {{%users}} WHERE id=:id FOR UPDATE', [':id' => $id])->queryScalar() === false) {
                    return false;
                }
                $member = Members::findOne($id);
                if ($member === null || !self::canDelete($member, $actorId)) {
                    return false;
                }
                // Delete the account once; owned member profile and consents cascade.
                if ($db->createCommand()->delete('{{%users}}', ['id' => $id])->execute() !== 1) {
                    return false;
                }
                Log::event(sprintf('Member and account deleted: user %d', $id), Log::TYPE_DATA);
                return true;
            });
        } catch (IntegrityException $error) {
            return false;
        }
    }
}
