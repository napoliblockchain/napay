<?php

use yii\helpers\Html;
use yii\helpers\Url;
use app\components\Crypt;

/* @var $payments app\models\MembershipPayments[] */
/* @var $showMember bool */
$f = Yii::$app->formatter;
$writable = empty($this->params['associationReadOnly']);
?>
<table class="table table-vcenter card-table">
    <thead>
    <tr>
        <th><?= Yii::t('app', 'Ricevuta') ?></th>
        <th><?= Yii::t('app', 'Data') ?></th>
        <?php if ($showMember): ?><th><?= Yii::t('app', 'Socio') ?></th><?php endif; ?>
        <th><?= Yii::t('app', 'Quota') ?></th>
        <th class="text-end"><?= Yii::t('app', 'Importo') ?></th>
        <th><?= Yii::t('app', 'Metodo') ?></th>
        <th><?= Yii::t('app', 'Stato') ?></th>
        <th><?= Yii::t('app', 'Scadenza') ?></th>
        <th class="w-1"></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($payments as $p): ?>
        <tr>
            <td><?= Html::encode($p->receiptLabel()) ?></td>
            <td><?= $f->asDate($p->registered_on, 'php:d/m/Y') ?></td>
            <?php if ($showMember): ?>
                <td><?= $p->user ? Html::a(Html::encode(trim($p->user->last_name . ' ' . $p->user->first_name)), ['member', 'id' => Crypt::encrypt($p->user_id)]) : '' ?></td>
            <?php endif; ?>
            <td><?= Html::encode($p->fee->description ?? '') ?></td>
            <td class="text-end"><?= $f->asCurrency($p->amount, 'EUR') ?></td>
            <td><?= Html::encode($p->method->description ?? '') ?></td>
            <td><?= $this->render('_status', ['status' => $p->status, 'label' => $p->statusLabel()]) ?></td>
            <td><?= $f->asDate($p->expires_on, 'php:d/m/Y') ?></td>
            <td class="text-nowrap">
                <?= Html::a('<i class="fas fa-receipt"></i>', ['receipt', 'id' => Crypt::encrypt($p->id)], ['class' => 'btn btn-sm btn-outline-secondary', 'title' => Yii::t('app', 'Ricevuta')]) ?>
                <?php if ($writable): ?><?= Html::a('<i class="fas fa-pen"></i>', ['payment-update', 'id' => Crypt::encrypt($p->id)], ['class' => 'btn btn-sm btn-outline-secondary', 'title' => Yii::t('app', 'Modifica')]) ?><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$payments): ?>
        <tr><td colspan="<?= $showMember ? 9 : 8 ?>" class="text-secondary"><?= Yii::t('app', 'Nessun pagamento.') ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>
