<?php

use yii\helpers\Html;
use yii\widgets\DetailView;

/* @var $this yii\web\View */
/* @var $member app\models\Members */
/* @var $payments app\models\MembershipPayments[] */

$this->title = trim($member->last_name . ' ' . $member->first_name);
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Soci'), 'url' => ['members']];
$this->params['breadcrumbs'][] = $this->title;
$profile = $member->profile;
$writable = empty($this->params['associationReadOnly']);
?>
<?= $this->render('_readonly') ?>
<div class="row row-cards">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><?= Html::encode($this->title) ?></h3>
                <div class="card-actions">
                    <?= $this->render('_status', ['status' => $member->memberStatus(), 'label' => $member->memberStatusLabel()]) ?>
                    <?php if ($writable): ?>
                        <?= Html::a('<i class="fas fa-pen"></i>', ['member-update', 'id' => \app\components\Crypt::encrypt($member->id)], ['class' => 'btn btn-sm btn-outline-secondary ms-2', 'title' => Yii::t('app', 'Modifica profilo')]) ?>
                    <?php endif; ?>
                </div>
            </div>
            <?= DetailView::widget([
                'model' => $member,
                'options' => ['class' => 'table table-vcenter card-table'],
                'attributes' => [
                    'email:email',
                    ['label' => Yii::t('app', 'Profilo'), 'value' => $member->privilege->description ?? ''],
                    ['label' => Yii::t('app', 'Carica'), 'value' => $profile->role->description ?? '-'],
                    ['label' => Yii::t('app', 'Abilitato'), 'value' => (int) $member->is_active === 1 ? Yii::t('app', 'Si') : Yii::t('app', 'No')],
                    ['label' => Yii::t('app', 'Scadenza quota'), 'value' => $member->last_expiry ? Yii::$app->formatter->asDate($member->last_expiry, 'php:d/m/Y') : '-'],
                    ['label' => Yii::t('app', 'Persona'), 'value' => $profile && $profile->is_corporate ? Yii::t('app', 'Giuridica') : Yii::t('app', 'Fisica')],
                    ['label' => Yii::t('app', 'Ragione sociale'), 'value' => $profile->denomination ?? '-'],
                    ['label' => Yii::t('app', 'C.F./P.IVA'), 'value' => $profile->vat ?? '-'],
                    ['label' => Yii::t('app', 'Indirizzo'), 'value' => trim(implode(' ', array_filter([$profile->address ?? null, $profile->cap ?? null, $profile->city ?? null, $profile->country ?? null])))],
                ],
            ]) ?>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><?= Yii::t('app', 'Quote associative') ?></h3>
                <?php if ($writable): ?>
                    <div class="card-actions"><?= Html::a('<i class="fas fa-plus me-1"></i>' . Yii::t('app', 'Registra pagamento'), ['payment-create', 'user' => \app\components\Crypt::encrypt($member->id)], ['class' => 'btn btn-primary']) ?></div>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <?= $this->render('_payments-table', ['payments' => $payments, 'showMember' => false]) ?>
            </div>
        </div>
    </div>
</div>
