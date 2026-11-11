<?php

use yii\helpers\Html;
use app\components\Crypt;
use app\components\User;

/* @var $this yii\web\View */
/* @var $model app\models\Commercianti */

$ownCompany = $ownCompany ?? false;
$this->title = $ownCompany ? Yii::t('app', 'Azienda') : Yii::t('app', 'Azienda: {name}', [
    'name' => $model->description,
]);
if (User::isAdministrator()) {
    $this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Aziende'), 'url' => ['index']];
}
if (!$ownCompany) {
    $this->params['breadcrumbs'][] = ['label' => $model->description, 'url' => ['view', 'id' => Crypt::encrypt($model->id)]];
    $this->params['breadcrumbs'][] = Yii::t('app', 'Modifica');
} else {
    $this->params['breadcrumbs'][] = $this->title;
}
?>
<div class="container-fluid">
    <div class="row">
        <div class="col-lg-8">
            <div class="commercianti-update">
                <?php if (Yii::$app->session->hasFlash('error')) : ?>
                    <div class="alert alert-warning alert-dismissible fade show" role="alert">
                        <?php echo Yii::$app->session->getFlash('error') ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                <div class="card card-outline card-primary">
                    <div class="card-header">
                        <h5><?= Html::encode($this->title) ?></h5>
                    </div>
                    <div class="card-body">
                        <?= $this->render('_form', [
                            'model' => $model,
                        ]) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
