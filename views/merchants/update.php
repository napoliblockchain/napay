<?php

use yii\helpers\Html;
use app\components\Crypt;
use app\components\User;

/* @var $this yii\web\View */
/* @var $model app\models\Commercianti */

$this->title = Yii::t('app', 'Azienda: {name}', ['name' => $model->description]);
if (User::isAdministrator()) $this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Aziende'), 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $model->description, 'url' => ['view', 'id' => Crypt::encrypt($model->id)]];
$this->params['breadcrumbs'][] = Yii::t('app', 'Modifica');
?>
    <div class="row row-cards">
        <div class="col-lg-8">
            <div class="commercianti-update">
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
