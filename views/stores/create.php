<?php

use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $model app\models\Stores */

$this->title = Yii::t('app', 'Nuovo negozio');
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Negozi'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
    <div class="row row-cards">
        <div class="col-lg-8">
            <div class="stores-create">
                <?= app\widgets\Alert::widget() ?>
                <div class="card card-outline card-primary">
                    <div class="card-header">
                        <h3><?= Html::encode($this->title) ?></h3>
                    </div>
                    <div class="card-body">
                        <?= $this->render('_form-create', [
                            'model' => $model,
                            'merchants_list' => $merchants_list
                        ]) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>