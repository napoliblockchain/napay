<?php
use yii\helpers\Html;
use yii\bootstrap5\ActiveForm;
$this->title = Yii::t('app', 'Request activation link');
?>
<div class="container mt-4">
    <div class="card mx-auto" style="max-width: 36rem">
        <div class="card-header"><h4><?= Html::encode($this->title) ?></h4></div>
        <div class="card-body">
            <p>Inserisci l'email di registrazione. Se l'account deve ancora essere attivato, riceverai un nuovo link.</p>
            <?php $form = ActiveForm::begin(['id' => 'resend-activation-form']); ?>
            <?= $form->field($model, 'email')->textInput(['type' => 'email', 'autocomplete' => 'email', 'maxlength' => 255]) ?>
            <?= Html::submitButton(Yii::t('app', 'Submit'), ['class' => 'btn btn-primary']) ?>
            <?php ActiveForm::end(); ?>
        </div>
    </div>
</div>
