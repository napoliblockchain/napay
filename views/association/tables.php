<?php

use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $roles app\models\AssociationRoles[] */
/* @var $fees app\models\MembershipFees[] */
/* @var $methods app\models\PaymentMethods[] */

$this->title = Yii::t('app', 'Tabelle associazione');
$this->params['breadcrumbs'][] = $this->title;
$f = Yii::$app->formatter;
?>
<?= $this->render('_readonly') ?>
<div class="row row-cards">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><?= Yii::t('app', 'Cariche') ?></h3></div>
            <table class="table card-table">
                <?php foreach ($roles as $r): ?><tr><td><?= Html::encode($r->description) ?></td></tr><?php endforeach; ?>
            </table>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><?= Yii::t('app', 'Quote') ?></h3></div>
            <table class="table card-table">
                <?php foreach ($fees as $q): ?><tr><td><?= Html::encode($q->description) ?></td><td class="text-end"><?= $f->asCurrency($q->amount, 'EUR') ?></td></tr><?php endforeach; ?>
            </table>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><?= Yii::t('app', 'Metodi di pagamento') ?></h3></div>
            <table class="table card-table">
                <?php foreach ($methods as $m): ?><tr><td><?= Html::encode($m->description) ?></td><td class="text-end text-secondary small"><?= (int) $m->min_level > 0 ? Yii::t('app', 'solo registrazione manuale') : Yii::t('app', 'anche online') ?></td></tr><?php endforeach; ?>
            </table>
        </div>
    </div>
</div>
