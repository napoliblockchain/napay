<?php

use yii\helpers\Html;

/* @var $this yii\web\View */
$this->title = Yii::t('app', 'Invito operatore POS');
echo $this->render('_card', ['title' => $this->title,
    'content' => Html::tag('p', Html::encode(Yii::t('app', 'Invito non valido, già usato o scaduto. Chiedi al gestore di inviarne uno nuovo.')))]);
