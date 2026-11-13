<?php

use yii\helpers\Html;
use app\components\Crypt;

/* @var $this yii\web\View */
/* @var $model app\models\Stores */

$this->title = $title;
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Negozi'), 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => substr($model->storesettings->bps_storeid, 0, 15) . '…', 'url' => ['view', 'id' => Crypt::encrypt($model->id)]];
$this->params['breadcrumbs'][] = Yii::t('app', 'Modifica');
?>
    <div class="row row-cards">
        <div class="col-lg-8">
            <div class="stores-update">
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
                        <?= $this->render($_form, [
                            'model' => $model,
                            'merchants_list' => $merchants_list,
                        ]) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>