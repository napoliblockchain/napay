<?php

use yii\helpers\Html;
use yii\helpers\Url;
use app\widgets\TablerGridView as GridView;
use yii\jui\DatePicker;
use app\components\Crypt;
use app\components\User;
use yii\bootstrap5\ActiveForm;

/* @var $this yii\web\View */
/* @var $searchModel app\models\search\InvoicesSearch */
/* @var $dataProvider yii\data\ActiveDataProvider */

$this->title = Yii::t('app', 'Transazioni');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="invoices-index">
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex flex-wrap align-items-center gap-2 w-100">
                            <h3 class="card-title mb-0"><?= Html::encode($this->title) ?></h3>
                            <div class="p-1 ms-auto">
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
                        </div>
                    </div>

                    <div class="card-body p-0">
                        <?= GridView::widget([
                            'dataProvider' => $dataProvider,
                            'filterModel' => $searchModel,
                            'columns' => [
                                // ['class' => 'yii\grid\SerialColumn'],

                                // 'id',
                                // [
                                //     'attribute' => 'id',
                                //     'format' => 'raw',
                                //     'value' => function ($data) {
                                //         return $data->id;
                                //     },
                                //     'contentOptions' => [
                                //         'style' => 'width: 60px;',
                                //         'class' => 'text-center',
                                //     ],
                                // ],

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
                                                'class' => 'btn btn-sm btn-outline-secondary',
                                            ]);
                                        },

                                    ],

                                ],
                                'invoiceId',
                                [
                                    'attribute' => 'createdTime',
                                    // 'createdTime:datetime',
                                    'value' => function ($data) {
                                        return $data->createdTime;
                                        // return Yii::$app->formatter->asDate(($data->createdTime), 'php:d/m/Y');
                                    },
                                    'format' => ['DateTime', 'php:H:i:s d/m/Y'],
                                    'filter' => DatePicker::widget([
                                        'model' => $searchModel,
                                        'attribute' => 'createdTime',
                                        'dateFormat' => 'dd/MM/yyyy',
                                        'options' => [
                                            'class' => 'form-control',
                                        ],
                                    ]),

                                ],

                                [
                                    'attribute' => 'merchantName',
                                    'value' => 'merchant.description',
                                    'visible' => User::can(40),
                                ],
                                [
                                    'attribute' => 'storeName',
                                    'value' => 'store.description',
                                    'visible' => User::can(30),
                                ],

                                [
                                    'attribute' => 'posName',
                                    'value' => 'pos.appName',
                                    // 'visible' => User::can(30),
                                ],
                                
                                // 'destination',
                                // [
                                //     'attribute' => 'destination',
                                //     'label' => Yii::t('app','Destination'),
                                //     'format' => 'raw',
                                //     'value' => function ($data) {
                                //         $html = '';
                                //         if (!empty($data->payments)){
                                //             foreach ($data->payments as $p){
                                //                 // $html .= '<p>'.$p->paymentMethod .': '. $p->destination.'</p>';
                                //                 $html .= '<p class="single-line"><b>' . $p->paymentMethod . '</b>: ' . $p->destination . '</p>';
                                //             }
                                //         }
                                //         return $html;
                                //     },
                                //     'contentOptions' => ['class' => 'text-break'],
                                // ],

                                
                                'invoiceType',
                                [
                                    'attribute' => 'amount',
                                    'value' => function ($data) {
                                        return Yii::$app->formatter->asCurrency(($data->amount), $data->currency);
                                    },
                                ],
                                'status',
                                //'metadata',
                                //'checkout',
                                //'receipt',
                                //'storeId',
                                // 'currency',
                                // 'type',
                                //'checkoutLink',
                                // 'createdTime:datetime',
                                // 'expirationTime:datetime',
                                //'monitoringExpiration',
                                // 'additionalStatus',
                                // 'availableStatusesForManualMarking',

                                // ['class' => 'yii\grid\ActionColumn'],
                            ],
                        ]); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>