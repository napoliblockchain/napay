<?php

use yii\helpers\Html;
use kartik\tabs\TabsX;
use app\components\User;

/* @var $this yii\web\View */
/* @var $model app\models\Stores */

$this->title = Yii::t('app', 'Negozio') . ': ' . $model->description;
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Stores'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
\yii\web\YiiAsset::register($this);


?>
<div class="container-fluid">
    <div class="row">
        <div class="col-lg-8">
            <div class="stores-view">
                <?= app\widgets\Alert::widget() ?>
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex flex-wrap align-items-center gap-2 w-100">
                            <h3 class="card-title mb-0"><?= Html::encode($this->title) ?></h3>
                            <?php if (User::isMerchant()) : ?>
                                <div class="ms-auto">
                                    <?= Html::a('<button type="button" class="btn btn-warning">
                                        <i class="fas fa-plus"></i> ' . Yii::t('app', 'Nuovo negozio') . '
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
                                'content' => $this->render('_details-general', ['model' => $model, 'rates' => $rates]),
                                // 'visible' => !is_null($model),
                            ],
                            [
                                'label' => Yii::t('app', 'Wallet'),
                                'encode' => false,
                                'options' => ['id' => 'tab-wallet'],
                                'content' => $this->render('_details-wallet', ['model' => $model]),
                                'visible' => User::isMerchant(),
                            ],
                            [
                                'label' => Yii::t('app', 'Tassi di cambio'),
                                'encode' => false,
                                'options' => ['id' => 'tab-rates'],
                                'content' => $this->render('_details-rates', ['model' => $model, 'rates' => $rates]),
                                'visible' => User::isMerchant(),
                            ],
                            [
                                'label' => Yii::t('app', 'Ricevute'),
                                'encode' => false,
                                // 'active' => $modelSegnalazione->getIsAnnullata(),
                                'options' => ['id' => 'tab-checkout'],
                                'content' => $this->render('_details-checkout', ['model' => $model]),
                                'visible' => User::isMerchant(),
                            ],
                            [
                                'label' => Yii::t('app', 'Criteri'),
                                'encode' => false,
                                // 'active' => $modelSegnalazione->getIsAnnullata(),
                                'options' => ['id' => 'tab-criteria'],
                                'content' => $this->render('_details-criteria', ['model' => $model]),
                                'visible' => User::isMerchant(),
                            ],
                            [
                                'label' => Yii::t('app', 'Webhook'),
                                'encode' => false,
                                'options' => ['id' => 'tab-webhook'],
                                'content' => $this->render('_details-webhook', ['model' => $model]),
                                'visible' => User::isMerchant(),
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
    </div>
</div>