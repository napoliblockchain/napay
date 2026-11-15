<?php

use yii\helpers\Html;
use app\components\Crypt;
use app\components\web\LinkPager;
use app\components\pos\management\PosInvitations;

/* @var $this yii\web\View */
/* @var $admin bool */
/* @var $stores array ID negozio cifrato => etichetta */
/* @var $store string|null filtro attivo (ID cifrato) */
/* @var $terminals yii\data\ActiveDataProvider */
/* @var $openings array aperture attive per ID interno del terminale */
/* @var $operators yii\data\ActiveDataProvider */
/* @var $invitations yii\data\ActiveDataProvider */
/* @var $invitationForm app\models\forms\pos\InvitationForm */
/* @var $merchants array ID merchant cifrato => nome (solo admin) */
/* @var $tab string scheda attiva: terminals | invitations | operators */

$this->title = Yii::t('app', 'POS terminals');
$this->params['breadcrumbs'][] = $this->title;
// fasi distinte: prima l'invito, poi l'abilitazione di chi l'ha accettato
$tabs = [
    'terminals' => Yii::t('app', 'Terminals'),
    'invitations' => Yii::t('app', '1. Invite operators'),
    'operators' => Yii::t('app', '2. Enable operators'),
];
?>
<div class="card">
    <div class="card-header">
        <ul class="nav nav-tabs card-header-tabs">
            <?php foreach ($tabs as $key => $label): ?>
                <li class="nav-item">
                    <?= Html::a(Html::encode($label), ['index', 'tab' => $key], ['class' => 'nav-link' . ($tab === $key ? ' active' : ''), 'aria-current' => $tab === $key ? 'page' : null]) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php if ($tab === 'terminals'): ?>
    <div class="card-body border-bottom d-flex flex-wrap gap-2 justify-content-end">
            <?= Html::beginForm(['index'], 'get', ['class' => 'd-flex gap-2']) ?>
            <?= Html::hiddenInput('tab', 'terminals') ?>
            <?= Html::dropDownList('store', $store, $stores, ['class' => 'form-select', 'prompt' => Yii::t('app', 'All stores'), 'aria-label' => Yii::t('app', 'Store')]) ?>
            <?= Html::submitButton(Html::encode(Yii::t('app', 'Filter')), ['class' => 'btn btn-outline-secondary']) ?>
            <?= Html::endForm() ?>
            <?= Html::a('<i class="fas fa-plus me-1"></i>' . Html::encode(Yii::t('app', 'New terminal')), ['terminal-create'], ['class' => 'btn btn-primary']) ?>
    </div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead><tr>
                <?php if ($admin): ?><th><?= Html::encode(Yii::t('app', 'Merchant')) ?></th><?php endif; ?>
                <th><?= Html::encode(Yii::t('app', 'Store - POS')) ?></th>
                <th><?= Html::encode(Yii::t('app', 'Status')) ?></th>
                <th><?= Html::encode(Yii::t('app', 'Availability')) ?></th>
                <th class="w-1 text-end"><?= Html::encode(Yii::t('app', 'Actions')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($terminals->getModels() as $t): ?>
                <tr>
                    <?php if ($admin): ?><td><?= Html::encode($t['merchant']) ?></td><?php endif; ?>
                    <td><?= Html::encode($t['store'] . ' - ' . $t['name']) ?></td>
                    <td><?= $this->render('_status', ['enabled' => (int) $t['is_enabled'] === 1]) ?></td>
                    <?php $open = $openings[$t['id']] ?? null; ?>
                    <td>
                        <?php if ($open === null): ?>
                            <span class="text-secondary"><?= Html::encode(Yii::t('app', 'Free')) ?></span>
                        <?php else: ?>
                            <span class="badge bg-warning-lt"><?= Html::encode(Yii::t('app', 'In use')) ?></span>
                            <div class="small text-secondary"><?= Html::encode(Yii::t('app', '{name}, since {opened}; last contact {seen}', [
                                'name' => trim($open['first_name'] . ' ' . $open['last_name']),
                                'opened' => Yii::$app->formatter->asDatetime((int) $open['opened_at'], 'short'),
                                'seen' => Yii::$app->formatter->asRelativeTime((int) $open['last_seen_at']),
                            ])) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap text-end">
                        <?= Html::a('<i class="fas fa-pen"></i>', ['terminal-update', 'id' => $t['public_id']], ['class' => 'btn btn-sm btn-outline-secondary', 'title' => Yii::t('app', 'Edit')]) ?>
                        <?= (int) $t['is_enabled'] === 1
                            ? $this->render('_post', ['action' => ['terminal-disable', 'id' => $t['public_id']], 'label' => Yii::t('app', 'Disable'), 'class' => 'btn-outline-danger'])
                            : $this->render('_post', ['action' => ['terminal-enable', 'id' => $t['public_id']], 'label' => Yii::t('app', 'Enable'), 'class' => 'btn-outline-success']) ?>
                        <?= $open === null ? '' : $this->render('_post', ['action' => ['terminal-unlock', 'id' => $t['public_id']], 'label' => Yii::t('app', 'Unlock'),
                            'class' => 'btn-outline-warning', 'confirm' => Yii::t('app', 'Unlock the terminal? The device using it will no longer be able to operate.')]) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$terminals->getCount()): ?><tr><td colspan="<?= $admin ? 5 : 4 ?>" class="text-secondary"><?= Html::encode(Yii::t('app', 'No terminals.')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex"><div class="ms-auto"><?= LinkPager::widget(['pagination' => $terminals->pagination]) ?></div></div>
<?php elseif ($tab === 'invitations'): ?>
    <div class="card-body border-bottom">
        <?= Html::beginForm(['invitation-create'], 'post', ['class' => 'row g-2 align-items-end']) ?>
        <?php if ($admin): ?>
            <div class="col-md-4">
                <?= Html::activeLabel($invitationForm, 'merchant', ['class' => 'form-label']) ?>
                <?= Html::activeDropDownList($invitationForm, 'merchant', $merchants, ['class' => 'form-select', 'prompt' => Yii::t('app', 'Select the merchant')]) ?>
            </div>
        <?php endif; ?>
        <div class="col-md-5">
            <?= Html::activeLabel($invitationForm, 'email', ['class' => 'form-label']) ?>
            <?= Html::activeInput('email', $invitationForm, 'email', ['class' => 'form-control', 'maxlength' => PosInvitations::EMAIL_MAX, 'autocomplete' => 'off']) ?>
        </div>
        <div class="col-md-3"><?= Html::submitButton(Html::encode(Yii::t('app', 'Send invitation')), ['class' => 'btn btn-primary']) ?></div>
        <?= Html::endForm() ?>
        <div class="form-hint mt-2"><?= Html::encode(Yii::t('app', 'An address not yet used on Napay is required: existing members use another email. The operator profile starts disabled and without stores.')) ?></div>
    </div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead><tr>
                <?php if ($admin): ?><th><?= Html::encode(Yii::t('app', 'Merchant')) ?></th><?php endif; ?>
                <th><?= Html::encode(Yii::t('app', 'Email')) ?></th>
                <th><?= Html::encode(Yii::t('app', 'Status')) ?></th>
                <th><?= Html::encode(Yii::t('app', 'Expiry')) ?></th>
                <th class="w-1 text-end"><?= Html::encode(Yii::t('app', 'Actions')) ?></th>
            </tr></thead>
            <tbody>
            <?php $now = time(); foreach ($invitations->getModels() as $i): ?>
                <?php $id = Crypt::encrypt($i['id']); $open = $i['consumed_at'] === null && $i['revoked_at'] === null; ?>
                <tr>
                    <?php if ($admin): ?><td><?= Html::encode($i['merchant']) ?></td><?php endif; ?>
                    <td><?= Html::encode($i['email']) ?></td>
                    <td><?= Html::encode(PosInvitations::status($i, $now)) ?></td>
                    <td><?= Html::encode(Yii::$app->formatter->asDatetime((int) $i['expires_at'], 'php:d/m/Y H:i')) ?></td>
                    <td class="text-nowrap text-end">
                        <?php if ($open): ?>
                            <?= $this->render('_post', ['action' => ['invitation-resend', 'id' => $id], 'label' => Yii::t('app', 'Resend'), 'class' => 'btn-outline-secondary']) ?>
                            <?= $this->render('_post', ['action' => ['invitation-revoke', 'id' => $id], 'label' => Yii::t('app', 'Cancel'), 'class' => 'btn-outline-danger']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$invitations->getCount()): ?><tr><td colspan="<?= $admin ? 5 : 4 ?>" class="text-secondary"><?= Html::encode(Yii::t('app', 'No invitations.')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex"><div class="ms-auto"><?= LinkPager::widget(['pagination' => $invitations->pagination]) ?></div></div>
<?php else: ?>
    <div class="card-body border-bottom">
        <div class="form-hint"><?= Html::encode(Yii::t('app', 'Who accepted an invitation appears here, disabled and without stores: open it to enable it and assign the stores where it can use the POS.')) ?></div>
    </div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead><tr>
                <?php if ($admin): ?><th><?= Html::encode(Yii::t('app', 'Merchant')) ?></th><?php endif; ?>
                <th><?= Html::encode(Yii::t('app', 'Operator')) ?></th>
                <th><?= Html::encode(Yii::t('app', 'Email')) ?></th>
                <th><?= Html::encode(Yii::t('app', 'Status')) ?></th>
                <th class="w-1 text-end"><?= Html::encode(Yii::t('app', 'Actions')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($operators->getModels() as $o): ?>
                <tr>
                    <?php if ($admin): ?><td><?= Html::encode($o['merchant']) ?></td><?php endif; ?>
                    <td><?= Html::encode(trim($o['first_name'] . ' ' . $o['last_name'])) ?></td>
                    <td><?= Html::encode($o['email']) ?></td>
                    <td><?= $this->render('_status', ['enabled' => (int) $o['is_enabled'] === 1]) ?></td>
                    <td class="text-nowrap text-end"><?= Html::a(Html::encode(Yii::t('app', 'Manage')), ['operator', 'id' => Crypt::encrypt($o['user_id'])], ['class' => 'btn btn-sm btn-outline-primary']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$operators->getCount()): ?><tr><td colspan="<?= $admin ? 5 : 4 ?>" class="text-secondary"><?= Html::encode(Yii::t('app', 'No operators.')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex"><div class="ms-auto"><?= LinkPager::widget(['pagination' => $operators->pagination]) ?></div></div>
<?php endif; ?>
</div>
