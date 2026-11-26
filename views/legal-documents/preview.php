<?php

use yii\helpers\Html;
use app\models\LegalDocuments;

/* @var $this yii\web\View */
/* @var $document LegalDocuments */

$this->title = Yii::t('app', '{type}: version {n}', ['type' => LegalDocuments::typeLabel($document->type), 'n' => (int) $document->version]);
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Legal documents'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><?= Html::encode($document->title) ?></h3>
        <div class="card-actions">
            <?= $document->status === LegalDocuments::DRAFT
                ? '<span class="badge bg-warning-lt">' . Html::encode(Yii::t('app', 'Draft: not public')) . '</span>'
                : Html::encode(Yii::t('app', 'Published on {date}', ['date' => Yii::$app->formatter->asDate((int) $document->published_at, 'php:d/m/Y')])) ?>
        </div>
    </div>
    <div class="card-body legal-document"><?= LegalDocuments::render($document->body) ?></div>
</div>
