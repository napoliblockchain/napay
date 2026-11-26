<?php

use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\grid\GridView;
use yii\grid\CheckboxColumn;

/* @var $this yii\web\View */
/* @var $dataProvider yii\data\ArrayDataProvider righe di MembershipRequests::query() */
/* @var $readOnly bool import dal vecchio Napay attivo */

$this->title = Yii::t('app', 'Membership requests');
$this->params['breadcrumbs'][] = $this->title;

if (!$readOnly && $dataProvider->getTotalCount() > 0) {
    $confirm = Json::htmlEncode(Yii::t('app', 'Reject the selected requests? The accounts are deleted and the reason is sent by email.'));
    // Keep native submission: Yii data-confirm drops the submitter and its formaction.
    $this->registerJs(<<<JS
document.getElementById('membership-requests-form').addEventListener('submit', function (event) {
    const button = event.submitter;
    if (!button || button.name !== 'decision' || (button.value === 'reject' && !window.confirm($confirm))) {
        event.preventDefault();
    }
});
JS);
}
?>
<?php if ($readOnly): ?>
    <div class="alert alert-info"><?= Html::encode(Yii::t('app', 'Dati sincronizzati dal vecchio Napay: modifica disattivata.')) ?></div>
<?php endif; ?>
<div class="card" data-membership-requests>
    <div class="card-header">
        <h3 class="card-title"><?= Html::encode($this->title) ?></h3>
    </div>
    <div class="card-body border-bottom text-secondary small">
        <?= Html::encode(Yii::t('app', 'Only requests with a verified email are listed; accounts never verified are deleted after {days} days. An approved member can log in and pay the fee; a rejected request deletes the account.', ['days' => app\components\association\MembershipRequests::UNVERIFIED_DAYS])) ?>
    </div>
    <?= Html::beginForm(['index'], 'post', ['id' => 'membership-requests-form']) ?>
    <div class="table-responsive">
        <?= GridView::widget([
            'dataProvider' => $dataProvider,
            'tableOptions' => ['class' => 'table table-vcenter card-table'],
            'emptyText' => Yii::t('app', 'No membership requests to review.'),
            'layout' => "{items}\n<div class=\"card-footer d-flex align-items-center\">{summary}<div class=\"ms-auto\">{pager}</div></div>",
            'columns' => [
                ['class' => CheckboxColumn::class, 'visible' => !$readOnly],
                [
                    'label' => Yii::t('app', 'Name'),
                    'format' => 'raw',
                    'value' => static fn($row) => Html::encode(trim($row['last_name'] . ' ' . $row['first_name']))
                        . ((int) $row['is_corporate'] === 1 && $row['denomination'] ? '<div class="text-secondary small">' . Html::encode($row['denomination']) . '</div>' : ''),
                ],
                ['label' => Yii::t('app', 'Email'), 'value' => static fn($row) => $row['email']],
                ['label' => Yii::t('app', 'Type'), 'value' => static fn($row) => (int) $row['is_corporate'] === 1 ? Yii::t('app', 'Legal person') : Yii::t('app', 'Natural person')],
                [
                    'label' => Yii::t('app', 'Requested on'),
                    'format' => 'raw',
                    'value' => static fn($row) => $row['requested_at'] ? Html::encode(Yii::$app->formatter->asDatetime((int) $row['requested_at'], 'php:d/m/Y H:i'))
                        : '<span class="badge bg-secondary-lt" title="' . Html::encode(Yii::t('app', 'Request imported from the old Napay: email not verified')) . '">' . Html::encode(Yii::t('app', 'Old Napay')) . '</span>',
                ],
            ],
        ]) ?>
    </div>
    <?php if (!$readOnly && $dataProvider->getTotalCount() > 0): ?>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label" for="membership-reject-reason"><?= Html::encode(Yii::t('app', 'Reason for the rejection (sent by email)')) ?></label>
                <?= Html::textarea('reason', '', ['id' => 'membership-reject-reason', 'class' => 'form-control', 'rows' => 3, 'maxlength' => 1000]) ?>
            </div>
            <div class="d-flex gap-2">
                <?= Html::submitButton('<i class="fas fa-check me-1" aria-hidden="true"></i>' . Html::encode(Yii::t('app', 'Approve selected')), [
                    'class' => 'btn btn-success', 'formaction' => Url::to(['approve']), 'name' => 'decision', 'value' => 'approve',
                ]) ?>
                <?= Html::submitButton('<i class="fas fa-times me-1" aria-hidden="true"></i>' . Html::encode(Yii::t('app', 'Reject selected')), [
                    'class' => 'btn btn-outline-danger', 'formaction' => Url::to(['reject']), 'name' => 'decision', 'value' => 'reject',
                ]) ?>
            </div>
        </div>
    <?php endif; ?>
    <?= Html::endForm() ?>
</div>
