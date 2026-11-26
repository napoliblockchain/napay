<?php

use yii\helpers\Html;
use app\models\LegalDocuments;

/* @var $this yii\web\View */
/* @var $documents array<string, LegalDocuments[]> */

$this->title = Yii::t('app', 'Legal documents');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="alert alert-info">
    <?= Html::encode(Yii::t('app', 'A new draft starts from the standard text with the data of the data controller from the settings. Have it approved by the association before publishing: published versions cannot be changed.')) ?>
</div>
<?php foreach (LegalDocuments::TYPES as $type): ?>
<div class="card mb-3" data-legal-type="<?= Html::encode($type) ?>">
    <div class="card-header">
        <h3 class="card-title"><?= Html::encode(LegalDocuments::typeLabel($type)) ?></h3>
        <div class="card-actions d-flex gap-2">
            <?= Html::a(Html::encode(Yii::t('app', 'Public page')), ['legal/view', 'type' => $type], ['class' => 'btn btn-sm btn-outline-secondary', 'target' => '_blank', 'rel' => 'noopener']) ?>
            <?= Html::beginForm(['draft'], 'post') . Html::hiddenInput('type', $type)
                . Html::submitButton(Html::encode(Yii::t('app', 'New draft')), ['class' => 'btn btn-sm btn-primary']) . Html::endForm() ?>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead><tr>
                <th class="w-1"><?= Html::encode(Yii::t('app', 'Version')) ?></th>
                <th><?= Html::encode(Yii::t('app', 'Title')) ?></th>
                <th><?= Html::encode(Yii::t('app', 'Status')) ?></th>
                <th class="w-1"></th>
            </tr></thead>
            <tbody>
            <?php if (empty($documents[$type])): ?>
                <tr><td colspan="4" class="text-secondary"><?= Html::encode(Yii::t('app', 'Never published.')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($documents[$type] ?? [] as $d): ?>
                <tr>
                    <td><?= (int) $d->version ?></td>
                    <td><?= Html::encode($d->title) ?></td>
                    <td><?= $d->status === LegalDocuments::PUBLISHED
                        ? Html::encode(Yii::t('app', 'Published on {date}', ['date' => Yii::$app->formatter->asDate((int) $d->published_at, 'php:d/m/Y')]))
                        : '<span class="badge bg-warning-lt">' . Html::encode(Yii::t('app', 'Draft')) . '</span>' ?></td>
                    <td class="text-nowrap">
                        <?= $d->status === LegalDocuments::DRAFT
                            ? Html::a(Html::encode(Yii::t('app', 'Edit')), ['update', 'id' => (int) $d->id], ['class' => 'btn btn-sm btn-outline-primary'])
                            : Html::a(Html::encode(Yii::t('app', 'View')), ['preview', 'id' => (int) $d->id], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>
