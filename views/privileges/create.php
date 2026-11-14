<?php

use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $model app\models\Servizi */

$this->title = Yii::t('app', 'Create Role');
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Roles'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
    <div class="row row-cards">
        <div class="col-lg-8">
            <div class="privilege-create">
                <div class="card card-outline card-primary">
                    <div class="card-header">
                        <h3><?= Html::encode($this->title) ?></h3>
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