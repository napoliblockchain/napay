<?php

use yii\helpers\Html;

/* @var $action array rotta della scrittura */
/* @var $label string */
/* @var $class string */
/* @var $confirm string|null richiesta di conferma (yii.js data-confirm) */
// POST con CSRF: nessuna scrittura via link GET
echo Html::beginForm($action, 'post', ['class' => 'd-inline'])
    . Html::submitButton(Html::encode($label), ['class' => 'btn btn-sm ' . $class, 'data-confirm' => $confirm ?? null])
    . Html::endForm();
