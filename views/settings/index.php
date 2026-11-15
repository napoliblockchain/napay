<?php

use yii\helpers\Html;
use yii\helpers\Url;
use app\widgets\TablerGridView as GridView;
use app\components\Crypt;

/* @var $this yii\web\View */
/* @var $searchModel app\models\search\SettingsSearch */
/* @var $dataProvider yii\data\ActiveDataProvider */

$this->title = Yii::t('app', 'Impostazioni');
$this->params['breadcrumbs'][] = $this->title;
?>

    <div class="row row-cards">
        <div class="col-lg-12">
            <div class="settings-index">
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex flex-wrap align-items-center gap-2 w-100">
                            <h3 class="card-title mb-0"><?= Html::encode($this->title) ?></h3>
                            <div class="ms-auto">
                                <?= Html::a('<i class="fas fa-plus"></i> ' . Yii::t('app', 'Aggiungi'), ['create'], ['class' => 'btn btn-primary']) ?>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div>
                            <?= GridView::widget([
                                'dataProvider' => $dataProvider,
                                'filterModel' => $searchModel,
                                'columns' => [
                                    // ['class' => 'yii\grid\SerialColumn'],

                                    // 'id',
                                    // view button
                                    [
                                        'class' => 'yii\grid\ActionColumn',
                                        'template' => '{view}',
                                        'contentOptions' => [
                                            'style' => 'width: 35px;'
                                        ],
                                        'buttons' => [
                                            'view' => function ($url, $model) {
                                                $url = Url::to(['view', 'id' => Crypt::encrypt($model->id)]);
                                                return Html::a('<i class="fa fa-eye"></i>', $url, [
                                                    'title' => Yii::t('app', 'Vista'),
                                                    'class' => 'btn btn-sm btn-outline-secondary',
                                                ]);
                                            },

                                        ],

                                    ],
                                    'description',
                                    'code',
                                    [
                                        'attribute' => 'value',
                                        'format' => 'raw',
                                        'value' => function ($data) {
                                            return Html::encode($data->value);
                                        },
                                        'contentOptions' => ['class' => 'text-break'],
                                    ],

                                    // ['class' => 'yii\grid\ActionColumn'],
                                ],
                            ]); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>