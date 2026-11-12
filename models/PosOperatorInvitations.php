<?php
namespace app\models;

/**
 * Invito operatore POS (C4 parte D). Solo il digest del token; scritture solo da PosInvitations, mai dal browser.
 * @property int $id
 * @property int $merchant_id
 * @property string $email
 * @property string $token_sha256
 * @property int $created_by
 * @property int $created_at
 * @property int $updated_at
 * @property int $expires_at
 * @property int|null $sent_at
 * @property int $send_failures
 * @property int|null $consumed_at
 * @property int|null $consumed_user_id
 * @property int|null $revoked_at
 */
class PosOperatorInvitations extends \yii\db\ActiveRecord
{
    public static function tableName() { return '{{%pos_operator_invitations}}'; }
    public function behaviors() { return [\yii\behaviors\TimestampBehavior::class]; }
    public function scenarios() { return [self::SCENARIO_DEFAULT => []]; }

    public function isActive(int $now): bool
    {
        return $this->consumed_at === null && $this->revoked_at === null && (int) $this->expires_at > $now;
    }
}
