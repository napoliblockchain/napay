<?php

use yii\helpers\Html;

/* @var $this yii\web\View */
$this->title = Yii::t('app', 'POS operator invitation');
echo $this->render('_card', ['title' => $this->title,
    'content' => Html::tag('p', Html::encode(Yii::t('app', 'Operator account created. You can sign in to the POS once the manager has enabled the profile and assigned the shops.')))]);
