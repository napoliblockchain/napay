<?php

use yii\helpers\Html;
use app\models\LegalDocuments;

/* @var $this yii\web\View */
/* @var $type string */

$this->title = LegalDocuments::typeLabel($type);
?>
<article class="legal-document">
    <h1><?= Html::encode($this->title) ?></h1>
    <p><?= Html::encode(Yii::t('app', 'This document is being prepared by the association and will be published here.')) ?></p>
</article>
