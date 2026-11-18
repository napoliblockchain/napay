<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "webhooks".
 *
 * @property int $id
 * @property int $store_id
 * @property string $bps_storeid
 * @property string $webhookId
 * @property string $url
 *
 * @property Stores $store
 */
class Webhooks extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'webhooks';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['store_id', 'bps_storeid', 'webhookId', 'url'], 'required'],
            [['store_id'], 'integer'],
            [['bps_storeid', 'webhookId', 'url'], 'string', 'max' => 512],
            [['store_id'], 'exist', 'skipOnError' => true, 'targetClass' => Stores::class, 'targetAttribute' => ['store_id' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('app', 'ID'),
            'store_id' => Yii::t('app', 'Store ID'),
            'bps_storeid' => Yii::t('app', 'Bps Storeid'),
            'webhookId' => Yii::t('app', 'Webhook ID'),
            'url' => Yii::t('app', 'Url'),
        ];
    }

    /**
     * Gets query for [[Store]].
     *
     * @return \yii\db\ActiveQuery|\app\models\query\StoresQuery
     */
    public function getStore()
    {
        return $this->hasOne(Stores::class, ['id' => 'store_id']);
    }

    /**
     * {@inheritdoc}
     * @return \app\models\query\WebhooksQuery the active query used by this AR class.
     */
    public static function find()
    {
        return new \app\models\query\WebhooksQuery(get_called_class());
    }
}
