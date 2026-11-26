<?php

use yii\helpers\Html;
use app\assets\MailOutboxAsset;

/* @var $this yii\web\View */
/* @var $lists array<string, list<array{time: int, to: string, subject: string, links: list<string>, readable: bool}>> */

$this->title = Yii::t('app', 'Outgoing mail');
$this->params['breadcrumbs'][] = $this->title;
MailOutboxAsset::register($this);
$titles = ['napay' => Yii::t('app', 'Napay emails'), 'wallet' => Yii::t('app', 'Wallet emails')];
?>
<div class="alert alert-info">
    <?= Html::encode(Yii::t('app', 'Outgoing mail is not available: emails are saved here instead of being sent. Copy the link and send it to the recipient in another way. The last {n} emails are listed.', ['n' => app\components\mail\Outbox::LIMIT])) ?>
</div>
<?php if ($lists === []): ?>
    <div class="card"><div class="card-body text-secondary"><?= Html::encode(Yii::t('app', 'Emails are sent normally: there is no saved mail to show.')) ?></div></div>
<?php endif; ?>
<?php foreach ($lists as $source => $messages): ?>
<div class="card mb-3">
    <div class="card-header"><h3 class="card-title"><?= Html::encode($titles[$source] ?? $source) ?></h3></div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead>
            <tr>
                <th class="w-1"><?= Html::encode(Yii::t('app', 'Date')) ?></th>
                <th><?= Html::encode(Yii::t('app', 'Recipient')) ?></th>
                <th><?= Html::encode(Yii::t('app', 'Subject')) ?></th>
                <th><?= Html::encode(Yii::t('app', 'Links')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($messages === []): ?>
                <tr><td colspan="4" class="text-secondary"><?= Html::encode(Yii::t('app', 'No emails.')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($messages as $message): ?>
                <tr>
                    <td class="text-nowrap"><?= Html::encode(Yii::$app->formatter->asDatetime($message['time'], 'php:d/m/Y H:i')) ?></td>
                    <?php if (!$message['readable']): ?>
                        <td colspan="3" class="text-secondary"><?= Html::encode(Yii::t('app', 'Email not readable.')) ?></td>
                    <?php else: ?>
                        <td><?= Html::encode($message['to']) ?></td>
                        <td><?= Html::encode($message['subject']) ?></td>
                        <td class="w-50">
                            <?php if ($message['links'] === []): ?>
                                <span class="text-secondary"><?= Html::encode(Yii::t('app', 'No links.')) ?></span>
                            <?php endif; ?>
                            <?php foreach ($message['links'] as $link): ?>
                                <div class="input-group input-group-sm mb-1">
                                    <input type="text" class="form-control" readonly value="<?= Html::encode($link) ?>" aria-label="<?= Html::encode(Yii::t('app', 'Links')) ?>">
                                    <button type="button" class="btn btn-outline-primary" data-napay-copy><?= Html::encode(Yii::t('app', 'Copy')) ?></button>
                                </div>
                            <?php endforeach; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>
