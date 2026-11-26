<?php

namespace app\models\query;

/**
 * This is the ActiveQuery class for [[\app\models\Privileges]].
 *
 * @see \app\models\Privileges
 */
class PrivilegesQuery extends \yii\db\ActiveQuery
{
    public function byLevelLessThen($level)
    {
        return $this->andWhere('[[level]]<=' . $level);
    }

    public function byLevel($livello)
    {
        return $this->andWhere([
            'level' => $livello,
        ]);
    }

    /**
     * {@inheritdoc}
     * @return \app\models\Privileges[]|array
     */
    public function all($db = null)
    {
        return parent::all($db);
    }

    /**
     * {@inheritdoc}
     * @return \app\models\Privileges|array|null
     */
    public function one($db = null)
    {
        return parent::one($db);
    }
}
