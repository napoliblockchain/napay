<?php

namespace app\models;

use Yii;

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
    public const SCENARIO_PROFILE_UPDATE = 'profile-update';

    public function scenarios()
    {
        $scenarios = parent::scenarios();
        $scenarios[self::SCENARIO_PROFILE_UPDATE] = [
            'association_role_id', '!is_corporate', 'denomination', 'vat', 'address', 'cap', 'city', 'country',
        ];
        return $scenarios;
    }

    public function rules()
    {
        return [
            [['denomination', 'vat', 'address', 'cap', 'city', 'country'], 'filter', 'filter' => static fn($v) => is_string($v) && trim($v) !== '' ? trim($v) : null],
            [['is_corporate'], 'required'],
            [['is_corporate'], 'boolean'],
            [['is_corporate'], 'validateImmutableType', 'skipOnEmpty' => false],
            [['denomination', 'vat', 'address', 'cap', 'city', 'country'], 'string', 'max' => 250],
            [['association_role_id'], 'exist', 'skipOnEmpty' => true, 'targetClass' => AssociationRoles::class, 'targetAttribute' => 'id'],
            [['denomination'], 'required', 'when' => fn($m) => (int) $m->is_corporate === 1,
                'whenClient' => 'function (attribute, value) { var type = document.getElementById("member-profile-type"); return type !== null && type.getAttribute("data-is-corporate") === "1"; }'],
        ];
    }

    public function validateImmutableType($attribute): void
    {
        if (!$this->isNewRecord && !$this->hasUnchangedType()) {
            $this->addError($attribute, Yii::t('app', 'Il tipo PF/PG non è modificabile dopo la registrazione.'));
        }
    }

    public function beforeSave($insert)
    {
        // Enforce immutable type even when a caller skips validation.
        if ((!$insert && !$this->hasUnchangedType())
            || !in_array($this->is_corporate, [0, 1, '0', '1', false, true], true)) {
            $this->addError('is_corporate', Yii::t('app', 'Il tipo PF/PG non è modificabile dopo la registrazione.'));
            return false;
        }
        return parent::beforeSave($insert);
    }

    private function hasUnchangedType(): bool
    {
        $old = $this->getOldAttribute('is_corporate');
        return in_array($old, [0, 1, '0', '1', false, true], true)
            && in_array($this->is_corporate, [0, 1, '0', '1', false, true], true)
            && (int)$old === (int)$this->is_corporate;
    }

    public function attributeLabels()
    {
        return [
            'association_role_id' => Yii::t('app', 'Carica'),
            'is_corporate' => Yii::t('app', 'Persona giuridica'),
            'denomination' => Yii::t('app', 'Ragione sociale'),
            'vat' => Yii::t('app', 'C.F./P.IVA'),
            'address' => Yii::t('app', 'Indirizzo'),
            'cap' => Yii::t('app', 'CAP'),
            'city' => Yii::t('app', 'Città'),
            'country' => Yii::t('app', 'Paese'),
        ];
    }

    public static function tableName()
    {
        return 'member_profiles';
    }

    public function getRole()
    {
        return $this->hasOne(AssociationRoles::class, ['id' => 'association_role_id']);
    }
}
