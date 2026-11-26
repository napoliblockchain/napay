<?php

namespace app\models;

/**
 * Sessione operatore POS (SIP-021). Il token non è mai salvato: solo token_sha256.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_sha256
 * @property string $client_key_prefix
 * @property string $credential_fingerprint
 * @property int $created_at
 * @property int $expires_at
 * @property int|null $last_used_at
 * @property int|null $revoked_at
 * @property string|null $revoked_reason
 * @property string|null $created_ip
 * @property Users $user
 */
class PosOperatorSessions extends \yii\db\ActiveRecord
{
    public static function tableName()
    {
        return 'pos_operator_sessions';
    }

    public function getUser()
    {
        return $this->hasOne(Users::class, ['id' => 'user_id']);
    }
}
