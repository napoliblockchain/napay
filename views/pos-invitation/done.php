<?php

use yii\helpers\Html;

/* @var $this yii\web\View */
$this->title = Yii::t('app', 'Invito operatore POS');
echo $this->render('_card', ['title' => $this->title,
    'content' => Html::tag('p', Html::encode(Yii::t('app', 'Account operatore creato. Potrai accedere alla cassa quando il gestore avrà abilitato il profilo e assegnato i negozi.')))]);
