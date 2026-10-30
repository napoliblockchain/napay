<?php

/** @var yii\web\View $this */
/** @var yii\bootstrap5\ActiveForm $form */

/** @var app\models\LoginForm $model */

use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;
use app\widgets\Alert;
use yii\helpers\Url;

$this->title = 'Login';
// $this->params['breadcrumbs'][] = $this->title;
?>
<div class="site-login mt-4">
    <div class="row">
        <div class="col-lg-12">

            <div class="container mt-4">
                <div class="row align-items-center justify-content-center ">
                    <div class="col-lg-6 col-md-12 align-self-end">
                        <?= Alert::widget() ?>

                        <div class="card text-left">
                            <div class="card-header text-center">
                                <h4><?= Yii::t('app', 'Accedi per iniziare la sessione') ?></h4>
                            </div>
                            <div class="card-body login-card-body text-left" id="loginBody">

                                <?php $form = ActiveForm::begin(['id' => 'login-form']) ?>

                                <?= $form->field($model, 'username', [
                                    'options' => ['class' => 'form-group has-feedback shadow p-2'],
                                    'inputTemplate' => '{input}<div class="input-group-append"><div class="input-group-text"><span class="fas fa-user"></span></div></div>',
                                    'template' => '{label}{beginWrapper}{input}{error}{endWrapper}',
                                    'wrapperOptions' => ['class' => 'input-group input-group-sm mb-1'],
                                    'inputOptions' => ['autocomplete' => 'off'],
                                ])
                                    ->label($model->getAttributeLabel('username'))
                                    ->textInput(['placeholder' => 'Inserisci il tuo nome utente']) ?>

                                <?= $form->field($model, 'password', [
                                    'options' => ['class' => 'form-group has-feedback shadow p-2'],
                                    'inputTemplate' => '{input}<div class="input-group-append"><div class="input-group-text"><span class="fas fa-lock"></span></div></div>',
                                    'template' => '{label}{beginWrapper}{input}{error}{endWrapper}',
                                    'wrapperOptions' => ['class' => 'input-group input-group-sm mb-1'],
                                    'inputOptions' => ['autocomplete' => 'off'],
                                ])
                                    ->label($model->getAttributeLabel('password'))
                                    ->passwordInput(['placeholder' => 'Inserisci la password']) ?>

                                <div class="row">
                                    <div class="col-12">
                                        <?= Html::submitButton('Accedi', [
                                            'class' => 'btn btn-primary w-100',
                                        ]) ?>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-6">
                                        <p class="my-2">
                                            <?= Html::a(Yii::t('app', 'I forgot my password'), Url::to(['site/forgot-password']), [
                                                'class' => 'small text-danger',
                                            ],) ?>
                                        </p>
                                    </div>
                                    <div class="col-6">
                                        <p class="my-2">
                                            <?= Html::a(Yii::t('app', 'Request activation link'), ['site/resend-activation'], ['class' => 'small text-info']) ?>
                                        </p>
                                        <p class="my-2 text-right">
                                            <?= Html::a(Yii::t('app', 'Register new account'), Url::to(['site/signup']), [
                                                'class' => 'small text-info',
                                            ],) ?>
                                        </p>
                                        <p class="my-2 text-right">
                                            <?= Html::a(
                                                '<i class="fas fa-mobile-alt"></i> ' . Yii::t('app', 'Install to home screen'),
                                                '#',
                                                [
                                                    'class' => 'small text-gray',
                                                    'onclick' => 'javascript:saveOnDesktop();'
                                                ],
                                            ) ?>
                                        </p>
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
