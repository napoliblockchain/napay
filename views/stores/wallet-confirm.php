<?php

/* SIP-022 xpub, passo 2 di 2: primi indirizzi di ricezione da confrontare con il wallet del merchant prima di confermare. */

use app\components\Crypt;
use yii\helpers\Html;

/* @var $this yii\web\View */
/* @var $model app\models\Stores */
/* @var $scheme string */
/* @var $addresses string[] */

$this->title = Yii::t('app', 'Confirm BTC wallet');
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Stores'), 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $model->description, 'url' => ['view', 'id' => Crypt::encrypt($model->id)]];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="row row-cards">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><?= Html::encode($this->title) ?></h3></div>
            <div class="card-body">
                <p><?= Html::encode(Yii::t('app', 'These are the first receive addresses BTCPay will use. Check that they are the same as the first receive addresses shown by your wallet.')) ?></p>
                <ol start="0" class="font-monospace" data-xpub-addresses>
                    <?php foreach ($addresses as $address): ?>
                        <li><?= Html::encode($address) ?></li>
                    <?php endforeach; ?>
                </ol>
                <p class="text-secondary"><?= Html::encode(Yii::t('app', 'If they differ, do not confirm: the payments would go to addresses you do not control.')) ?></p>
                <div class="d-flex gap-2">
                    <?= Html::beginForm(['wallet-set', 'id' => Crypt::encrypt($model->id)], 'post') ?>
                    <?= Html::hiddenInput('xpub', $scheme) ?>
                    <?= Html::submitButton(Html::encode(Yii::t('app', 'Confirm wallet')), ['class' => 'btn btn-primary']) ?>
                    <?= Html::endForm() ?>
                    <?= Html::a(Html::encode(Yii::t('app', 'Cancel')), ['view', 'id' => Crypt::encrypt($model->id)], ['class' => 'btn btn-outline-secondary']) ?>
                </div>
            </div>
        </div>
    </div>
</div>
