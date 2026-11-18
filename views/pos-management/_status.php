<?php

use yii\helpers\Html;

/* @var $enabled bool */
echo $enabled
    ? Html::tag('span', Html::encode(Yii::t('app', 'Enabled')), ['class' => 'badge bg-success-lt'])
    : Html::tag('span', Html::encode(Yii::t('app', 'Disabled')), ['class' => 'badge bg-secondary-lt']);
