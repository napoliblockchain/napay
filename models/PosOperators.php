<?php
namespace app\models;

/** A POS operator remains bound to one merchant even without enabled store grants. */
class PosOperators extends \yii\db\ActiveRecord
{
    public static function tableName() { return '{{%pos_operators}}'; }
    public function behaviors() { return [\yii\behaviors\TimestampBehavior::class]; }
    public function rules()
    {
        return [
            [['user_id','merchant_id'], 'required'],
            [['user_id','merchant_id'], 'integer', 'min'=>1, 'max'=>2147483647],
            ['is_enabled', 'default', 'value'=>0], ['is_enabled', 'integer', 'min'=>0, 'max'=>1],
            ['user_id', 'exist', 'targetClass'=>Users::class, 'targetAttribute'=>'id'],
            ['merchant_id', 'exist', 'targetClass'=>Merchants::class, 'targetAttribute'=>'id'],
            ['user_id', 'unique'],
        ];
    }
    public function beforeSave($insert)
    {
        if (!$insert && ($this->isAttributeChanged('user_id') || $this->isAttributeChanged('merchant_id'))) {
            $this->addError('merchant_id', 'Operator reassignment requires an approved administrative operation.');
            return false;
        }
        return parent::beforeSave($insert);
    }
    public function getUser() { return $this->hasOne(Users::class, ['id'=>'user_id']); }
    public function getMerchant() { return $this->hasOne(Merchants::class, ['id'=>'merchant_id']); }
}
