<?php

use yii\helpers\Html;

/* @var $link string */
/* @var $hours int */
?>
<p><?= Html::encode(Yii::t('app', 'Sei stato invitato come operatore di cassa POS su {app}.', ['app' => Yii::$app->name])) ?></p>
<p><?= Html::encode(Yii::t('app', 'Apri il link per creare un account dedicato alla cassa (valido {hours} ore, utilizzabile una sola volta):', ['hours' => $hours])) ?></p>
<p><?= Html::a(Html::encode($link), $link) ?></p>
<p><?= Html::encode(Yii::t('app', 'Se non ti aspettavi questo invito, ignora il messaggio.')) ?></p>
