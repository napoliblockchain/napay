<?php

use yii\helpers\Html;
use app\components\Crypt;
use app\components\web\LinkPager;

/* @var $this yii\web\View */
/* @var $admin bool */
/* @var $stores array ID negozio cifrato => etichetta */
/* @var $store string|null filtro attivo (ID cifrato) */
/* @var $terminals yii\data\ActiveDataProvider */
/* @var $operators yii\data\ActiveDataProvider */

$this->title = Yii::t('app', 'Terminali POS');
$this->params['breadcrumbs'][] = $this->title;
?>
<?= $this->render('_flash') ?>
<div class="card mb-3">
    <div class="card-header">
        <h3 class="card-title"><?= Html::encode(Yii::t('app', 'Terminali')) ?></h3>
        <div class="card-actions d-flex gap-2">
            <?= Html::beginForm(['index'], 'get', ['class' => 'd-flex gap-2']) ?>
            <?= Html::dropDownList('store', $store, $stores, ['class' => 'form-select', 'prompt' => Yii::t('app', 'Tutti i negozi'), 'aria-label' => Yii::t('app', 'Negozio')]) ?>
            <?= Html::submitButton(Html::encode(Yii::t('app', 'Filtra')), ['class' => 'btn btn-outline-secondary']) ?>
            <?= Html::endForm() ?>
            <?= Html::a('<i class="fas fa-plus me-1"></i>' . Html::encode(Yii::t('app', 'Nuovo terminale')), ['terminal-create'], ['class' => 'btn btn-primary']) ?>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead><tr>
                <?php if ($admin): ?><th><?= Html::encode(Yii::t('app', 'Merchant')) ?></th><?php endif; ?>
                <th><?= Html::encode(Yii::t('app', 'Negozio - POS')) ?></th>
                <th><?= Html::encode(Yii::t('app', 'Stato')) ?></th>
                <th><?= Html::encode(Yii::t('app', 'Disponibilità')) ?></th>
                <th class="w-1"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($terminals->getModels() as $t): ?>
                <tr>
                    <?php if ($admin): ?><td><?= Html::encode($t['merchant']) ?></td><?php endif; ?>
                    <td><?= Html::encode($t['store'] . ' - ' . $t['name']) ?></td>
                    <td><?= $this->render('_status', ['enabled' => (int) $t['is_enabled'] === 1]) ?></td>
                    <td class="text-secondary"><?= Html::encode(Yii::t('app', 'Non verificata')) ?></td>
                    <td class="text-nowrap">
                        <?= Html::a('<i class="fas fa-pen"></i>', ['terminal-update', 'id' => $t['public_id']], ['class' => 'btn btn-sm btn-outline-secondary', 'title' => Yii::t('app', 'Modifica')]) ?>
                        <?= (int) $t['is_enabled'] === 1
                            ? $this->render('_post', ['action' => ['terminal-disable', 'id' => $t['public_id']], 'label' => Yii::t('app', 'Disabilita'), 'class' => 'btn-outline-danger'])
                            : $this->render('_post', ['action' => ['terminal-enable', 'id' => $t['public_id']], 'label' => Yii::t('app', 'Abilita'), 'class' => 'btn-outline-success']) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$terminals->getCount()): ?><tr><td colspan="<?= $admin ? 5 : 4 ?>" class="text-secondary"><?= Html::encode(Yii::t('app', 'Nessun terminale.')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex"><div class="ms-auto"><?= LinkPager::widget(['pagination' => $terminals->pagination]) ?></div></div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><?= Html::encode(Yii::t('app', 'Operatori')) ?></h3>
    </div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead><tr>
                <?php if ($admin): ?><th><?= Html::encode(Yii::t('app', 'Merchant')) ?></th><?php endif; ?>
                <th><?= Html::encode(Yii::t('app', 'Operatore')) ?></th>
                <th><?= Html::encode(Yii::t('app', 'Email')) ?></th>
                <th><?= Html::encode(Yii::t('app', 'Stato')) ?></th>
                <th class="w-1"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($operators->getModels() as $o): ?>
                <tr>
                    <?php if ($admin): ?><td><?= Html::encode($o['merchant']) ?></td><?php endif; ?>
                    <td><?= Html::encode(trim($o['first_name'] . ' ' . $o['last_name'])) ?></td>
                    <td><?= Html::encode($o['email']) ?></td>
                    <td><?= $this->render('_status', ['enabled' => (int) $o['is_enabled'] === 1]) ?></td>
                    <td><?= Html::a(Html::encode(Yii::t('app', 'Gestisci')), ['operator', 'id' => Crypt::encrypt($o['user_id'])], ['class' => 'btn btn-sm btn-outline-primary']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$operators->getCount()): ?><tr><td colspan="<?= $admin ? 5 : 4 ?>" class="text-secondary"><?= Html::encode(Yii::t('app', 'Nessun operatore.')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex"><div class="ms-auto"><?= LinkPager::widget(['pagination' => $operators->pagination]) ?></div></div>
</div>
