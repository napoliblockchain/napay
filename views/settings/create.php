<?php

use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $model app\models\Settings */

$this->title = Yii::t('app', 'Crea nuovo');
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Impostazioni'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
    <div class="row row-cards">
        <div class="col-lg-8">
            <div class="settings-update">
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