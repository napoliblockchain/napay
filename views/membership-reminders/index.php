<?php

use yii\helpers\Html;
use yii\grid\GridView;
use yii\grid\CheckboxColumn;
use app\components\association\MembershipReminders;

/* @var $this yii\web\View */
/* @var $dataProvider yii\data\ArrayDataProvider righe di MembershipReminders::rows() */
/* @var $readOnly bool import dal vecchio Napay attivo */

$this->title = Yii::t('app', 'Fee reminders');
$this->params['breadcrumbs'][] = $this->title;
$f = Yii::$app->formatter;
?>
<?php if ($readOnly): ?>
    <div class="alert alert-info"><?= Html::encode(Yii::t('app', 'Dati sincronizzati dal vecchio Napay: modifica disattivata.')) ?></div>
<?php endif; ?>
<div class="card" data-membership-reminders>
    <div class="card-header"><h3 class="card-title"><?= Html::encode($this->title) ?></h3></div>
    <div class="card-body border-bottom text-secondary small">
        <?= Html::encode(Yii::t('app', 'Members whose fee expires within {list} days or expired less than a year ago. The first reminder is sent automatically {auto} days before expiry; the next ones from here, at least {pause} days apart. Administrators, members who never paid and members who turned reminders off are excluded.',
            ['list' => MembershipReminders::LIST_DAYS, 'auto' => MembershipReminders::AUTO_DAYS, 'pause' => MembershipReminders::PAUSE_DAYS])) ?>
    </div>
    <?= Html::beginForm(['send'], 'post') ?>
    <div class="table-responsive">
        <?= GridView::widget([
            'dataProvider' => $dataProvider,
            'tableOptions' => ['class' => 'table table-vcenter card-table'],
            'emptyText' => Yii::t('app', 'No members with an expiring or expired fee.'),
            'layout' => "{items}\n<div class=\"card-footer d-flex align-items-center\">{summary}<div class=\"ms-auto\">{pager}</div></div>",
            'columns' => [
                ['class' => CheckboxColumn::class, 'visible' => !$readOnly,
                    'checkboxOptions' => static fn($row) => (int) $row['reminders_opt_out'] === 1 ? ['disabled' => true] : []],
                [
                    'label' => Yii::t('app', 'Name'),
                    'format' => 'raw',
                    'value' => static fn($row) => Html::encode(trim($row['last_name'] . ' ' . $row['first_name']))
                        . ((int) $row['is_corporate'] === 1 && $row['denomination'] ? '<div class="text-secondary small">' . Html::encode($row['denomination']) . '</div>' : ''),
                ],
                ['label' => Yii::t('app', 'Email'), 'value' => static fn($row) => $row['email']],
                ['label' => Yii::t('app', 'Expiry'), 'value' => static fn($row) => $f->asDate($row['last_expiry'], 'php:d/m/Y')],
                ['label' => Yii::t('app', 'Status'), 'format' => 'raw', 'value' => static fn($row) => $row['status'] === 'expired'
                    ? Html::tag('span', Html::encode(Yii::t('app', 'Scaduto')), ['class' => 'badge bg-danger text-white'])
                    : Html::tag('span', Html::encode(Yii::t('app', 'In scadenza')), ['class' => 'badge bg-warning text-white'])],
                ['label' => Yii::t('app', 'Reminders'), 'format' => 'raw', 'value' => static fn($row) => (int) $row['reminders_opt_out'] === 1
                    ? Html::tag('span', Html::encode(Yii::t('app', 'Turned off')), ['class' => 'badge bg-secondary-lt'])
                    : Html::encode((string) $row['reminders'])],
                ['label' => Yii::t('app', 'Last sent'), 'value' => static fn($row) => $row['last_sent'] ? $f->asDatetime((int) $row['last_sent'], 'php:d/m/Y H:i') : '-'],
            ],
        ]) ?>
    </div>
    <?php if (!$readOnly && $dataProvider->getTotalCount() > 0): ?>
        <div class="card-body">
            <?= Html::submitButton('<i class="fas fa-envelope me-1" aria-hidden="true"></i>' . Html::encode(Yii::t('app', 'Send reminder to selected')), ['class' => 'btn btn-primary']) ?>
        </div>
    <?php endif; ?>
    <?= Html::endForm() ?>
</div>
