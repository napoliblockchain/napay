<?php use yii\helpers\Html; ?>
<footer class="footer footer-transparent d-print-none">
    <div class="container-fluid d-flex flex-wrap justify-content-between gap-2">
        <span>&copy; <?= date('Y') ?> <?= Html::a(Html::encode(Yii::$app->params['webapp_society']), Yii::$app->params['webapp_link']) ?></span>
        <span class="d-flex gap-3">
            <?php foreach (\app\models\LegalDocuments::TYPES as $legalType): // SIP-040 ?>
                <?= Html::a(Html::encode(\app\models\LegalDocuments::typeLabel($legalType)), ['/legal/view', 'type' => $legalType], ['class' => 'link-secondary']) ?>
            <?php endforeach; ?>
        </span>
        <span class="text-secondary">Version <?= Html::encode(Yii::$app->params['version']) ?></span>
    </div>
</footer>
