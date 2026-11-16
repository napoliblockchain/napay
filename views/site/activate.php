<?php

/** @var yii\web\View $this */

use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;

$this->title = Yii::t('app', 'Confirm account activation');
?>
<h1 class="auth-title"><?= Html::encode($this->title) ?></h1>
<p class="auth-subtitle"><?= Html::encode(Yii::t('app', 'Confirm to activate your account. The link expires after 48 hours.')) ?></p>

<?php $form = ActiveForm::begin(['id' => 'activation-form']); ?>
<?= Html::submitButton(Html::encode(Yii::t('app', 'Confirm')), ['class' => 'auth-submit']) ?>
<?php ActiveForm::end(); ?>
