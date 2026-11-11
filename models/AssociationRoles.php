<?php

namespace app\models;

/**
 * Cariche sociali (TIP-020).
 * Scritture solo dall'import legacy finché è attivo (components/legacy/LegacyImporter).
 *
 * @property mixed $id
 * @property mixed $legacy_id
 * @property mixed $description
 */
class AssociationRoles extends \yii\db\ActiveRecord
{
    public static function tableName()
    {
        return 'association_roles';
    }

    public function getProfiles()
    {
        return $this->hasMany(MemberProfiles::class, ['association_role_id' => 'id']);
    }
}
