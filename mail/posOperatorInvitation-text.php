<?php

/* @var $link string */
/* @var $hours int */
?>
<?= Yii::t('app', 'Sei stato invitato come operatore di cassa POS su {app}.', ['app' => Yii::$app->name]) ?>

<?= Yii::t('app', 'Apri il link per creare un account dedicato alla cassa (valido {hours} ore, utilizzabile una sola volta):', ['hours' => $hours]) ?>

<?= $link ?>


<?= Yii::t('app', 'Se non ti aspettavi questo invito, ignora il messaggio.') ?>
