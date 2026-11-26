<?php
namespace app\models;

/** Logical terminal only: no SIN, keys, wallet or opening state. */
class PosTerminals extends \yii\db\ActiveRecord
{
    public static function tableName() { return '{{%pos_terminals}}'; }
    public function behaviors() { return [\yii\behaviors\TimestampBehavior::class]; }
    public function rules()
    {
        return [
            ['name', 'filter', 'filter'=>static fn($v)=>is_string($v) ? trim($v) : $v],
            [['merchant_id','store_id','name','public_id'], 'required'],
            [['merchant_id','store_id'], 'integer', 'min'=>1, 'max'=>2147483647],
            ['name', 'string', 'max'=>256],
            ['name', 'match', 'pattern'=>'/\S/u'],
            ['public_id', 'match', 'pattern'=>'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D'],
            ['public_id', 'unique'],
            ['is_enabled', 'default', 'value'=>0], ['is_enabled', 'integer', 'min'=>0, 'max'=>1],
            [['store_id','merchant_id'], 'exist', 'targetClass'=>Stores::class, 'targetAttribute'=>['store_id'=>'id','merchant_id'=>'merchant_id']],
        ];
    }
    public function safeAttributes()
    {
        return array_diff(parent::safeAttributes(), ['id','public_id','created_at','updated_at']);
    }
    public function beforeValidate()
    {
        if ($this->isNewRecord && $this->public_id === null) {
            $bytes = random_bytes(16);
            $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
            $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
            $hex = bin2hex($bytes);
            $this->public_id = substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
        }
        return parent::beforeValidate();
    }
    public function beforeSave($insert)
    {
        if (!$insert && array_intersect(['id','public_id','merchant_id','store_id'], array_keys($this->getDirtyAttributes()))) {
            $this->addError('store_id', 'Terminal identity and ownership cannot be reassigned.');
            return false;
        }
        return parent::beforeSave($insert);
    }
    public function getStore() { return $this->hasOne(Stores::class, ['id'=>'store_id','merchant_id'=>'merchant_id']); }
}
