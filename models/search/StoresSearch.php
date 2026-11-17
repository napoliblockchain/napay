<?php

namespace app\models\search;

use yii\base\Model;
use yii\data\ActiveDataProvider;
use app\models\Stores;

/**
 * StoresSearch represents the model behind the search form of `app\models\Stores`.
 */
class StoresSearch extends Stores
{
    // add the public attributes that will be used to store the data to be search
    public $merchantName;

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['id', 'merchant_id'], 'integer'],
            [['description', 'addressStreet', 'email'], 'safe'],
            [['phone', 'mobile', 'addressNumberHouse', 'addressCity', 'addressZip', 'addressProvince', 'addressCountry'], 'safe'],

            [['merchantName'], 'safe'],
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
        // negozi archiviati (eliminati) esclusi (SIP-022 passo 3b)
        $query = Stores::find()->andWhere(['archived_at' => null]);

        // add conditions that should always apply here
        // Utilizza il nome del metodo del Model (getMerchant) e non il nome di tabella
        $query->joinWith(['merchant']);

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
        ]);

        // Important: here is how we set up the sorting
        // The key is the attribute name on our "ClientiSearch" instance
        $dataProvider->sort->attributes['merchantName'] = [
            // The tables are the ones our relation are configured to
            // in my case they are prefixed with "tbl_"
            'asc' => ['merchant.description' => SORT_ASC],
            'desc' => ['merchant.description' => SORT_DESC],
        ];

        $this->load($params);

        if (!$this->validate()) {
            // uncomment the following line if you do not want to return any records when validation fails
            // $query->where('0=1');
            return $dataProvider;
        }

        // grid filtering conditions
        // utilizza il nome dalla tabella
        $query->andFilterWhere([
            'stores.id' => $this->id,
            'stores.merchant_id' => $this->merchant_id,
        ]);

        
        $query->andFilterWhere(['like', 'stores.description', $this->description])
            ->andFilterWhere(['like', 'stores.email', $this->email])
            ->andFilterWhere(['like', 'stores.phone', $this->phone])
            ->andFilterWhere(['like', 'stores.mobile', $this->mobile])
            ->andFilterWhere(['like', 'stores.addressStreet', $this->addressStreet])
            ->andFilterWhere(['like', 'stores.addressNumberHouse', $this->addressNumberHouse])
            ->andFilterWhere(['like', 'stores.addressCity', $this->addressCity])
            ->andFilterWhere(['like', 'stores.addressZip', $this->addressZip])
            ->andFilterWhere(['like', 'stores.addressProvince', $this->addressProvince])
            ->andFilterWhere(['like', 'stores.addressCountry', $this->addressCountry]);
        
        // filter search
        $query->andFilterWhere(['like', 'merchants.description', $this->merchantName]);

        return $dataProvider;
    }
}
