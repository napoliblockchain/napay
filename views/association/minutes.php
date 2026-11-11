<?php

use yii\helpers\Html;
use app\components\Crypt;

/* @var $this yii\web\View */
/* @var $minutes app\models\AssemblyMinutes[] */

$this->title = Yii::t('app', 'Verbali');
$this->params['breadcrumbs'][] = $this->title;
?>
<?= $this->render('_readonly') ?>
<div class="card">
    <div class="card-header"><h3 class="card-title"><?= Html::encode($this->title) ?></h3></div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead><tr><th><?= Yii::t('app', 'Data') ?></th><th><?= Yii::t('app', 'Descrizione') ?></th><th class="w-1"></th></tr></thead>
            <tbody>
            <?php foreach ($minutes as $m): ?>
                <tr>
                    <td><?= Yii::$app->formatter->asDate($m->meeting_date, 'php:d/m/Y') ?></td>
                    <td><?= Html::encode($m->description) ?></td>
                    <td class="text-nowrap">
                        <?= $m->file_sha256
                            ? Html::a('<i class="fas fa-file-pdf me-1"></i>PDF', ['minute-file', 'id' => Crypt::encrypt($m->id)], ['class' => 'btn btn-sm btn-outline-primary'])
                            : Html::tag('span', Html::encode(Yii::t('app', 'PDF non disponibile')), ['class' => 'text-secondary small']) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$minutes): ?><tr><td colspan="3" class="text-secondary"><?= Yii::t('app', 'Nessun verbale.') ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
