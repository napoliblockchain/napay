<?php

use yii\helpers\Html;
use kartik\tabs\TabsX;
use app\components\User;

/* @var $this yii\web\View */
/* @var $model app\models\Stores */
/* @var $ttsWallet string|null indirizzo wallet TTS del negozio (SIP-030) */
/* @var $btcWallet bool wallet BTC presente (xpub o negozio v1) */

$this->title = Yii::t('app', 'Negozio') . ': ' . $model->description;
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Stores'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
\yii\web\YiiAsset::register($this);


?>
    <div class="row row-cards">
        <div class="col-lg-8">
            <div class="stores-view">
                <?= app\widgets\Alert::widget() ?>
                <?php
                // wallet per incassare: TTS (indirizzo del negozio) e BTC (xpub del merchant o negozio v1 gestito su BTCPay)
                $hasTts = !empty($ttsWallet);
                $hasBtc = !empty($btcWallet);
                $walletNotice = match (true) {
                    !$hasTts && !$hasBtc => Yii::t('app', 'This store cannot receive payments yet: set both the TTS wallet and the BTC wallet.'),
                    !$hasTts => Yii::t('app', 'TTS wallet missing: TTS payments are not active for this store.'),
                    !$hasBtc => Yii::t('app', 'BTC wallet missing: BTC payments are not active for this store.'),
                    default => null,
                };
                ?>
                <?php if ($walletNotice !== null) : ?>
                    <div class="alert alert-warning" role="status" data-wallet-notice="<?= $hasTts ? 'btc' : ($hasBtc ? 'tts' : 'both') ?>">
                        <?= Html::encode($walletNotice) ?>
                    </div>
                <?php endif; ?>
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
                                'label' => Yii::t('app', 'General'),
                                'encode' => false,
                                // 'active' => !$modelSegnalazione->getIsAnnullata(),
                                'options' => ['id' => 'tab-general'],
                                'content' => $this->render('_details-general', ['model' => $model, 'rates' => $rates]),
                                // 'visible' => !is_null($model),
                            ],
                            [
                                'label' => Yii::t('app', 'BTC wallet'),
                                'encode' => false,
                                'options' => ['id' => 'tab-wallet'],
                                'content' => $this->render('_details-wallet', ['model' => $model]),
                            ],
                            [
                                'label' => Yii::t('app', 'TTS wallet'),
                                'encode' => false,
                                'options' => ['id' => 'tab-tts-wallet'],
                                'content' => $this->render('_details-tts-wallet', ['model' => $model, 'ttsWallet' => $ttsWallet]),
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