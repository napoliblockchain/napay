<?php

use yii\helpers\Html;

?>
<p><?= Html::encode(Yii::t('app', 'Qualcuno ha chiesto di invitare questo indirizzo come operatore di cassa POS su {app}.', ['app' => Yii::$app->name])) ?></p>
<p><?= Html::encode(Yii::t('app', 'Questo indirizzo è già usato da un account: per lavorare in cassa serve un account separato con un\'altra email. Comunica al gestore un indirizzo diverso.')) ?></p>
<p><?= Html::encode(Yii::t('app', 'Il tuo account attuale non è stato modificato. Se non ti aspettavi questo messaggio, ignoralo.')) ?></p>
