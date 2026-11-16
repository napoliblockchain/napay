<?php

/* Wallet BTC del negozio, in sola lettura. Schema di derivazione e key path gestiti internamente da Napay (SIP-022), mai mostrati. */

use yii\helpers\Html;
use kartik\detail\DetailView;

?>

<?= DetailView::widget([
    'model' => $model,
    'condensed' => true,
    'hover' => true,
    'mode' => DetailView::MODE_VIEW,
    'enableEditMode' => FALSE,
    // 'panel' => [
    //     'heading' => Yii::t('app', 'Wallet'),
    //     'type' => DetailView::TYPE_INFO,
    // ],
    'labelColOptions' => ['style' => 'width:40%'],
    'valueColOptions' => ['style' => 'width:60%'],
    'attributes' => [
        [
            'attribute' => 'derivationLabel',
            'format' => 'raw',
            'value' => Html::encode($model->storesettings->label),
        ],

    ],
]) ?>
