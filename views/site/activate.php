<?php
use yii\helpers\Html;
use yii\bootstrap5\ActiveForm;
$this->title = Yii::t('app', 'Confirm account activation');
?>
<div class="container mt-4">
    <div class="card mx-auto" style="max-width: 36rem">
        <div class="card-header"><h4><?= Html::encode($this->title) ?></h4></div>
        <div class="card-body">
            <p>Conferma per attivare il tuo account. Il link scade dopo 48 ore.</p>
            <?php $form = ActiveForm::begin(['id' => 'activation-form']); ?>
            <?= Html::submitButton(Yii::t('app', 'Confirm'), ['class' => 'btn btn-primary']) ?>
            <?php ActiveForm::end(); ?>
        </div>
    </div>
</div>
