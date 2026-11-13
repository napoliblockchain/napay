<?php

use yii\helpers\Html;
use yii\widgets\DetailView;
use app\components\Crypt;

/* @var $this yii\web\View */
/* @var $model app\models\Privileges */

$this->title = Yii::t('app', 'Ruolo') . ': ' . $model->description;
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Privileges'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
\yii\web\YiiAsset::register($this);
?>
<div class="container-fluid">
    <div class="row">
        <div class="col-lg-8">
            <div class="privileges-view">
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
                            <h3 class="card-title mb-0"><?= Html::encode($this->title) ?></h3>

                            <div class="ms-auto">
                                <?= Html::a('<button type="button" class="btn btn-warning">
                                    <i class="fas fa-plus"></i> ' . Yii::t('app', 'Add Privilege') . '
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
                                'codice_ruolo',
                                'level'
                            ],
                        ]) ?>
                    </div>
                    <div class="card-footer">
                        <div class="d-flex flex-wrap align-items-center gap-2 w-100">
                            <div>
                                <?= Html::a('<i class="fas fa-pen"></i> ' . Yii::t('app', 'Update'), ['update', 'id' => Crypt::encrypt($model->id)], ['class' => 'btn btn-primary']) ?>
                            </div>
                            <div class="ms-auto">
                                <?= Html::a('<i class="fas fa-trash"></i> ' . Yii::t('app', 'Delete'), ['delete', 'id' => Crypt::encrypt($model->id)], [
                                    'class' => 'btn btn-danger',
                                    'data' => [
                                        'confirm' => Yii::t('app', 'Are you sure you want to delete this item?'),
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
</div>