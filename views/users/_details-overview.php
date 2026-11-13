<?php

use yii\helpers\Html;

/* @var $model app\models\Users */
$fields = [
    Yii::t('app', 'Nome') => $model->first_name,
    Yii::t('app', 'Cognome') => $model->last_name,
    Yii::t('app', 'Email') => $model->email,
    Yii::t('app', 'Nome utente') => $model->username,
    Yii::t('app', 'Profilo di accesso') => $model->privilege->description ?? '',
];
?>
<h4 class="subheader mb-3"><?= Html::encode(Yii::t('app', 'Dati account')) ?></h4>
<dl class="row mb-0" data-napay-account-details>
    <?php foreach ($fields as $label => $value): ?>
        <dt class="col-sm-4 text-secondary fw-normal mb-sm-3"><?= Html::encode($label) ?></dt>
        <dd class="col-sm-8 text-break mb-3"><?= Html::encode($value !== null && $value !== '' ? $value : '-') ?></dd>
    <?php endforeach; ?>
</dl>
