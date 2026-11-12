<?php

use yii\helpers\Html;

/* @var $this yii\web\View */
$this->title = Yii::t('app', 'Invito operatore POS');
echo $this->render('_card', ['title' => $this->title,
    'content' => Html::tag('p', Html::encode(Yii::t('app', 'Sei connesso con un altro account. Esci e riapri il link: l\'invito crea un account separato e non si collega a quello attuale.')))]);
