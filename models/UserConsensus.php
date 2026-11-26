<?php

namespace app\models;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "user_consensus".
 *
 * @property int $id
 * @property int $user_id
 * @property int $consenso_statuto
 * @property int $consenso_privacy
 * @property int $consenso_condizioni
 * @property int|null $consenso_condizioni_pos
 * @property int $consenso_marketing
 * @property int $timestamp_statuto
 * @property int $timestamp_privacy
 * @property int $timestamp_condizioni
 * @property int|null $timestamp_condizioni_pos
 * @property int $timestamp_marketing
 *
 * @property Users $user
 */
class UserConsensus extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'user_consensus';
    }

    /**
     * Metodo invocato prima che il record sia salvato.
     * @param bool $insert true se il record sta per essere inserito, altrimenti false se sta per essere aggiornato
     * @return bool true per continuare a eseguire il salvataggio, false per interrompere il salvataggio
     */
    public function beforeSave($insert)
    {
        if ($insert) {
            // Se stai inserendo un nuovo record, aggiorna il timestamp
            $this->timestamp_statuto = time();
            $this->timestamp_privacy = time();
            $this->timestamp_condizioni = time();
            $this->timestamp_condizioni_pos = time();
            $this->timestamp_marketing = time();
        } else {
            // Se stai aggiornando un record esistente, aggiorna il timestamp solo se necessario
            if ($this->isAttributeChanged('timestamp_statuto')) {
                $this->timestamp_statuto = time();
            }
            if ($this->isAttributeChanged('timestamp_privacy')) {
                $this->timestamp_privacy = time();
            }
            if ($this->isAttributeChanged('timestamp_condizioni')) {
                $this->timestamp_condizioni = time();
            }
            if ($this->isAttributeChanged('timestamp_condizioni_pos')) {
                $this->timestamp_condizioni_pos = time();
            }
            if ($this->isAttributeChanged('timestamp_marketing')) {
                $this->timestamp_marketing = time();
            }
        }

        return parent::beforeSave($insert);
    }

    

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['user_id', 'consenso_statuto', 'consenso_privacy', 'consenso_condizioni',  ], 'required'],
            
            [['user_id', 'consenso_statuto', 'consenso_privacy', 'consenso_condizioni', 'consenso_condizioni_pos', 'consenso_marketing', 'timestamp_statuto', 'timestamp_privacy', 'timestamp_condizioni', 'timestamp_condizioni_pos', 'timestamp_marketing'], 'integer'],
            [['user_id'], 'exist', 'skipOnError' => true, 'targetClass' => Users::class, 'targetAttribute' => ['user_id' => 'id']],

            // Altre regole di validazione...
            [['consenso_statuto', 'consenso_privacy', 'consenso_condizioni'], 'compare', 'compareValue' => 1, 'operator' => '==', 'message' => 'Campo obbligatorio.'],
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
            'consenso_statuto' => Yii::t('app', 'Consenso Statuto'),
            'consenso_privacy' => Yii::t('app', 'Consenso Privacy'),
            'consenso_condizioni' => Yii::t('app', 'Consenso Condizioni'),
            'consenso_condizioni_pos' => Yii::t('app', 'Consenso Condizioni Pos'),
            'consenso_marketing' => Yii::t('app', 'Consenso Marketing'),
            'timestamp_statuto' => Yii::t('app', 'Timestamp Statuto'),
            'timestamp_privacy' => Yii::t('app', 'Timestamp Privacy'),
            'timestamp_condizioni' => Yii::t('app', 'Timestamp Condizioni'),
            'timestamp_condizioni_pos' => Yii::t('app', 'Timestamp Condizioni Pos'),
            'timestamp_marketing' => Yii::t('app', 'Timestamp Marketing'),
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
     * @return \app\models\query\UserConsensusQuery the active query used by this AR class.
     */
    public static function find()
    {
        return new \app\models\query\UserConsensusQuery(get_called_class());
    }
}
