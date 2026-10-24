<?php

use yii\helpers\Html;
use kartik\tabs\TabsX;
use app\assets\SidebarCollapseAsset;
use app\components\User;

/* @var $this yii\web\View */
/* @var $model app\models\Users */

$this->title = Yii::t('app', 'User: ') . $model->username;
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Utenti'), 'url' => User::isAdministrator() ? ['index'] : ''];

\yii\web\YiiAsset::register($this);
SidebarCollapseAsset::register($this);

// echo "<pre>" . print_r($model->negozio, true) . "</pre>";exit;
?>
<div class="row">
    <div class="col-xl-4">
        <div class="card">
            <div class="card-body profile-card pt-4 d-flex flex-column align-items-center">

                <?= Html::img($model->picture, [
                    'alt' => 'Profile',
                    'class' => "profile-user-img img-fluid img-circle",
                ]) ?>
                <h3 class="profile-username text-center"><?= Html::encode($model->first_name . ' ' . $model->last_name) ?></h3>
                <p class="text-muted text-center mb-0"><?= Html::encode('Profilo: ' . $model->privilege->description) ?></p>
                <p class="text-muted text-center mt-0"><?= Html::encode('Email: ' . $model->email) ?></p>
            </div>
        </div>
    </div>
    <div class="col-xl-8">
        <div class="card">
            <div class="card-body pt-3">
                <?php
                $items = [
                    [
                        'label' => 'Generale',
                        'encode' => false,
                        // 'active' => !$modelSegnalazione->getIsAnnullata(),
                        'options' => ['id' => 'tab-overview'],
                        'content' => $this->render('_details-overview', ['model' => $model]),
                        // 'visible' => !is_null($model),
                    ],
                    [
                        'label' => Yii::t('app', 'Impostazioni'),
                        'encode' => false,
                        // 'active' => $modelSegnalazione->getIsAnnullata(),
                        'options' => ['id' => 'tab-settings'],
                        'content' => $this->render('_details-settings', ['model' => $model]),
                        'visible' => $model->id == Yii::$app->user->id,
                    ],

                    [
                        'label' => Yii::t('app', 'Modifica Profilo'),
                        'encode' => false,
                        'options' => ['id' => 'tab-edit'],
                        'content' => $this->render('_details-edit', ['model' => $model, 'merchants_list'=>$merchants_list]),
                        'visible' => $model->id != Yii::$app->user->id,
                    ],

                ];
                echo TabsX::widget([
                    'items' => $items,
                    'bordered' => true,
                    'position' => TabsX::POS_ABOVE,
                    'encodeLabels' => false,
                    'enableStickyTabs' => true,
                ]);

                ?>
            </div>
        </div>

    </div>
</div>
