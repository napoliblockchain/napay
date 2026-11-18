<?php

use yii\helpers\Html;

/* @var $this yii\web\View */
$this->title = Yii::t('app', 'POS operator invitation');
echo $this->render('_card', ['title' => $this->title,
    'content' => Html::tag('p', Html::encode(Yii::t('app', 'You are signed in with another account. Sign out and open the link again: the invitation creates a separate account and is not linked to the current one.')))]);
