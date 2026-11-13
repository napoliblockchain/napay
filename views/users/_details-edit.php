<?php

use yii\helpers\Html;
use yii\bootstrap5\ActiveForm;
use app\components\Crypt;

/* @var $model app\models\Users */
?>
<p class="text-secondary"><?= Html::encode(Yii::t('app', 'Gestisci lo stato di accesso. Credenziali, ruolo e appartenenze non vengono modificati da questa pagina.')) ?></p>
<?php $form = ActiveForm::begin(['id' => 'users-form', 'action' => ['update', 'id' => Crypt::encrypt($model->id)]]); ?>
<?= $form->errorSummary($model) ?>
<div class="row">
    <div class="col-md-6">
        <?= $form->field($model, 'is_active')->dropDownList([
            0 => Yii::t('app', 'Inattivo'), 1 => Yii::t('app', 'Attivo'),
        ], ['prompt' => Yii::t('app', 'Seleziona stato')])->label(Yii::t('app', 'Stato account')) ?>
    </div>
</div>
<div class="d-flex justify-content-end border-top pt-3 mt-3">
    <?= Html::submitButton(Html::encode(Yii::t('app', 'Salva stato')), ['class' => 'btn btn-primary',
        'data-confirm' => Yii::t('app', 'Confermi la modifica dello stato account?')]) ?>
</div>
<?php ActiveForm::end(); ?>
