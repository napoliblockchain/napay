<?php

use yii\helpers\Html;
use yii\helpers\Url;
use app\widgets\TablerGridView as GridView;
use app\components\Crypt;
use app\components\User;
use yii\bootstrap5\ActiveForm;

/* @var $this yii\web\View */
/* @var $searchModel app\models\search\StoresSearch */
/* @var $dataProvider yii\data\ActiveDataProvider */

$this->title = Yii::t('app', 'Negozi');
$this->params['breadcrumbs'][] = $this->title;

$status_list = ['Attivo', 'Disabilitato'];
$speedPolicy = [
    'HighSpeed' => Yii::t('app', 'High Speed: 0 confirmations'),
    'MediumSpeed' => Yii::t('app', 'Medium Speed: 1 confirmations'),
    'LowMediumSpeed' => Yii::t('app', 'Low Medium Speed: 2 confirmations'),
    'LowSpeed' => Yii::t('app', 'Low Speed: 6 confirmations'),

];
?>
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="stores-index">
                <?= app\widgets\Alert::widget() ?>
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex flex-wrap align-items-center gap-2 w-100">
                            <h3 class="card-title mb-0"><?= Html::encode($this->title) ?></h3>

                            <?php if (User::can(User::ROLE_MERCHANT)) : ?>
                                <div class="ms-auto p-1">
                                    <?php $form = ActiveForm::begin([
                                        'id' => 'export-form',
                                        'action' => ['export'],
                                        'method' => 'post',
                                    ]); ?>
                                    <?= Html::hiddenInput('queryParams', $queryParams) ?>

                                    <?= Html::submitButton('<i class="fas fa-file-excel me-1"></i>' . Yii::t('app', 'Esporta'), [
                                        'class' => 'btn btn-success',
                                        'target' => '_blank'
                                    ]) ?>
                                    <?php ActiveForm::end(); ?>
                                </div>
                                <div class="p-1">
                                    <?= Html::a('<i class="fas fa-plus"></i> ' . Yii::t('app', 'Nuovo negozio'), ['create'], ['class' => 'btn btn-primary']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?= GridView::widget([
                            'dataProvider' => $dataProvider,
                            'filterModel' => $searchModel,
                            'columns' => [
                                // ['class' => 'yii\grid\SerialColumn'],
                                [
                                    'attribute' => 'id',
                                    'format' => 'raw',
                                    'value' => function ($data) {
                                        return $data->id;
                                    },
                                    'contentOptions' => [
                                        'style' => 'width: 60px;',
                                        'class' => 'text-center',
                                    ],
                                ],

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
                                                'title' => Yii::t('app', 'Dettaglio'),
                                                'class' => 'btn btn-sm btn-outline-secondary',
                                            ]);
                                        },

                                    ],

                                ],

                                // 'id',
                                // [
                                //     'attribute' => 'merchant_id',
                                //     'format' => 'raw',
                                //     'value' => function ($data) {
                                //         return $data->merchant->description;
                                //     },
                                // ],
                                [
                                    'attribute' => 'merchantName',
                                    'value' => 'merchant.description',
                                    'visible' => User::can(40),
                                ],
                                'description',
                                [
                                    'attribute' => 'storesettings.speedPolicy',
                                    'value' => function ($data) use ($speedPolicy) {
                                        return $speedPolicy[$data->storesettings->speedPolicy];
                                    },
                                    // 'contentOptions' => ['class' => 'text-break'],
                                ],
                                [
                                    'attribute' => 'storesettings.spread',
                                    'value' => function ($data) {
                                        return \Yii::$app->formatter->asPercent($data->storesettings->spread / 100, 2);
                                    },
                                ],
                                // [
                                //     'attribute' => 'historical',
                                //     'label' => 'Stato',
                                //     'value' => function ($data) use ($status_list) {
                                //         return $status_list[$data->historical];
                                //     },
                                //     'filter' => $status_list

                                // ],

                                // [
                                //     'attribute' => 'bps_storeid',
                                //     'format' => 'raw',
                                //     'value' => function ($data) {
                                //         return $data->storesettings->bps_storeid;
                                //     },
                                //     'contentOptions' => ['class' => 'text-break'],
                                // ],


                                // ['class' => 'yii\grid\ActionColumn'],

                            ],
                        ]); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>