<?php

use yii\helpers\Html;
use app\components\Crypt;

/* @var $this yii\web\View */
/* @var $model app\models\Stores */

$this->title = $title;
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Stores'), 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $model->description, 'url' => ['view', 'id' => Crypt::encrypt($model->id)]];
$this->params['breadcrumbs'][] = Yii::t('app', 'Edit');
?>
    <div class="row row-cards">
        <div class="col-lg-8">
            <div class="stores-update">
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