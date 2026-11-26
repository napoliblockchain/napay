<?php

use yii\helpers\Html;

/* @var $this yii\web\View */
?>
<?php if (!empty($this->params['associationReadOnly'])): ?>
    <div class="alert alert-info d-print-none" role="status">
        <?= Html::encode(Yii::t('app', 'Dati sincronizzati dal vecchio Napay a ogni avvio: consultazione in sola lettura.')) ?>
    </div>
<?php endif; ?>
