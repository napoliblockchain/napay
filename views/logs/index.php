<?php

use yii\helpers\Html;
use yii\helpers\Url;
use yii\grid\GridView;
use yii\jui\DatePicker;
use app\components\Crypt;
use yii\bootstrap5\ActiveForm;

/* @var $this yii\web\View */
/* @var $searchModel app\models\search\LogsSearch */
/* @var $dataProvider yii\data\ActiveDataProvider */

$this->title = Yii::t('app', 'Logs');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="logs-index">
                <div class="card card-outline card-primary">
                    <div class="card-header">
                        <div class="d-flex flex-row">
                            <h3><?= Html::encode($this->title) ?></h3>
                            <div class="ml-auto p-1">
                                <?php $form = ActiveForm::begin([
                                    'id' => 'export-form',
                                    'action' => ['export'],
                                    'method' => 'post',
                                ]); ?>
                                <input type='hidden' name='queryParams' value='<?= $queryParams ?>' />

                                <?= Html::submitButton('<i class="fas fa-file-excel mr-1"></i>' . Yii::t('app', 'Esporta'), [
                                    'class' => 'btn btn-success',
                                    'target' => '_blank'
                                ]) ?>
                                <?php ActiveForm::end(); ?>
                            </div>
                        </div>
                    </div>

                    <div class="card-body table-responsive">
                        <?= GridView::widget([
                            'dataProvider' => $dataProvider,
                            'filterModel' => $searchModel,
                            'columns' => [
                                // ['class' => 'yii\grid\SerialColumn'],

                                'id',
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
                                                'title' => Yii::t('app', 'Dettagli'),
                                                'class' => 'btn btn-sm btn-default',
                                            ]);
                                        },

                                    ],

                                ],

                                // [
                                //     'attribute' => 'timestamp',
                                //     'format' => 'raw',
                                //     'value' => function ($data) {
                                //         return Yii::$app->formatter->asDate(($data->timestamp), 'php:d/m/Y h:i:s');
                                //     },
                                // ],
                                [
                                    'attribute' => 'timestamp',
                                    'label' => 'Data',
                                    'value' => function ($data) {
                                        return $data->timestamp;
                                    },
                                    'format' => ['DateTime', 'php:d/m/Y H:i:s'],
                                    'filter' => DatePicker::widget([
                                        'model' => $searchModel,
                                        'attribute' => 'timestamp',
                                        'dateFormat' => 'dd/MM/yyyy',
                                        'options' => [
                                            'class' => 'form-control',
                                        ],
                                    ]),
                                ],
                                // [
                                //     'attribute' => 'user_id',
                                //     'format' => 'raw',
                                //     'value' => function ($data) {
                                //         return $data->user->username ?? null;
                                //     },
                                // ],
                                [
                                    'attribute' => 'event_type',
                                    'format' => 'raw',
                                    'filter' => array_map(fn($t) => Yii::t('app', $t['label']), \app\models\Logs::eventTypes()),
                                    'value' => function ($data) {
                                        $type = \app\models\Logs::eventTypes()[$data->event_type] ?? ['label' => $data->event_type, 'badge' => 'bg-secondary-lt'];
                                        return Html::tag('span', Html::encode(Yii::t('app', $type['label'])), ['class' => 'badge ' . $type['badge']]);
                                    },
                                ],
                                'controller',
                                'action',
                                'remote_address',
                                'browser',
                                'description:ntext',



                                // ['class' => 'yii\grid\ActionColumn'],
                            ],
                        ]); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>