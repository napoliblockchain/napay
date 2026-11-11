<?php

use yii\helpers\Html;

/* @var $status string chiave di app\models\Members::STATUS_LABELS o MembershipPayments::STATUS_LABELS */
/* @var $label string */
$classes = [
    'active' => 'bg-success', 'paid' => 'bg-success', 'exempt' => 'bg-azure',
    'expiring' => 'bg-warning', 'pending' => 'bg-secondary',
    'expired' => 'bg-danger', 'lapsed' => 'bg-dark', 'invalid' => 'bg-danger', 'never' => 'bg-secondary-lt', 'refunded' => 'bg-orange', 'reversed' => 'bg-orange',
];
echo Html::tag('span', Html::encode($label), ['class' => 'badge text-white ' . ($classes[$status] ?? 'bg-secondary')]);
