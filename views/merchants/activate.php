<?php
use yii\helpers\Html;
$this->title = Yii::t('app', 'Attiva merchant');
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="card">
    <div class="card-header"><h2 class="card-title"><?= Html::encode($this->title) ?></h2></div>
    <div class="card-body">
        <p class="text-secondary"><?= Html::encode(Yii::t('app', 'Attivi il merchant della tua persona giuridica. Questo passaggio non abilita ancora gli incassi: la configurazione dei pagamenti sarà separata. La partita IVA non sarà modificabile dopo il salvataggio.')) ?></p>
        <?= $this->render('_form', ['model'=>$model, 'users_list'=>$users_list]) ?>
    </div>
</div>
