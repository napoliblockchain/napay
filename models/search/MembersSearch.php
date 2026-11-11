<?php

namespace app\models\search;

use Yii;
use yii\base\Model;
use yii\data\ActiveDataProvider;
use app\models\Members;

/** Lista soci con filtri di stato quota (TIP-020). */
class MembersSearch extends Model
{
    public $q;
    public $status;
    public $role_id;

    public function rules()
    {
        return [
            [['q'], 'string', 'max' => 100],
            [['status'], 'in', 'range' => array_keys(Members::STATUS_LABELS)],
            [['role_id'], 'integer'],
        ];
    }

    public function attributeLabels()
    {
        return ['q' => Yii::t('app', 'Cerca'), 'status' => Yii::t('app', 'Stato quota'), 'role_id' => Yii::t('app', 'Carica')];
    }

    public function search($params)
    {
        $expiry = Members::lastExpirySql();
        $query = Members::find()->alias('u')
            ->select(['u.*', 'last_expiry' => $expiry])
            ->joinWith(['profile p', 'privilege pr'])
            ->with(['profile.role']);
        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 50],
            'sort' => [
                'defaultOrder' => ['last_name' => SORT_ASC],
                'attributes' => [
                    'last_name' => ['asc' => ['u.last_name' => SORT_ASC, 'u.first_name' => SORT_ASC], 'desc' => ['u.last_name' => SORT_DESC, 'u.first_name' => SORT_DESC]],
                    'email' => ['asc' => ['u.email' => SORT_ASC], 'desc' => ['u.email' => SORT_DESC]],
                    'last_expiry' => ['asc' => [$expiry->expression => SORT_ASC], 'desc' => [$expiry->expression => SORT_DESC]],
                ],
            ],
        ]);
        if (!($this->load($params) && $this->validate())) {
            if ($this->hasErrors()) $query->where('0=1');
            return $dataProvider;
        }
        if ($this->q !== null && $this->q !== '') {
            $query->andWhere(['or', ['like', 'u.first_name', $this->q], ['like', 'u.last_name', $this->q], ['like', 'u.email', $this->q], ['like', 'p.denomination', $this->q]]);
        }
        if ($this->role_id) $query->andWhere(['p.association_role_id' => (int) $this->role_id]);
        if ($this->status) {
            $today = date('Y-m-d');
            $expiring = date('Y-m-d', strtotime('+' . Members::EXPIRING_DAYS . ' days'));
            $expired = date('Y-m-d', strtotime('-' . Members::EXPIRED_DAYS . ' days'));
            $e = $expiry->expression;
            $exempt = ['>=', 'pr.level', 50];
            $condition = match ($this->status) {
                'exempt' => $exempt,
                'never' => ['and', ['<', 'pr.level', 50], "$e IS NULL"],
                'lapsed' => ['and', ['<', 'pr.level', 50], ['<', $e, $expired]],
                'expired' => ['and', ['<', 'pr.level', 50], ['>=', $e, $expired], ['<', $e, $today]],
                'expiring' => ['and', ['<', 'pr.level', 50], ['>=', $e, $today], ['<=', $e, $expiring]],
                'active' => ['and', ['<', 'pr.level', 50], ['>', $e, $expiring]],
            };
            $query->andWhere($condition);
        }
        return $dataProvider;
    }
}
