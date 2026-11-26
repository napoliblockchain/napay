<?php

use yii\helpers\Html;
use app\models\ConsentEvents;
use app\models\LegalDocuments;

/* @var $this yii\web\View */
/* @var $consensus app\models\UserConsensus|null stato corrente */
/* @var $events ConsentEvents[] storico, dal più recente */
/* @var $readOnly bool vista admin di un altro account */

$this->title = $readOnly ? Yii::t('app', 'Consents of the member') : Yii::t('app', 'My consents');
$this->params['breadcrumbs'][] = $this->title;
$version = static function ($id): string {
    $doc = $id === null ? null : LegalDocuments::findOne((int) $id);
    return $doc === null ? Html::encode(Yii::t('app', 'version not recorded'))
        : Html::a(Html::encode(Yii::t('app', 'version {n}', ['n' => (int) $doc->version])), ['/legal/view', 'type' => $doc->type, 'version' => (int) $doc->version]);
};
$marketing = $consensus !== null && (int) $consensus->consenso_marketing === 1;
?>
<div class="card mb-3" data-consents="state">
    <div class="card-header"><h3 class="card-title"><?= Html::encode($this->title) ?></h3></div>
    <div class="card-body">
        <?php if ($consensus === null): ?>
            <p class="text-secondary"><?= Html::encode(Yii::t('app', 'No consent recorded for this account.')) ?></p>
        <?php else: ?>
            <dl class="row mb-0">
                <dt class="col-sm-4"><?= Html::encode(Yii::t('app', 'Statute')) ?></dt>
                <dd class="col-sm-8"><?= Html::encode((int) $consensus->consenso_statuto === 1 ? Yii::t('app', 'Accepted') : Yii::t('app', 'Not recorded')) ?></dd>
                <dt class="col-sm-4"><?= Html::encode(Yii::t('app', 'Privacy notice')) ?></dt>
                <dd class="col-sm-8"><?= (int) $consensus->consenso_privacy === 1 ? Html::encode(Yii::t('app', 'Read')) . ', ' . $version($consensus->privacy_document_id) : Html::encode(Yii::t('app', 'Not recorded')) ?></dd>
                <dt class="col-sm-4"><?= Html::encode(Yii::t('app', 'Terms of use')) ?></dt>
                <dd class="col-sm-8"><?= (int) $consensus->consenso_condizioni === 1 ? Html::encode(Yii::t('app', 'Accepted')) . ', ' . $version($consensus->terms_document_id) : Html::encode(Yii::t('app', 'Not recorded')) ?></dd>
                <dt class="col-sm-4"><?= Html::encode(Yii::t('app', 'Marketing communications')) ?></dt>
                <dd class="col-sm-8"><?= Html::encode($marketing ? Yii::t('app', 'Consent given') : Yii::t('app', 'Not given')) ?></dd>
            </dl>
            <?php if (!$readOnly): ?>
                <?= Html::beginForm(['/site/marketing'], 'post', ['class' => 'mt-3']) ?>
                <?= Html::hiddenInput('marketing', $marketing ? '0' : '1') ?>
                <?= Html::submitButton(Html::encode($marketing ? Yii::t('app', 'Withdraw marketing consent') : Yii::t('app', 'Give marketing consent')), ['class' => $marketing ? 'btn btn-outline-danger' : 'btn btn-outline-primary']) ?>
                <?= Html::endForm() ?>
                <p class="text-secondary small mt-3 mb-0"><?= Html::encode(Yii::t('app', 'Statute and terms of use cannot be withdrawn one by one: they end by leaving the association or closing the account.')) ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<div class="card" data-consents="history">
    <div class="card-header"><h3 class="card-title"><?= Html::encode(Yii::t('app', 'History')) ?></h3></div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead><tr>
                <th><?= Html::encode(Yii::t('app', 'Date')) ?></th>
                <th><?= Html::encode(Yii::t('app', 'Item')) ?></th>
                <th><?= Html::encode(Yii::t('app', 'Choice')) ?></th>
                <th><?= Html::encode(Yii::t('app', 'Where')) ?></th>
            </tr></thead>
            <tbody>
            <?php if ($events === []): ?>
                <tr><td colspan="4" class="text-secondary"><?= Html::encode(Yii::t('app', 'No events.')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($events as $e): ?>
                <tr>
                    <td class="text-nowrap"><?= Html::encode($e->created_at > 0 ? Yii::$app->formatter->asDatetime((int) $e->created_at, 'php:d/m/Y H:i') : '-') ?></td>
                    <td><?= Html::encode(ConsentEvents::itemLabel($e->item)) ?></td>
                    <td><?= Html::encode(ConsentEvents::actionLabel($e->item, $e->action)) ?><?= $e->document_id !== null ? ' (' . $version($e->document_id) . ')' : '' ?></td>
                    <td><?= Html::encode(ConsentEvents::sourceLabel($e->source)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
