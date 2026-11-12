<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "merchants".
 *
 * @property int $id
 * @property int $user_id
 * @property string $description
 * @property string $email
 * @property string $vatNumber
 * @property string $phone
 * @property string $mobile
 * @property string $addressStreet
 * @property string $addressNumberHouse
 * @property string $addressCity
 * @property string $addressZip
 * @property string $addressProvince
 * @property string $addressCountry
 *
 * @property Users $user
 */
class Merchants extends \yii\db\ActiveRecord
{
    public const SCENARIO_UPDATE = 'update';
    public const SCENARIO_ACTIVATION = 'activation';

    public function scenarios()
    {
        $scenarios = parent::scenarios();
        $scenarios[self::SCENARIO_UPDATE] = [
            'description', 'email', 'phone', 'mobile', 'addressStreet', 'addressNumberHouse',
            'addressCity', 'addressZip', 'addressProvince', 'addressCountry',
        ];
        $scenarios[self::SCENARIO_ACTIVATION] = array_merge(['user_id', 'vatNumber'], $scenarios[self::SCENARIO_UPDATE]);
        return $scenarios;
    }

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'merchants';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['description', 'email', 'vatNumber', 'phone', 'mobile', 'addressStreet', 'addressNumberHouse', 'addressCity', 'addressZip', 'addressProvince', 'addressCountry'],
                'filter', 'filter'=>static fn($v) => is_string($v) ? trim($v) : '', 'on'=>self::SCENARIO_ACTIVATION],
            ['email', 'email', 'on'=>self::SCENARIO_ACTIVATION],
            [['user_id', 'description', 'email', 'vatNumber', 'phone', 'mobile', 'addressStreet', 'addressNumberHouse', 'addressCity', 'addressZip', 'addressProvince', 'addressCountry'], 'required'],
            [['user_id'], 'integer'],
            [['description', 'email', 'addressCity'], 'string', 'max' => 256],
            [['vatNumber'], 'string', 'max' => 16],
            [['phone', 'mobile'], 'string', 'max' => 30],
            [['addressStreet'], 'string', 'max' => 512],
            [['addressNumberHouse', 'addressZip'], 'string', 'max' => 20],
            [['addressProvince', 'addressCountry'], 'string', 'max' => 10],
            [['user_id'], 'exist', 'skipOnError' => true, 'targetClass' => Users::class, 'targetAttribute' => ['user_id' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('app', 'ID'),
            'user_id' => Yii::t('app', 'User ID'),
            'description' => Yii::t('app', 'Description'),
            'email' => Yii::t('app', 'Email'),
            'vatNumber' => Yii::t('app', 'Vat Number'),
            'phone' => Yii::t('app', 'Phone'),
            'mobile' => Yii::t('app', 'Mobile'),
            'addressStreet' => Yii::t('app', 'Address Street'),
            'addressNumberHouse' => Yii::t('app', 'Address Number House'),
            'addressCity' => Yii::t('app', 'Address City'),
            'addressZip' => Yii::t('app', 'Address Zip'),
            'addressProvince' => Yii::t('app', 'Address Province'),
            'addressCountry' => Yii::t('app', 'Address Country'),
        ];
    }

    /**
     * Gets query for [[User]].
     *
     * @return \yii\db\ActiveQuery|\app\models\query\UsersQuery
     */
    public function getUser()
    {
        return $this->hasOne(Users::class, ['id' => 'user_id']);
    }

    /**
     * {@inheritdoc}
     * @return \app\models\query\MerchantsQuery the active query used by this AR class.
     */
    public static function find()
    {
        return new \app\models\query\MerchantsQuery(get_called_class());
    }
}
