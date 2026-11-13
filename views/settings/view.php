<?php

use yii\helpers\Html;
use yii\widgets\DetailView;
use app\components\Crypt;


/* @var $this yii\web\View */
/* @var $model app\models\Settings */

$this->title = sprintf(Yii::t('app', 'Dettagli: ') . '%s', $model->code);
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Impostazioni'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
\yii\web\YiiAsset::register($this);
?>
    <div class="row row-cards">
        <div class="col-lg-8">
            <div class="settings-view">
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
                <div class="card card-outline card-primary">
                    <div class="card-header">
                        <div class="d-flex flex-row">
                            <h3><?= Html::encode($this->title) ?></h3>
                            <div class="ml-auto">
                                <?= Html::a('<button type="button" class="btn btn-warning">
                                    <i class="fas fa-plus"></i> ' . Yii::t('app', 'Aggiungi') . '
                                    </button>', ['create']) ?>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <?= DetailView::widget([
                            'model' => $model,
                            'attributes' => [
                                // 'id',
                                'description',
                                'code',
                                [
                                    'attribute' => 'value',
                                    'format' => 'raw',
                                    'value' => function ($data) {
                                        return Html::encode($data->value);
                                    },
                                    'contentOptions' => ['class' => 'text-break'],
                                ],

                            ],
                        ]) ?>
                    </div>
                    <div class="card-footer">
                        <div class="d-flex flex-row">
                            <div>
                                <?= Html::a('<i class="fas fa-pen"></i> ' . Yii::t('app', 'Modifica'), ['update', 'id' => Crypt::encrypt($model->id)], ['class' => 'btn btn-primary']) ?>
                            </div>
                            <div class="ml-auto">
                                <?= Html::a('<i class="fas fa-trash"></i> ' . Yii::t('app', 'Elimina'), ['delete', 'id' => Crypt::encrypt($model->id)], [
                                    'class' => 'btn btn-danger',
                                    'data' => [
                                        'confirm' => Yii::t('app', 'Vuoi eliminare questo elemento?'),
                                        'method' => 'post',
                                    ],
                                ]) ?>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>