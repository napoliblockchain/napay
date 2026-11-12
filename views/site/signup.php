<?php

/** @var yii\web\View $this */
/** @var yii\bootstrap5\ActiveForm $form */
/** @var app\models\forms\SignupForm $model */
/** @var app\models\UserConsensus $consensus */

use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;
use yii\helpers\Url;
use app\widgets\Alert;
use app\assets\SignupAsset;

$this->title = 'Registrazione';
// $this->params['breadcrumbs'][] = $this->title;

$memberTypes = ['pf' => Yii::t('app', 'Socio persona fisica'), 'pg' => Yii::t('app', 'Socio persona giuridica')];
SignupAsset::register($this);
?>

<div class="site-login mt-4">
    <div class="row">
        <div class="col-lg-12">
            <div class="mt-4">
                <div class="row align-items-center justify-content-center ">
                    <div class="col-lg-6 align-self-end">
                        <?= Alert::widget() ?>
                        <div class="card text-left">
                            <div class="card-header text-center">
                                <h4 class="login-box-msg"><?= Yii::t('app', 'Registrazione Account') ?></h4>
                            </div>
                            <div class="card-body login-card-body text-left" id="loginBody">

                                <?php $form = ActiveForm::begin(['id' => 'signup-form']) ?>

                                <?= $form->field($model, 'member_type')->dropDownList(
                                    $memberTypes,
                                    [
                                        'prompt' => Yii::t('app', 'Seleziona il tipo di iscrizione'),
                                        'id' => 'member_type',
                                    ]
                                )->label(false); ?>

                                <div id="signup-pg-fields">
                                    <?= $form->field($model, 'denomination')->textInput(['maxlength' => 250]) ?>
                                    <p class="form-hint"><?= Html::encode(Yii::t('app', 'La persona giuridica si iscrive come socio. Il profilo merchant si attiva separatamente dopo la registrazione.')) ?></p>
                                </div>

                                <?= $form->field($model, 'first_name', [
                                    'options' => ['class' => 'form-group has-feedback'],
                                    'inputTemplate' => '{input}<div class="input-group-append"><div class="input-group-text"><span class="fas fa-user"></span></div></div>',
                                    'template' => '{beginWrapper}{input}{error}{endWrapper}',
                                    'wrapperOptions' => ['class' => 'input-group mb-3'],
                                    'inputOptions' => ['autocomplete' => 'off'],
                                ])
                                    ->label(false)
                                    ->textInput(['placeholder' => $model->getAttributeLabel('first_name')]) ?>

                                <?= $form->field($model, 'last_name', [
                                    'options' => ['class' => 'form-group has-feedback'],
                                    'inputTemplate' => '{input}<div class="input-group-append"><div class="input-group-text"><span class="fas fa-user"></span></div></div>',
                                    'template' => '{beginWrapper}{input}{error}{endWrapper}',
                                    'wrapperOptions' => ['class' => 'input-group mb-3'],
                                    'inputOptions' => ['autocomplete' => 'off'],
                                ])
                                    ->label(false)
                                    ->textInput(['placeholder' => $model->getAttributeLabel('last_name')]) ?>

                                <?= $form->field($model, 'email', [
                                    'options' => ['class' => 'form-group has-feedback'],
                                    'inputTemplate' => '{input}<div class="input-group-append"><div class="input-group-text"><span class="fas fa-envelope"></span></div></div>',
                                    'template' => '{beginWrapper}{input}{error}{endWrapper}',
                                    'wrapperOptions' => ['class' => 'input-group mb-3'],
                                    'inputOptions' => ['autocomplete' => 'off'],
                                ])
                                    ->label(false)
                                    ->textInput(['placeholder' => $model->getAttributeLabel('email')]) ?>

                                <?= $form->field($model, 'password', [
                                    'options' => ['class' => 'form-group has-feedback'],
                                    'inputTemplate' => '{input}<div class="input-group-append"><div class="input-group-text"><span class="fas fa-lock"></span></div></div>',
                                    'template' => '{beginWrapper}{input}{error}{endWrapper}',
                                    'wrapperOptions' => ['class' => 'input-group mb-3']
                                ])
                                    ->label(false)
                                    ->passwordInput(['placeholder' => $model->getAttributeLabel('password')]) ?>

                                <?= $form->field($model, 'repeat_password', [
                                    'options' => ['class' => 'form-group has-feedback'],
                                    'inputTemplate' => '{input}<div class="input-group-append"><div class="input-group-text"><span class="fas fa-lock"></span></div></div>',
                                    'template' => '{beginWrapper}{input}{error}{endWrapper}',
                                    'wrapperOptions' => ['class' => 'input-group mb-3']
                                ])
                                    ->label(false)
                                    ->passwordInput(['placeholder' => $model->getAttributeLabel('repeat_password')]) ?>


                                <!-- STATUTO -->
                                <div class="form-group">
                                    <?= $form->field($consensus, 'consenso_statuto')->checkbox()->label('<i>Ho letto lo <a href="https://napoliblockchain.it/chi-siamo/statuto-associazione-napoli-blockchain-ets/" target="_blank">STATUTO</a> e ne condivido ogni punto.</i>') ?>
                                </div>

                                <!-- PRIVACY -->
                                <div class="form-group">
                                    <?= $form->field($consensus, 'consenso_privacy')->checkbox()->label('<i>&nbsp;Ho letto l\'<a href="' . Url::to(['site/print-privacy']) . '" target="_blank">INFORMATIVA SULLA PRIVACY</a> e autorizzo al trattamento dei miei dati personali.</i>') ?>
                                </div>

                                <!-- TERMINI -->
                                <div class="input-group">
                                    <?= $form->field($consensus, 'consenso_condizioni')->checkbox()->label('<i>&nbsp;Accetto le <a href="' . Url::to(['site/term-of-use']) . '" target="_blank">Condizioni di utilizzo</a> del software.</i>') ?>
                                </div>

                                <!-- TERMINI -->
                                <div class="input-group">
                                    <?= $form->field($consensus, 'consenso_marketing')->checkbox()->label('<i>&nbsp;Acconsento al trattamento dei dati per finalità di marketing.</i>') ?>
                                </div>

                                <div class="row">
                                    <div class="col-12">
                                        <?= Html::submitButton('<i class="fas fa-check"></i> ' . 'Conferma', [
                                            'class' => 'btn btn-primary w-100',
                                        ]) ?>
                                    </div>
                                </div>
                                <?php ActiveForm::end(); ?>
                            </div>
                            <!-- /.login-card-body -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
