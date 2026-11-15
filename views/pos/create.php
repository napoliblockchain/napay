<?php

use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $model app\models\Pos */

$this->title = Yii::t('app', 'Nuovo Pos');
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Pos'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="container-fluid">
    <div class="row">
        <div class="col-lg-8">
            <div class="pos-create">
                <div class="card card-outline card-primary">
                    <div class="card-header">
                        <h3><?= Html::encode($this->title) ?></h3>
                    </div>
                    <div class="card-body">
                        <?= $this->render('_form-create', [
                            'model' => $model,
                            'merchants_list' => $merchants_list,
                        ]) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>