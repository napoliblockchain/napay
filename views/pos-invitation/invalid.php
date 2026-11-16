<?php

use yii\helpers\Html;

/* @var $this yii\web\View */
$this->title = Yii::t('app', 'POS operator invitation');
echo $this->render('_card', ['title' => $this->title,
    'content' => Html::tag('p', Html::encode(Yii::t('app', 'Invitation invalid, already used or expired. Ask the manager to send a new one.')))]);
