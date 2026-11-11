<?php

use yii\helpers\Html;

/* @var $action array rotta della cancellazione */
/* @var $label string */
// POST con CSRF: nessuna cancellazione via link GET
echo Html::beginForm($action, 'post', ['class' => 'd-inline'])
    . Html::submitButton('<i class="fas fa-trash"></i>' . ($label !== '' ? ' ' . Html::encode($label) : ''), ['class' => 'btn btn-sm btn-outline-danger', 'title' => Yii::t('app', 'Elimina')])
    . Html::endForm();
