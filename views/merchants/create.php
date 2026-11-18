<?php

use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $model app\models\Commercianti */

$this->title = Yii::t('app', 'Nuovo Esercente');
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Esercenti'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
    <div class="row row-cards">
        <div class="col-lg-8">
            <div class="commercianti-create">
                <?= app\widgets\Alert::widget() ?>
                <div class="card card-outline card-primary">
                    <div class="card-header">
                        <h3><?= Html::encode($this->title) ?></h3>
                    </div>
                    <div class="card-body">
                        <?= $this->render('_form', [
                            'model' => $model,
                            'users_list' => $users_list
                        ]) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>