<?php

namespace app\models\search;

use yii\base\Model;
use yii\data\ActiveDataProvider;
use app\models\Logs;

/**
 * LogsSearch represents the model behind the search form of `app\models\Logs`.
 */
class LogsSearch extends Logs
{
    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['id', 'user_id'], 'integer'], // tolto timestamp da qui per filter
            [['timestamp', 'remote_address', 'browser', 'controller', 'action', 'description'], 'safe'],
            [['event_type'], 'in', 'range' => array_keys(Logs::eventTypes())],

            // ['timestamp', 'date', 'format' => 'php:d/m/Y H:i:s', 'message' => 'Il formato non è valido. Usare dd/mm/yyyy'],

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
        $query = Logs::find();

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
            // 'timestamp' => $this->timestamp,
            'user_id' => $this->user_id,
            'event_type' => $this->event_type,
        ]);


        // if (!empty($this->timestamp)) {
        //     $startDate = strtotime($this->timestamp . ' 00:00:00');
        //     $endDate = strtotime($this->timestamp . ' 23:59:59');

        //     $query->andWhere(['between', 'timestamp', $startDate, $endDate]);
        // }

        // ricerca PER date
        if (!empty($this->timestamp)) {
            $date = \DateTime::createFromFormat('d/m/Y', $this->timestamp);
        }

        $errors = \DateTime::getLastErrors();

        // echo '<pre>'.var_dump($this->timestamp).'</pre>';;
        // echo '<pre>' . var_dump($date) . '</pre>';
        // exit;
        // $time_start = '0000000000'; // La data non corrisponde al formato desiderato
        // $time_end =   '9999999999'; // La data non corrisponde al formato desiderato
        if (!$errors && isset($date)) {
            $time_start = strtotime($date->format('Y-m-d'));
            $time_end = $time_start + 86400;
            
            $query->andFilterWhere(['between', 'timestamp', $time_start, $time_end]);
        }


        

        $query->andFilterWhere(['like', 'remote_address', $this->remote_address])
            ->andFilterWhere(['like', 'browser', $this->browser])
            ->andFilterWhere(['like', 'controller', $this->controller])
            ->andFilterWhere(['like', 'action', $this->action])
            ->andFilterWhere(['like', 'description', $this->description]);

        return $dataProvider;
    }
}
