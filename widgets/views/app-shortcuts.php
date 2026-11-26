<?php

use yii\helpers\Html;

/** @var array $links */
/** @var bool $publicPage */
$options = ['target' => '_blank', 'rel' => 'noopener noreferrer'];
$newTab = Html::tag('span', Html::encode(Yii::t('app', ' (opens in a new tab)')), ['class' => 'visually-hidden']);
?>
<?php if (!$publicPage): ?>
    <div class="nav-item dropdown" data-napay-app-shortcuts="menu">
        <button type="button" class="btn btn-icon btn-ghost-secondary" data-bs-toggle="dropdown"
                aria-expanded="false" aria-label="<?= Html::encode(Yii::t('app', 'Apps')) ?>">
            <i class="fas fa-th" aria-hidden="true"></i>
        </button>
        <div class="dropdown-menu dropdown-menu-end">
            <?php foreach ($links as [$url, $label, $icon]): ?>
                <?= Html::a(Html::tag('i', '', ['class' => "fas $icon me-2", 'aria-hidden' => 'true'])
                    . Html::encode($label) . $newTab, $url, $options + ['class' => 'dropdown-item']) ?>
            <?php endforeach ?>
        </div>
    </div>
<?php else: ?>
    <section class="mt-4 pt-3 border-top" data-napay-app-shortcuts="public" aria-labelledby="napay-public-apps-title">
        <h2 class="small fw-bold text-secondary mb-2" id="napay-public-apps-title"><?= Html::encode(Yii::t('app', 'Apps')) ?></h2>
        <div class="row g-2">
            <?php foreach ($links as [$url, $label, $icon]): ?>
                <div class="col-12 col-sm-6">
                    <?= Html::beginTag('a', $options + ['href' => $url, 'class' => 'btn btn-outline-secondary w-100 d-flex align-items-center justify-content-center gap-2 py-2 fw-semibold']) ?>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <?php if ($icon === 'fa-wallet'): ?>
                                <path d="M19 8V5H6a3 3 0 0 0 0 6h15v9H6a3 3 0 0 1-3-3V8m18 6h-5v3h5"/>
                            <?php else: ?>
                                <path d="M4 4h16v11H4zM12 15v5m-5 0h10"/>
                            <?php endif ?>
                        </svg>
                        <?= Html::encode($label) . $newTab ?>
                    <?= Html::endTag('a') ?>
                </div>
            <?php endforeach ?>
        </div>
    </section>
<?php endif ?>
