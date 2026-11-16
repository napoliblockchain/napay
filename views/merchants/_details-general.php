<?php
use kartik\detail\DetailView;
use yii\helpers\Html;
use app\components\btcpay\provisioning\ProvisioningStatus;

?>

<?= DetailView::widget([
    'model' => $model,
    'condensed' => true,
    'hover' => true,
    'mode' => DetailView::MODE_VIEW,
    'enableEditMode' => FALSE,
    // 'panel' => [
    //     'heading' => Yii::t('app', 'Generale'),
    //     'type' => DetailView::TYPE_INFO,
    // ],
    'labelColOptions' => ['style' => 'width:15%'],
    'valueColOptions' => ['style' => 'width:35%'],
    'attributes' => [
        [
            'attribute' => 'user_id',
            'format' => 'raw',
            'value' => Html::encode($model->user->username)
        ],
        'description',
        'vatNumber',
        'addressStreet',
        'addressNumberHouse',
        'addressCity',
        'addressZip',
        'addressProvince',
        'addressCountry',
        
        'email:email',
        'phone',
        'mobile',
        [
            'label' => Yii::t('app', 'BTCPay account'),
            'format' => 'raw',
            'value' => ProvisioningStatus::badge($btcpayState = ProvisioningStatus::merchant((int) $model->id))
                . $this->render('/btcpay-provisioning/_retry', ['kind' => 'merchant_account', 'entityId' => (int) $model->id]),
            'visible' => $btcpayState !== null,
        ],
    ],
]) ?>

