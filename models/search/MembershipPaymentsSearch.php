<?php

namespace app\models\search;

use Yii;
use yii\base\Model;
use yii\data\ActiveDataProvider;
use app\models\MembershipPayments;

/** Pagamenti quote con filtri e totali (TIP-020). */
class MembershipPaymentsSearch extends Model
{
    public $q;
    public $year;
    public $payment_method_id;
    public $status;
    public $user_id;

    public function rules()
    {
        return [
            [['q'], 'string', 'max' => 100],
            [['year'], 'integer', 'min' => 1900, 'max' => 2999],
            [['payment_method_id', 'user_id'], 'integer'],
            [['status'], 'string', 'max' => 32],
        ];
    }

    public function attributeLabels()
    {
        return ['q' => Yii::t('app', 'Socio'), 'year' => Yii::t('app', 'Anno'), 'payment_method_id' => Yii::t('app', 'Metodo'), 'status' => Yii::t('app', 'Stato')];
    }

    public function search($params)
    {
        $query = MembershipPayments::find()->alias('mp')->joinWith(['user u', 'method m', 'fee f'], false)->with(['user', 'method', 'fee']);
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 50],
            'sort' => [
                'defaultOrder' => ['registered_on' => SORT_DESC],
                'attributes' => [
                    'registered_on' => ['asc' => ['mp.registered_on' => SORT_ASC, 'mp.id' => SORT_ASC], 'desc' => ['mp.registered_on' => SORT_DESC, 'mp.id' => SORT_DESC]],
                    'expires_on' => ['asc' => ['mp.expires_on' => SORT_ASC], 'desc' => ['mp.expires_on' => SORT_DESC]],
                    'amount' => ['asc' => ['mp.amount' => SORT_ASC], 'desc' => ['mp.amount' => SORT_DESC]],
                    'member' => ['asc' => ['u.last_name' => SORT_ASC, 'u.first_name' => SORT_ASC], 'desc' => ['u.last_name' => SORT_DESC, 'u.first_name' => SORT_DESC]],
                ],
            ],
        ]);
        if (!($this->load($params) && $this->validate())) {
            if ($this->hasErrors()) $query->where('0=1');
            return $dataProvider;
        }
        if ($this->q !== null && $this->q !== '') {
            $query->andWhere(['or', ['like', 'u.first_name', $this->q], ['like', 'u.last_name', $this->q], ['like', 'u.email', $this->q]]);
        }
        if ($this->year) $query->andWhere(['between', 'mp.registered_on', $this->year . '-01-01', $this->year . '-12-31']);
        $query->andFilterWhere(['mp.payment_method_id' => $this->payment_method_id, 'mp.status' => $this->status, 'mp.user_id' => $this->user_id]);
        return $dataProvider;
    }

    /** Numero e somma dei pagamenti filtrati (tutte le pagine) */
    public static function totals(ActiveDataProvider $dataProvider): array
    {
        $query = clone $dataProvider->query;
        $query->with = [];
        $row = $query->select(['n' => 'COUNT(*)', 'total' => 'COALESCE(SUM(mp.amount),0)'])->orderBy([])->asArray()->one();
        return ['count' => (int) $row['n'], 'amount' => (string) $row['total']];
    }
}
