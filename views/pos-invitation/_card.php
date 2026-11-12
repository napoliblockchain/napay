<?php

use yii\helpers\Html;

/* @var $title string */
/* @var $content string HTML gia' codificato */
?>
<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h4 class="card-title"><?= Html::encode($title) ?></h4></div>
                <div class="card-body"><?= $content ?></div>
            </div>
        </div>
    </div>
</div>
