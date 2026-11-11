<?php

namespace app\models;

/**
 * Profilo socio collegato a users (TIP-020).
 * Scritture solo dall'import legacy finché è attivo (components/legacy/LegacyImporter).
 *
 * @property mixed $id
 * @property mixed $user_id
 * @property mixed $legacy_user_id
 * @property mixed $legacy_user_type
 * @property mixed $user_imported
 * @property mixed $association_role_id
 * @property mixed $is_corporate
 * @property mixed $denomination
 * @property mixed $vat
 * @property mixed $address
 * @property mixed $cap
 * @property mixed $city
 * @property mixed $country
 */
class MemberProfiles extends \yii\db\ActiveRecord
{
    public static function tableName()
    {
        return 'member_profiles';
    }

    public function getRole()
    {
        return $this->hasOne(AssociationRoles::class, ['id' => 'association_role_id']);
    }
}
