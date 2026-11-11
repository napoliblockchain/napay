<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "logs".
 *
 * @property int $id
 * @property int $timestamp
 * @property int $user_id
 * @property string $remote_address
 * @property string $browser
 * @property string $controller
 * @property string $action
 * @property string $description
 */
class Logs extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'logs';
    }

    /** Tipi di evento del registro (components/Log), con etichetta e badge Tabler */
    public static function eventTypes(): array
    {
        return [
            \app\components\Log::TYPE_SECURITY => ['label' => 'Sicurezza', 'badge' => 'bg-red-lt'],
            \app\components\Log::TYPE_DATA => ['label' => 'Dati', 'badge' => 'bg-blue-lt'],
            \app\components\Log::TYPE_EXPORT => ['label' => 'Esportazione', 'badge' => 'bg-purple-lt'],
            \app\components\Log::TYPE_ACTIVITY => ['label' => 'Attività', 'badge' => 'bg-secondary-lt'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            // utente facoltativo: azioni anonime senza id fittizio (components/Log)
            [['timestamp', 'event_type', 'controller', 'action', 'description'], 'required'],
            [['timestamp', 'user_id'], 'integer'],
            [['event_type'], 'in', 'range' => array_keys(self::eventTypes())],
            [['description'], 'string'],
            [['remote_address'], 'string', 'max' => 45],
            [['browser'], 'string', 'max' => 256],
            [['controller', 'action'], 'string', 'max' => 60],
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
            'timestamp' => Yii::t('app', 'Timestamp'),
            'event_type' => Yii::t('app', 'Tipo'),
            'user_id' => Yii::t('app', 'User ID'),
            'remote_address' => Yii::t('app', 'Remote Address'),
            'browser' => Yii::t('app', 'Browser'),
            'controller' => Yii::t('app', 'Controller'),
            'action' => Yii::t('app', 'Action'),
            'description' => Yii::t('app', 'Description'),
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
     * @return \app\models\query\LogsQuery the active query used by this AR class.
     */
    public static function find()
    {
        return new \app\models\query\LogsQuery(get_called_class());
    }
}
