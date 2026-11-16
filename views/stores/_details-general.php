<?php

use yii\helpers\Html;
use yii\helpers\Url;
use app\components\Crypt;
use app\components\User;
use kartik\detail\DetailView;

?>

<?= DetailView::widget([
    'model' => $model,
    'condensed' => true,
    'hover' => true,
    'mode' => DetailView::MODE_VIEW,
    'enableEditMode' => FALSE,
    // 'panel' => [
    //     'heading' => Yii::t('app', 'General'),
    //     'type' => DetailView::TYPE_INFO,
    // ],
    'labelColOptions' => ['style' => 'width:40%'],
    'valueColOptions' => ['style' => 'width:60%'],
    'attributes' => [
        [
            'attribute' => 'merchant_id',
            'format' => 'raw',
            'value' => Html::encode($model->merchant->description ?? null),
            'visible' => User::hasRoleAtLeast(User::ROLE_MERCHANT),
        ],
        [
            'label' => $model->getAttributeLabel('bps_storeid'),
            'format' => 'raw',
            'value' =>  call_user_func(function ($data) {
                return Html::encode($data->bps_storeid);
            }, $model->storesettings),
        ],
        
        [
            'attribute' => 'description',
            'format' => 'raw',
            'value' => Html::encode($model->description ?? null)
        ],
       
        [
            'attribute' => 'defaultCurrency',
            'value' => Html::encode($model->storesettings->defaultCurrency),
            'format' => 'raw',
        ],
        [
            'label' => Yii::t('app', 'BTC exchange rate'),
            'value' => Html::encode(\Yii::$app->formatter->asCurrency((float) $rates, $model->storesettings->defaultCurrency)),
            'format' => 'raw',
        ],
    ],
]) ?>

<?php if (User::isMerchant()): ?>
<div class="card-footer">
    <div class="d-flex flex-row">
        <div class="p-1">
            <?= Html::a('<i class="fas fa-pen"></i> ' . Yii::t('app', 'Edit'), ['update-general', 'id' => Crypt::encrypt($model->id)], ['class' => 'btn btn-primary']) ?>
        </div>

        <div class="p-1 ml-auto">
            <?= Html::a('<i class="fas fa-trash"></i> ' . Yii::t('app', 'Delete'), ['delete', 'id' => Crypt::encrypt($model->id)], [
                'class' => 'btn btn-danger',
                'data' => [
                    'confirm' => Yii::t('app', 'Delete this store?'),
                    'method' => 'post',
                ],
            ]) ?>
        </div>
    </div>
</div>
<?php endif; ?>