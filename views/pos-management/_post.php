<?php

use yii\helpers\Html;

/* @var $action array rotta della scrittura */
/* @var $label string */
/* @var $class string */
// POST con CSRF: nessuna scrittura via link GET
echo Html::beginForm($action, 'post', ['class' => 'd-inline'])
    . Html::submitButton(Html::encode($label), ['class' => 'btn btn-sm ' . $class])
    . Html::endForm();
