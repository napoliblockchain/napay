<?php use yii\helpers\Html; ?>
<footer class="footer footer-transparent d-print-none">
    <div class="container-fluid d-flex flex-wrap justify-content-between gap-2">
        <span>&copy; <?= date('Y') ?> <?= Html::a(Html::encode(Yii::$app->params['webapp_society']), Yii::$app->params['webapp_link']) ?></span>
        <span class="text-secondary">Version <?= Html::encode(Yii::$app->params['version']) ?></span>
    </div>
</footer>
