<?php

use yii\helpers\Html;
use yii\helpers\Url;
use yii\grid\GridView;
use app\components\Crypt;
?>
<div class="card card-outline card-primary">
    <div class="card-header border-0">
        <h3 class="card-title">
            <?= Html::a('<i class="fa fa-list"></i>', Url::to(['logs/index']), [
                'title' => Yii::t('app', 'List'),
                'class' => 'btn btn-sm btn-default',
            ])  ?>
            <?= Yii::t('app', 'Logs') ?>
        </h3>
    </div>
    <div class="card-body table-responsive p-1">
        <?= GridView::widget([
            'dataProvider' => $dataLogs,
            'tableOptions' => [
                'class' => 'table table-sm  table-valign-middle small',
            ],
            'summary' => false,
            'columns' => [
                // view button
                [
                    'class' => 'yii\grid\ActionColumn',
                    'template' => '{view}',
                    'contentOptions' => [
                        'style' => 'width: 35px;'
                    ],
                    'buttons' => [
                        'view' => function ($url, $model) {

                            $url = Url::to(['logs/view', 'id' => Crypt::encrypt($model->id)]);
                            return Html::a('<i class="fa fa-eye"></i>', $url, [
                                'title' => Yii::t('app', 'View'),
                                'class' => 'btn btn-sm btn-default',
                            ]);
                        },

                    ],

                ],
                'id',

                // 'timestamp:datetime',
                [
                    'attribute' => 'timestamp',
                    'format' => 'raw',
                    'value' => function ($data) {
                        return Yii::$app->formatter->asDate(($data->timestamp), 'php:d/m/Y h:i:s');
                    },
                ],
                // 'user_id',
                'remote_address',
                // 'browser',
                'controller',
                'action',
                'description:ntext',

            ],
        ]); ?>


    </div>
</div>