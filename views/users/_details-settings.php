<?php

$collapsed_text = [0 => Yii::t('app', 'Sidebar aperta'), 1 => Yii::t('app', 'Sidebar chiusa')];
$collapsed_value = \app\components\theme\NapayTheme::legacySidebarFolded() ? 1 : 0;

$options = [
    'spinner' => '<div class="button-spinner spinner-border text-primary" role="status"><span class="sr-only">Loading...</span></div>',
    'changeCollapsed' => yii\helpers\Url::to(['/users/change-collapsed']),
];

$this->registerJs(
    "var yiiOptions = " . yii\helpers\Json::htmlEncode($options) . ";",
    yii\web\View::POS_HEAD,
    'yiiOptions'
);
?>

<?php if ($model->id == Yii::$app->user->id) : ?>

    <div class="col-xl-12">
        <div class="card card-info card-outline">
            <div class="card-body box-profile">
                <div class="form-check form-switch">
                    <input <?= $collapsed_value === 0 ? 'checked' : '' ?> type="checkbox" class="form-check-input" id="pushmenu_collapsed">
                    <label class="form-check-label" for="pushmenu_collapsed" data-sidebar-expanded-label="<?= \yii\helpers\Html::encode($collapsed_text[0]) ?>" data-sidebar-folded-label="<?= \yii\helpers\Html::encode($collapsed_text[1]) ?>"><?= $collapsed_text[$collapsed_value] ?></label>
                </div>
            </div>
        </div>
    </div>

    <!-- SAVE APP ON HOME -->
    <div class="col-xl-12">
        <div class="card card-info card-outline">
            <div class="card-body box-profile">
                <div class="form-check form-switch">
                    <input type="checkbox" class="form-check-input" id="saveOnDesktop" onclick="js:saveOnDesktop();" >
                    <label class="form-check-label" for="saveOnDesktop">Salva App su Home</label>
                </div>
            </div>
        </div>
    </div>

    
<?php endif; ?>