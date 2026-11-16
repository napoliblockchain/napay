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
    'panel' => [
        'heading' => Yii::t('app', 'Generale'),
        'type' => DetailView::TYPE_INFO,
    ],
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
            'attribute' => 'speedPolicy',
            'value' => Html::encode($model->storesettings->speedPolicy),
            'format' => 'raw',
        ],
        
        
        [
            'attribute' => 'networkFeeMode',
            'value' => Html::encode($model->storesettings->networkFeeMode),
            'format' => 'raw',
        ],
        [
            'attribute' => 'invoiceExpiration',
            'value' => Html::encode(\Yii::$app->formatter->asTime($model->storesettings->invoiceExpiration)),
            'format' => 'raw',
        ],
        
        [
            'attribute' => 'defaultCurrencyPair',
            'format' => 'raw',
            'value' => Html::encode($model->storesettings->defaultPaymentMethod . '_' . $model->storesettings->defaultCurrency)
        ],
        [
            'attribute' => 'spread',
            'value' => Html::encode($model->storesettings->spread . '%'),
            'format' => 'raw',
        ],
        [
            'attribute' => 'Tasso per esercente',
            'value' => Html::encode(\Yii::$app->formatter->asCurrency((float) $rates)),
            'format' => 'raw',
        ],

        // importo_iniziale = 30750 / (1 - 5/100) = 32368.42€ (approssimato a due decimali)
        [
            'attribute' => 'Tasso originale senza spread',
            'value' => Html::encode(\Yii::$app->formatter->asCurrency((float) $rates / (1 - ($model->storesettings->spread / 100)))),
            'format' => 'raw',
        ],



    ],
]) ?>

<?php if (User::isMerchant()): ?>
<div class="card-footer">
    <div class="d-flex flex-row">
        <div class="p-1">
            <?= Html::a('<i class="fas fa-pen"></i> ' . Yii::t('app', 'Modifica'), ['update-general', 'id' => Crypt::encrypt($model->id)], ['class' => 'btn btn-primary']) ?>
        </div>

        <div class="p-1 ml-auto">
            <?= Html::a('<i class="fas fa-trash"></i> ' . Yii::t('app', 'Elimina'), ['delete', 'id' => Crypt::encrypt($model->id)], [
                'class' => 'btn btn-danger',
                'data' => [
                    'confirm' => Yii::t('app', 'Vuoi eliminare questo negozio?'),
                    'method' => 'post',
                ],
            ]) ?>
        </div>
    </div>
</div>
<?php endif; ?>