<?php

use yii\helpers\Url;
use app\components\User;
use kartik\icons\Icon;

/* @var $this yii\web\View */
/* @var $searchModel app\models\search\UsersSearch */
/* @var $dataProvider yii\data\ActiveDataProvider */

Icon::map($this);

$this->title = Yii::t('app', 'Dashboard');
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="body-content">
    <div class="row mt-2">
        <div class="col-lg-12 col-12">
            <div class="row g-3">
                <?php if (User::can(40)) : ?>
                    <div class="col-lg-3 col-md-12">
                        <a href="<?= Url::to(['merchants/index']) ?>" class="d-block h-100 text-reset text-decoration-none">
                            <div class="card card-sm h-100 flex-row align-items-center p-3 gap-3">
                                <span class="avatar bg-info text-white">
                                    <div class="icon">
                                        <?= Icon::show('store') ?>
                                    </div>
                                </span>

                                <div class="flex-fill">
                                    <span class="text-secondary">
                                        <?= Yii::t('app', 'Totale Esercenti') ?>
                                    </span>
                                    <span class="d-block fw-semibold">
                                        <!-- <small>%</small> -->
                                    </span>
                                </div>
                                <!-- /.info-box-content -->
                            </div>
                            <!-- /.info-box -->
                        </a>
                    </div>
                <?php endif; ?>
                <?php if (User::can(30)) : ?>
                    <div class="col-lg-3 col-md-12">
                        <a href="<?= Url::to(['stores/index']) ?>" class="d-block h-100 text-reset text-decoration-none">
                            <div class="card card-sm h-100 flex-row align-items-center p-3 gap-3">
                                <span class="avatar bg-success text-white">
                                    <div class="icon">
                                        <?= Icon::show('shopping-cart') ?>
                                    </div>
                                </span>

                                <div class="flex-fill">
                                    <span class="text-secondary">
                                        <?= Yii::t('app', 'Totale Negozi') ?>
                                    </span>
                                    <span class="d-block fw-semibold">
                                        <!-- <small>%</small> -->
                                    </span>
                                </div>
                                <!-- /.info-box-content -->
                            </div>
                            <!-- /.info-box -->
                        </a>
                    </div>
                <?php endif; ?>
                <div class="col-lg-3 col-md-12">
                    <a href="<?= Url::to(['pos/index']) ?>" class="d-block h-100 text-reset text-decoration-none">
                        <div class="card card-sm h-100 flex-row align-items-center p-3 gap-3">
                            <span class="avatar bg-primary text-white">
                                <div class="icon">
                                    <?= Icon::show('mobile-alt') ?>
                                </div>
                            </span>

                            <div class="flex-fill">
                                <span class="text-secondary">
                                    <?= Yii::t('app', 'Totale Pos') ?>
                                </span>
                                <span class="d-block fw-semibold">
                                    <!-- <small>%</small> -->
                                </span>
                            </div>
                            <!-- /.info-box-content -->
                        </div>
                        <!-- /.info-box -->
                    </a>
                </div>

                <div class="col-lg-3 col-md-12">
                    <a href="<?= Url::to(['invoices/index']) ?>" class="d-block h-100 text-reset text-decoration-none">
                        <div class="card card-sm h-100 flex-row align-items-center p-3 gap-3">
                            <span class="avatar bg-warning text-white">
                                <div class="icon">
                                    <?= Icon::show('file-invoice') ?>
                                </div>
                            </span>

                            <div class="flex-fill">
                                <span class="text-secondary">
                                    <?= Yii::t('app', 'Totale Transazioni') ?>
                                </span>
                                <span class="d-block fw-semibold">
                                    <!-- <small>%</small> -->
                                </span>
                            </div>
                            <!-- /.info-box-content -->
                        </div>
                        <!-- /.info-box -->
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-2">
        <div class="col-lg-12 col-12">
            <div class="container-fluid">
                <div class="row">
                    <div class="<?php echo (User::can(40)) ? 'col-lg-6' : 'col-md-12'; ?>">
                        <?php //echo $this->render('dashboard/invoices', ['dataInvoices' => $dataInvoices]); ?>
                    </div>
                    <?php if (User::can(40)) : ?>
                        <div class="col-lg-6 col-md-12 ">
                            <?php //echo $this->render('dashboard/merchants', ['dataMerchants' => $dataMerchants]); ?>
                        </div>
                    <?php endif; ?>
                    <?php if (User::can(30)) : ?>
                        <div class="col-lg-6 col-md-12 ">
                            <?php //echo $this->render('dashboard/stores', ['dataStores' => $dataStores]); ?>
                        </div>
                    <?php endif; ?>
                    <div class="col-lg-6 col-md-12 ">
                        <?php //echo $this->render('dashboard/pos', ['dataPos' => $dataPos]); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>