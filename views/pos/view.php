<?php

use yii\helpers\Html;
use kartik\tabs\TabsX;
use app\components\User;

/* @var $this yii\web\View */
/* @var $model app\models\Pos */

$this->title = Yii::t('app', 'Terminale POS');
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Pos'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
\yii\web\YiiAsset::register($this);
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-lg-8">
            <div class="pos-view">
                <?php if (Yii::$app->session->hasFlash('success')) : ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo Yii::$app->session->getFlash('success') ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                <?php if (Yii::$app->session->hasFlash('error')) : ?>
                    <div class="alert alert-warning alert-dismissible fade show" role="alert">
                        <?php echo Yii::$app->session->getFlash('error') ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex flex-wrap align-items-center gap-2 w-100">
                            <h5><?= Html::encode($this->title) ?></h5>
                            <?php if (User::can(40)) : ?>
                                <div class="ms-auto">
                                    <?= Html::a('<button type="button" class="btn btn-warning">
                                        <i class="fas fa-plus"></i> ' . Yii::t('app', 'Nuovo Pos') . '
                                        </button>', ['create']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php
                        $items = [
                            [
                                'label' => Yii::t('app', 'Generale'),
                                'encode' => false,
                                // 'active' => !$modelSegnalazione->getIsAnnullata(),
                                'options' => ['id' => 'tab-general'],
                                'content' => $this->render('_details-general', ['model' => $model]),
                                // 'visible' => !is_null($model),
                            ],

                            // [
                            //     'label' => Yii::t('app', 'Settings'),
                            //     'encode' => false,
                            //     // 'active' => $modelSegnalazione->getIsAnnullata(),
                            //     'options' => ['id' => 'tab-settings'],
                            //     'content' => $this->render('_details-settings', ['model' => $model]),
                            //     // 'visible' => $model->documenti->count,
                            // ],


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
    </div>
</div>