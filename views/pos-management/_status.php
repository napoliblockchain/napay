<?php

use yii\helpers\Html;

/* @var $enabled bool */
echo $enabled
    ? Html::tag('span', Html::encode(Yii::t('app', 'Abilitato')), ['class' => 'badge bg-success-lt'])
    : Html::tag('span', Html::encode(Yii::t('app', 'Disabilitato')), ['class' => 'badge bg-secondary-lt']);
