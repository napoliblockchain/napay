<?php

use yii\helpers\Html;
use app\components\Crypt;

/* @var $this yii\web\View */
/* @var $roles app\models\AssociationRoles[] */
/* @var $fees app\models\MembershipFees[] */
/* @var $methods app\models\PaymentMethods[] */

$this->title = Yii::t('app', 'Tabelle associazione');
$this->params['breadcrumbs'][] = $this->title;
$f = Yii::$app->formatter;
$writable = empty($this->params['associationReadOnly']);
$actions = function (string $type, $model) use ($writable) {
    if (!$writable) return '';
    $id = Crypt::encrypt($model->id);
    return '<td class="text-end text-nowrap w-1">'
        . Html::a('<i class="fas fa-pen"></i>', ['table-edit', 'type' => $type, 'id' => $id], ['class' => 'btn btn-sm btn-outline-secondary', 'title' => Yii::t('app', 'Modifica')]) . ' '
        . $this->render('_delete', ['action' => ['table-delete', 'type' => $type, 'id' => $id], 'label' => '']) . '</td>';
};
$add = fn(string $type) => $writable ? '<div class="card-actions">' . Html::a('<i class="fas fa-plus"></i>', ['table-edit', 'type' => $type], ['class' => 'btn btn-sm btn-primary', 'title' => Yii::t('app', 'Nuova voce')]) . '</div>' : '';
?>
<?= $this->render('_readonly') ?>
<div class="row row-cards">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><?= Yii::t('app', 'Cariche') ?></h3><?= $add('role') ?></div>
            <table class="table card-table">
                <?php foreach ($roles as $r): ?><tr><td><?= Html::encode($r->description) ?></td><?= $actions('role', $r) ?></tr><?php endforeach; ?>
            </table>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><?= Yii::t('app', 'Quote') ?></h3><?= $add('fee') ?></div>
            <table class="table card-table">
                <?php foreach ($fees as $q): ?><tr><td><?= Html::encode($q->description) ?></td><td class="text-end"><?= $f->asCurrency($q->amount, 'EUR') ?></td><?= $actions('fee', $q) ?></tr><?php endforeach; ?>
            </table>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><?= Yii::t('app', 'Metodi di pagamento') ?></h3><?= $add('method') ?></div>
            <table class="table card-table">
                <?php foreach ($methods as $m): ?><tr><td><?= Html::encode($m->description) ?></td><td class="text-end text-secondary small"><?= (int) $m->min_level > 0 ? Yii::t('app', 'solo registrazione manuale') : Yii::t('app', 'anche online') ?></td><?= $actions('method', $m) ?></tr><?php endforeach; ?>
            </table>
        </div>
    </div>
</div>
