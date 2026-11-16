<?php

/* Wallet BTC del negozio (derivazione BTCPay), in sola lettura: la modifica passa dal provisioning (SIP-022). */

use yii\helpers\Html;
use kartik\detail\DetailView;

?>

<?= DetailView::widget([
    'model' => $model,
    'condensed' => true,
    'hover' => true,
    'mode' => DetailView::MODE_VIEW,
    'enableEditMode' => FALSE,
    'panel' => [
        'heading' => Yii::t('app', 'Wallet'),
        'type' => DetailView::TYPE_INFO,
    ],
    'labelColOptions' => ['style' => 'width:40%'],
    'valueColOptions' => ['style' => 'width:60%'],
    'attributes' => [
        [
            'attribute' => 'derivationLabel',
            'format' => 'raw',
            'value' => Html::encode($model->storesettings->label),
        ],
        
        [
            'attribute' => 'derivationScheme',
            'format' => 'raw',
            'value' => Html::encode($model->storesettings->derivationScheme)
        ],
        [
            'attribute' => 'derivationAccountKeyPath',
            'format' => 'raw',
            'value' => Html::encode($model->storesettings->accountKeyPath)
        ],

       

    ],
]) ?>
