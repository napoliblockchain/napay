<?php

namespace app\models\query;

/**
 * This is the ActiveQuery class for [[\app\models\Users]].
 *
 * @see \app\models\Users
 */
class UsersQuery extends \yii\db\ActiveQuery
{
    /**
     * user is active
     */
    public function active()
    {
        return $this->andWhere('[[is_active]]=1');
    }

    /**
     * Finds user by username
     *
     * @param string $username
     * @return static|null
     */
    public function findByUsername($username)
    {
        return $this->andWhere(['username' => $username]);
    }

    /**
     * {@inheritdoc}
     * @return \app\models\Users[]|array
     */
    public function all($db = null)
    {
        return parent::all($db);
    }

    /**
     * {@inheritdoc}
     * @return \app\models\Users|array|null
     */
    public function one($db = null)
    {
        return parent::one($db);
    }
}
