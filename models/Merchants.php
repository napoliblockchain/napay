<?php

namespace app\models;

use Yii;
use app\components\geo\ItalianMunicipalities;

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
        // Form ridotto: via e civico in un campo, provincia e paese non richiesti, email = registrazione del titolare.
        $scenarios[self::SCENARIO_UPDATE] = ['description', 'phone', 'mobile', 'addressStreet', 'addressCity', 'addressZip'];
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
        $form = [self::SCENARIO_ACTIVATION, self::SCENARIO_UPDATE];
        return [
            [['description', 'vatNumber', 'phone', 'mobile', 'addressStreet', 'addressCity', 'addressZip'],
                'filter', 'filter'=>static fn($v) => is_string($v) ? trim(preg_replace('/\s+/u', ' ', $v)) : '', 'on'=>$form],
            // P.IVA anche con prefisso IT o spazi: salvata come 11 cifre
            ['vatNumber', 'filter', 'filter'=>static fn($v) => preg_replace('/^IT/i', '', str_replace(' ', '', $v)), 'on'=>self::SCENARIO_ACTIVATION],
            ['vatNumber', 'validateItalianVat', 'on'=>self::SCENARIO_ACTIVATION],
            ['addressZip', 'match', 'pattern'=>'/^[0-9]{5}$/D', 'message'=>Yii::t('app', 'Enter a 5-digit postal code.'), 'on'=>$form],
            ['addressCity', 'validateCity', 'on'=>$form],
            ['addressStreet', 'match', 'pattern'=>'/\\p{L}/u', 'message'=>Yii::t('app', 'Enter street and house number.'), 'on'=>$form],
            [['phone', 'mobile'], 'match', 'pattern'=>'/^\\+?[0-9][0-9 .\\/-]{5,28}$/D', 'message'=>Yii::t('app', 'Enter a valid phone number.'), 'on'=>$form],
            ['email', 'email'],
            [['user_id', 'description', 'email', 'vatNumber', 'addressStreet', 'addressCity', 'addressZip'], 'required'],
            [['phone', 'mobile'], 'default', 'value'=>''],
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

    /** Città dall'elenco comuni ISTAT ("Comune" o "Comune (SIGLA)"): salva nome ufficiale e sigla della provincia. */
    public function validateCity($attribute): void
    {
        $city = ItalianMunicipalities::resolve((string) $this->$attribute);
        if ($city === null) {
            $this->addError($attribute, Yii::t('app', 'Choose a municipality from the list.'));
        } elseif (isset($city['ambiguous'])) {
            $this->addError($attribute, Yii::t('app', 'More municipalities have this name: choose the one with the province.'));
        } else {
            $this->$attribute = $city['name'];
            $this->addressProvince = $city['province'];
        }
    }

    /** Partita IVA italiana: 11 cifre con cifra di controllo. */
    public function validateItalianVat($attribute): void
    {
        $vat = $this->$attribute;
        if (!is_string($vat) || preg_match('/^[0-9]{11}$/D', $vat) !== 1) {
            $this->addError($attribute, Yii::t('app', 'Enter an Italian VAT number (11 digits).'));
            return;
        }
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $digit = (int) $vat[$i];
            if ($i % 2 === 1) { $digit *= 2; if ($digit > 9) $digit -= 9; }
            $sum += $digit;
        }
        if ((10 - $sum % 10) % 10 !== (int) $vat[10]) $this->addError($attribute, Yii::t('app', 'VAT number check digit is not valid.'));
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('app', 'ID'),
            'user_id' => Yii::t('app', 'User ID'),
            'description' => Yii::t('app', 'Company name'),
            'email' => Yii::t('app', 'Email'),
            'vatNumber' => Yii::t('app', 'Vat Number'),
            'phone' => Yii::t('app', 'Phone'),
            'mobile' => Yii::t('app', 'Mobile'),
            'addressStreet' => Yii::t('app', 'Address (street and number)'),
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
