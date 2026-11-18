<?php

use yii\helpers\Html;
use app\components\user;

$this->title = Yii::t('app', 'Pannello Admin');
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="body-content">
    <div class="row mt-2">
        <div class="col-lg-12 col-12">
            <div class="card card-outline card-primary">
                <div class="card-header">
                    <div class="d-flex flex-row">
                        <h3><?= Html::encode($this->title) ?></h3>
                    </div>
                </div>

                <div class="card-body">
                    <div class="container-fluid">
                        <div class="row">
                            <?php if (User::isAdministrator()) : ?>
                                <div class="col-lg-6">
                                    <?= $this->render('_users', ['dataUsers' => $dataUsers]) ?>
                                </div>

                                <div class="col-lg-6">
                                    <?= $this->render('_privileges', ['dataPrivilegi' => $dataPrivilegi]) ?>
                                </div>
                                <div class="col-lg-6">
                                    <?= $this->render('_settings', ['dataSettings' => $dataSettings]) ?>
                                </div>

                                <div class="col-lg-6">
                                    <?= $this->render('_logs', ['dataLogs' => $dataLogs]) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>