<?php

use yii\helpers\Html;
use yii\helpers\Url;
use yii\grid\GridView;
use app\components\Crypt;

use yii\helpers\ArrayHelper;
use app\models\Privileges;
use yii\bootstrap5\ActiveForm;

/* @var $this yii\web\View */
/* @var $searchModel app\models\search\UsersSearch */
/* @var $dataProvider yii\data\ActiveDataProvider */

$this->title = Yii::t('app', 'Utenti');
$this->params['breadcrumbs'][] = $this->title;


$privilege_list = ArrayHelper::map(Privileges::find()->byLevelLessThen(Yii::$app->user->identity->privilege->level)->all(), 'id', function ($data) {
    return $data->description;
});
// echo '<pre>'.print_r($privilege_list,true);exit;

$yesOrNot = [
    0 => Yii::t('app', 'No'),
    1 => Yii::t('app', 'Si'),
    \app\models\Users::STATUS_BLOCKED => Yii::t('app', 'Blocked'),
];
?>

<div class="row">

    <div class="col-md-12">
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
                        <?= Html::hiddenInput('queryParams', $queryParams) ?>

                        <?= Html::submitButton('<i class="fas fa-file-excel mr-1"></i>' . Yii::t('app', 'Esporta'), [
                            'class' => 'btn btn-success',
                            'target' => '_blank'
                        ]) ?>
                        <?php ActiveForm::end(); ?>
                    </div>
                </div>
            </div>
            <!-- /.card-header -->
            <div class="card-body">
                <div class="table-responsive">
                    <?= GridView::widget([
                        'dataProvider' => $dataProvider,
                        'filterModel' => $searchModel,
                        // 'layout' => "{summary}\n{items}\n{pager}",
                        // 'tableOptions' => [
                        //     'class' => 'table table-sm table-valign-middle',
                        // ],


                        'columns' => [


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
                                            'title' => Yii::t('app', 'View'),
                                            'class' => 'btn btn-sm btn-default',
                                        ]);
                                    },

                                ],

                            ],

                            'username',
                            'first_name',
                            'last_name',
                            'email:email',
                            [
                                'attribute' => 'merchantSearch',
                                'format' => 'raw',
                                'value' => function ($data) {
                                    return Html::encode($data->merchants[0]->description ?? '');
                                },
                                'filter' => false
                            ],
                            
                            [
                                'attribute' => 'privilege_id',
                                'format' => 'raw',
                                'value' => function ($data) {
                                    return Html::encode($data->privilege->description ?? '');
                                },
                                'filter' => $privilege_list,
                            ],

                            [
                                'attribute' => 'is_active',
                                'format' => 'raw',
                                'value' => function ($data) use ($yesOrNot) {
                                    return $yesOrNot[$data->is_active] ?? Yii::t('app', 'Disabled');
                                },
                                'filter' => $yesOrNot,
                            ],
                        ],
                    ]); ?>


                </div>
            </div>
        </div>
    </div>

</div>
