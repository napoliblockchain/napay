<?php

use yii\bootstrap5\Breadcrumbs;
use yii\helpers\Url;
?>
<div class="page-header d-print-none">
    <div class="container-fluid">
        <?= Breadcrumbs::widget([
            'homeLink' => ['label' => 'Home', 'url' => Url::to(['site/index'], true)],
            'links' => $this->params['breadcrumbs'] ?? [],
            'options' => ['class' => 'breadcrumb breadcrumb-arrows'],
        ]) ?>
    </div>
</div>
<main id="main-content" class="page-body">
    <div class="container-fluid">
        <?= $content ?>
    </div>
</main>
