<?php

use yii\helpers\Url;
use app\components\User;
use kartik\icons\Icon;
use app\components\identity\AccountEligibility;
use app\components\pos\management\Scope;
use app\components\pos\tts\PaymentTotals;
use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $searchModel app\models\search\UsersSearch */
/* @var $dataProvider yii\data\ActiveDataProvider */
/* @var $payments array|null riepilogo incassi (PaymentTotals::dashboard), null fuori dallo scope esercenti */

Icon::map($this);

$this->title = Yii::t('app', 'Dashboard');
$this->params['breadcrumbs'][] = $this->title;
$commerce = Scope::current() !== null;
$member = AccountEligibility::isMemberAccount((int)Yii::$app->user->id);
?>

<div class="body-content">
    <?php if ($payments !== null): ?>
    <div class="row g-3 mt-2">
        <?php foreach ([
            ['today', Yii::t('app', 'Collected today'), PaymentTotals::format($payments['today']), 'bg-success'],
            ['month', Yii::t('app', 'Collected this month'), PaymentTotals::format($payments['month']), 'bg-success'],
            ['pending', Yii::t('app', 'Pending payments'), (string) $payments['pending'], 'bg-blue'],
        ] as [$key, $label, $value, $color]): ?>
            <div class="col-sm-6 col-lg-3">
                <a href="<?= Url::to(['pos-payments/index']) ?>" class="card card-sm h-100 p-3 text-reset text-decoration-none" data-dashboard="<?= $key ?>">
                    <span class="text-secondary"><span class="badge <?= $color ?> me-2"></span><?= Html::encode($label) ?></span>
                    <span class="h2 mb-0 mt-1"><?= Html::encode($value) ?></span>
                </a>
            </div>
        <?php endforeach; ?>
        <?php if ($payments['wallet'] !== null): ?>
            <!-- saldo reale sulla chain: comprende anche i trasferimenti fuori dal POS, quindi è separato dagli incassi -->
            <div class="col-sm-6 col-lg-3">
                <a href="<?= Url::to(['merchant-wallet/index']) ?>" class="card card-sm h-100 p-3 text-reset text-decoration-none border-primary" data-dashboard="balance">
                    <span class="text-secondary"><span class="badge bg-primary me-2"></span><?= Html::encode(Yii::t('app', 'TTS wallet balance')) ?></span>
                    <span class="h2 mb-0 mt-1"><?= Html::encode($payments['balance'] === null ? Yii::t('app', 'Not available') : PaymentTotals::format($payments['balance'])) ?></span>
                    <span class="small text-secondary text-truncate font-monospace" title="<?= Html::encode($payments['wallet']) ?>"><?= Html::encode(substr($payments['wallet'], 0, 10) . '…' . substr($payments['wallet'], -8)) ?></span>
                </a>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="row mt-2">
        <div class="col-lg-12 col-12">
            <div class="row g-3">
                <?php if (User::isAdministrator()) : ?>
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
                                        <?= Yii::t('app', 'Aziende') ?>
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
                <?php if ($commerce) : ?>
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
                                        <?= Yii::t('app', 'Negozi') ?>
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
                <?php if ($commerce): ?>
                <div class="col-lg-3 col-md-12">
                    <a href="<?= Url::to(['pos-management/index']) ?>" class="d-block h-100 text-reset text-decoration-none">
                        <div class="card card-sm h-100 flex-row align-items-center p-3 gap-3">
                            <span class="avatar bg-primary text-white">
                                <div class="icon">
                                    <?= Icon::show('mobile-alt') ?>
                                </div>
                            </span>

                            <div class="flex-fill">
                                <span class="text-secondary">
                                    <?= Yii::t('app', 'Terminali POS') ?>
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
                <?php if ($member): ?>
                    <div class="col-lg-3 col-md-12">
                        <a class="card card-sm h-100 p-3 text-reset text-decoration-none" href="<?= Url::to(['pay-fee/index']) ?>"><?= Yii::t('app', 'Paga quota') ?></a>
                    </div>
                <?php elseif (!$commerce && AccountEligibility::isOperatorAccount((int)Yii::$app->user->id)): ?>
                    <div class="col-12"><div class="alert alert-info"><?= Yii::t('app', 'Account operatore POS. Accedi al POS con le tue credenziali per scegliere il terminale assegnato.') ?></div></div>
                <?php endif; ?>
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
