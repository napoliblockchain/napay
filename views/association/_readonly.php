<?php

use yii\helpers\Html;

/* @var $this yii\web\View */
?>
<?php foreach (Yii::$app->session->getAllFlashes() as $type => $messages): ?>
    <?php foreach ((array) $messages as $message): ?>
        <div class="alert alert-<?= in_array($type, ['success', 'danger', 'warning', 'info'], true) ? $type : 'info' ?> d-print-none" role="status"><?= Html::encode($message) ?></div>
    <?php endforeach; ?>
<?php endforeach; ?>
<?php if (!empty($this->params['associationReadOnly'])): ?>
    <div class="alert alert-info d-print-none" role="status">
        <?= Html::encode(Yii::t('app', 'Dati sincronizzati dal vecchio Napay a ogni avvio: consultazione in sola lettura.')) ?>
    </div>
<?php endif; ?>
