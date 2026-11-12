<?php
namespace app\models;

/** Explicit store permission; both composite foreign keys enforce merchant scope. */
class PosStoreOperators extends \yii\db\ActiveRecord
{
    public static function tableName() { return '{{%pos_store_operators}}'; }
    public function behaviors() { return [\yii\behaviors\TimestampBehavior::class]; }
    public function rules()
    {
        return [
            [['user_id','merchant_id','store_id'], 'required'],
            [['user_id','merchant_id','store_id'], 'integer', 'min'=>1, 'max'=>2147483647],
            ['is_enabled', 'default', 'value'=>0], ['is_enabled', 'integer', 'min'=>0, 'max'=>1],
            [['user_id','merchant_id'], 'exist', 'targetClass'=>PosOperators::class, 'targetAttribute'=>['user_id'=>'user_id','merchant_id'=>'merchant_id']],
            [['store_id','merchant_id'], 'exist', 'targetClass'=>Stores::class, 'targetAttribute'=>['store_id'=>'id','merchant_id'=>'merchant_id']],
            [['user_id','store_id'], 'unique', 'targetAttribute'=>['user_id','store_id']],
        ];
    }
    public function beforeSave($insert)
    {
        if (!$insert && array_intersect(['user_id','merchant_id','store_id'], array_keys($this->getDirtyAttributes()))) {
            $this->addError('store_id', 'Grant reassignment requires an approved administrative operation.');
            return false;
        }
        return parent::beforeSave($insert);
    }
    public function getOperator() { return $this->hasOne(PosOperators::class, ['user_id'=>'user_id','merchant_id'=>'merchant_id']); }
    public function getStore() { return $this->hasOne(Stores::class, ['id'=>'store_id','merchant_id'=>'merchant_id']); }
}
