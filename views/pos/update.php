<?php

use yii\helpers\Html;
use app\components\Crypt;

/* @var $this yii\web\View */
/* @var $model app\models\Pos */

$this->title = Yii::t('app', 'Modifica Pos: {name}', [
    'name' => $model->sin,
]);
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Pos'), 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => substr($model->sin, 0, 15) . '…', 'url' => ['view', 'id' => Crypt::encrypt($model->id)]];
$this->params['breadcrumbs'][] = Yii::t('app', 'Modifica');
?>
<div class="container-fluid">
    <div class="row">
        <div class="col-lg-8">
            <div class="pos-update">
                <div class="card card-outline card-primary">
                    <div class="card-header">
                        <h5><?= Html::encode($this->title) ?></h5>
                    </div>
                    <div class="card-body">
                        <?= $this->render('_form-update', [
                            'model' => $model,
                            'merchants_list' => $merchants_list,
                            'stores_list' => $stores_list,
                        ]) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>