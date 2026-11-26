<?php

namespace app\models\query;

/**
 * This is the ActiveQuery class for [[\app\models\Stores]].
 *
 * @see \app\models\Stores
 */
class StoresQuery extends \yii\db\ActiveQuery
{
    // public function active()
    // {
    //     return $this->andWhere('[[historical]]=0');
    // }

    public function byMerchantId($id)
    {
        return $this->andWhere([
            'merchant_id' => $id
        ]);
    }

    /**
     * {@inheritdoc}
     * @return \app\models\Stores[]|array
     */
    public function all($db = null)
    {
        return parent::all($db);
    }

    /**
     * {@inheritdoc}
     * @return \app\models\Stores|array|null
     */
    public function one($db = null)
    {
        return parent::one($db);
    }
}
