<?php

namespace app\models;

use Yii;

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
    public function rules()
    {
        return [
            [['description'], 'filter', 'filter' => static fn($v) => is_string($v) ? trim($v) : ''],
            [['description'], 'required'],
            [['description'], 'string', 'max' => 50],
        ];
    }

    public function attributeLabels()
    {
        return [
            'description' => Yii::t('app', 'Carica'),
        ];
    }

    public static function tableName()
    {
        return 'association_roles';
    }

    public function getProfiles()
    {
        return $this->hasMany(MemberProfiles::class, ['association_role_id' => 'id']);
    }
}
