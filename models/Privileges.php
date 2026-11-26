<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "privileges".
 *
 * @property int $id
 * @property string $description
 * @property int $level
 * @property string $codice_ruolo
 */
class Privileges extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'privileges';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['description', 'level', 'codice_ruolo'], 'required'],
            [['level'], 'integer'],
            [['description', 'codice_ruolo'], 'string', 'max' => 50],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('app', 'ID'),
            'description' => Yii::t('app', 'Descrizione'),
            'level' => Yii::t('app', 'Livello'),
            'codice_ruolo' => Yii::t('app', 'Codice ruolo'),
        ];
    }

    /**
     * {@inheritdoc}
     * @return \app\models\query\PrivilegesQuery the active query used by this AR class.
     */
    public static function find()
    {
        return new \app\models\query\PrivilegesQuery(get_called_class());
    }
}
