<?php

namespace app\models\search;

use yii\base\Model;
use yii\data\ActiveDataProvider;
use app\models\Merchants;

/**
 * MerchantsSearch represents the model behind the search form of `app\models\Merchants`.
 */
class MerchantsSearch extends Merchants
{
    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['id', 'user_id'], 'integer'],
            [['description', 'email', 'vatNumber', 'phone', 'mobile', 'addressStreet', 'addressNumberHouse', 'addressCity', 'addressZip', 'addressProvince', 'addressCountry'], 'safe'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function scenarios()
    {
        // bypass scenarios() implementation in the parent class
        return Model::scenarios();
    }

    /**
     * Creates data provider instance with search query applied
     *
     * @param array $params
     *
     * @return ActiveDataProvider
     */
    public function search($params)
    {
        $query = Merchants::find();

        // add conditions that should always apply here

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
        ]);

        $this->load($params);

        if (!$this->validate()) {
            // uncomment the following line if you do not want to return any records when validation fails
            // $query->where('0=1');
            return $dataProvider;
        }

        // grid filtering conditions
        $query->andFilterWhere([
            'id' => $this->id,
            'user_id' => $this->user_id,
        ]);

        $query->andFilterWhere(['like', 'description', $this->description])
            ->andFilterWhere(['like', 'email', $this->email])
            ->andFilterWhere(['like', 'vatNumber', $this->vatNumber])
            ->andFilterWhere(['like', 'phone', $this->phone])
            ->andFilterWhere(['like', 'mobile', $this->mobile])
            ->andFilterWhere(['like', 'addressStreet', $this->addressStreet])
            ->andFilterWhere(['like', 'addressNumberHouse', $this->addressNumberHouse])
            ->andFilterWhere(['like', 'addressCity', $this->addressCity])
            ->andFilterWhere(['like', 'addressZip', $this->addressZip])
            ->andFilterWhere(['like', 'addressProvince', $this->addressProvince])
            ->andFilterWhere(['like', 'addressCountry', $this->addressCountry]);

        return $dataProvider;
    }
}
