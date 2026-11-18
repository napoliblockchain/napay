<?php

use yii\helpers\Html;
use yii\helpers\ArrayHelper;
use app\components\Crypt;

/* @var $this yii\web\View */
/* @var $operator array */
/* @var $grants array */
/* @var $available array negozi del merchant senza grant */
/* @var $eligible bool merchant qualificato e account operatore coerente (solo presentazione) */
/* @var $grantForm app\models\forms\pos\GrantForm */
/* @var $admin bool */

$id = Crypt::encrypt($operator['user_id']);
$this->title = trim($operator['first_name'] . ' ' . $operator['last_name']);
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'POS terminals'), 'url' => ['index', 'tab' => 'operators']];
$this->params['breadcrumbs'][] = $this->title;
$enabled = (int) $operator['is_enabled'] === 1;
?>
<?php if (!$eligible): ?>
    <div class="alert alert-warning" role="status"><?= Html::encode(Yii::t('app', 'Account or merchant not eligible: profile and stores can only be disabled.')) ?></div>
<?php endif; ?>
<div class="card mb-3">
    <div class="card-header">
        <h3 class="card-title"><?= Html::encode($this->title) ?></h3>
        <div class="card-actions">
            <?= $enabled
                ? $this->render('_post', ['action' => ['operator-disable', 'id' => $id], 'label' => Yii::t('app', 'Disable and sign out'), 'class' => 'btn-outline-danger'])
                : ($eligible ? $this->render('_post', ['action' => ['operator-enable', 'id' => $id], 'label' => Yii::t('app', 'Enable'), 'class' => 'btn-outline-success']) : '') ?>
        </div>
    </div>
    <div class="card-body">
        <dl class="row mb-0">
            <?php if ($admin): ?>
                <dt class="col-sm-3"><?= Html::encode(Yii::t('app', 'Merchant')) ?></dt><dd class="col-sm-9"><?= Html::encode($operator['merchant']) ?></dd>
            <?php endif; ?>
            <dt class="col-sm-3"><?= Html::encode(Yii::t('app', 'Email')) ?></dt><dd class="col-sm-9"><?= Html::encode($operator['email']) ?></dd>
            <dt class="col-sm-3"><?= Html::encode(Yii::t('app', 'Status')) ?></dt><dd class="col-sm-9"><?= $this->render('_status', ['enabled' => $enabled]) ?></dd>
        </dl>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3 class="card-title"><?= Html::encode(Yii::t('app', 'Assigned stores')) ?></h3></div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead><tr><th><?= Html::encode(Yii::t('app', 'Store')) ?></th><th><?= Html::encode(Yii::t('app', 'Status')) ?></th><th class="w-1"></th></tr></thead>
            <tbody>
            <?php foreach ($grants as $g): ?>
                <?php $store = Crypt::encrypt($g['store_id']); ?>
                <tr>
                    <td><?= Html::encode($g['store']) ?></td>
                    <td><?= $this->render('_status', ['enabled' => (int) $g['is_enabled'] === 1]) ?></td>
                    <td class="text-nowrap">
                        <?= (int) $g['is_enabled'] === 1
                            ? $this->render('_post', ['action' => ['grant-disable', 'id' => $id, 'store' => $store], 'label' => Yii::t('app', 'Disable'), 'class' => 'btn-outline-danger'])
                            : ($eligible ? $this->render('_post', ['action' => ['grant-enable', 'id' => $id, 'store' => $store], 'label' => Yii::t('app', 'Enable'), 'class' => 'btn-outline-success']) : '') ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$grants): ?><tr><td colspan="3" class="text-secondary"><?= Html::encode(Yii::t('app', 'No store assigned.')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($eligible && $available): ?>
        <div class="card-footer">
            <?= Html::beginForm(['grant-create', 'id' => $id], 'post', ['class' => 'd-flex gap-2']) ?>
            <?= Html::activeDropDownList($grantForm, 'store', ArrayHelper::map($available, fn($s) => Crypt::encrypt($s['id']), 'description'), ['class' => 'form-select', 'prompt' => Yii::t('app', 'Select the store')]) ?>
            <?= Html::submitButton(Html::encode(Yii::t('app', 'Assign store')), ['class' => 'btn btn-primary']) ?>
            <?= Html::endForm() ?>
            <div class="form-hint mt-2"><?= Html::encode(Yii::t('app', 'The assigned store starts disabled.')) ?></div>
        </div>
    <?php endif; ?>
</div>
