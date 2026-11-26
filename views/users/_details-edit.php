<?php

use yii\helpers\Html;
use app\components\Crypt;

/* @var $model app\models\Users */
?>
<p class="text-secondary"><?= Html::encode(Yii::t('app', 'Gestisci lo stato di accesso. Credenziali, ruolo e appartenenze non vengono modificati da questa pagina.')) ?></p>
<?php $active = (int) $model->is_active === app\models\Users::STATUS_ACTIVE; ?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3" data-user-status>
    <div>
        <span class="text-secondary me-2"><?= Html::encode(Yii::t('app', 'Stato account')) ?></span>
        <?= Html::tag('span', Html::encode($active ? Yii::t('app', 'Attivo') : Yii::t('app', 'Inattivo')), ['class' => 'badge ' . ($active ? 'bg-success-lt' : 'bg-secondary-lt')]) ?>
    </div>
    <?php if ($active): ?>
        <?= Html::beginForm(['disable', 'id' => Crypt::encrypt($model->id)], 'post') ?>
        <?= Html::submitButton('<i class="fas fa-user-slash me-1" aria-hidden="true"></i>' . Html::encode(Yii::t('app', 'Disable account')), ['class' => 'btn btn-outline-danger',
            'data-confirm' => Yii::t('app', 'Disable this account? The user is logged out everywhere and cannot log in until the account is enabled again.')]) ?>
        <?= Html::endForm() ?>
    <?php else: ?>
        <?= Html::beginForm(['enable', 'id' => Crypt::encrypt($model->id)], 'post') ?>
        <?= Html::submitButton('<i class="fas fa-user-check me-1" aria-hidden="true"></i>' . Html::encode(Yii::t('app', 'Enable account')), ['class' => 'btn btn-success',
            'data-confirm' => Yii::t('app', 'Enable this account? The user can log in again.')]) ?>
        <?= Html::endForm() ?>
    <?php endif; ?>
</div>
<?= $this->render('_password-reset', ['model' => $model]) ?>
