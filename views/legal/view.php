<?php

use yii\helpers\Html;
use app\models\LegalDocuments;

/* @var $this yii\web\View */
/* @var $document LegalDocuments versione pubblicata */
/* @var $isCurrent bool */
/* @var $current LegalDocuments|null */

$this->title = $document->title;
?>
<article class="legal-document" data-legal-document="<?= Html::encode($document->type) ?>">
    <h1><?= Html::encode($document->title) ?></h1>
    <p class="text-secondary small">
        <?= Html::encode(Yii::t('app', 'Version {n}, published on {date}', ['n' => (int) $document->version, 'date' => Yii::$app->formatter->asDate((int) $document->published_at, 'php:d/m/Y')])) ?>
    </p>
    <?php if (!$isCurrent && $current !== null): ?>
        <div class="alert alert-warning">
            <?= Html::encode(Yii::t('app', 'This is an earlier version.')) ?>
            <?= Html::a(Html::encode(Yii::t('app', 'Read the version in force')), ['legal/view', 'type' => $document->type]) ?>
        </div>
    <?php endif; ?>
    <?= LegalDocuments::render($document->body) ?>
</article>
