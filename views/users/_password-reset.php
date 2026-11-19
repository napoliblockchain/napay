<?php
use yii\helpers\Html;
use app\components\{Crypt, User};
?>
<?php if (User::isAdministrator() && (int) $model->id !== (int) Yii::$app->user->id): ?>
    <?= Html::beginForm(['/users/send-password-reset', 'id' => Crypt::encrypt($model->id)], 'post', ['class' => 'mt-3']) ?>
    <?= Html::submitButton(Html::encode(Yii::t('app', 'Send password reset link')), ['class' => 'btn btn-outline-primary']) ?>
    <?= Html::endForm() ?>
<?php endif; ?>
