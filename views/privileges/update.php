<?php

use yii\helpers\Html;
use app\components\Crypt;

/* @var $this yii\web\View */
/* @var $model app\models\Servizi */

$this->title = Yii::t('app', 'Update Privilege: {name}', [
    'name' => $model->id,
]);
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Privileges'), 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $model->id, 'url' => ['view', 'id' => Crypt::encrypt($model->id)]];
$this->params['breadcrumbs'][] = Yii::t('app', 'Update');
?>
    <div class="row row-cards">
        <div class="col-lg-8">
            <div class="privilege-update">
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