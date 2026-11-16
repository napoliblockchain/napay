<?php

/** @var yii\web\View $this */
/** @var string $name */
/** @var string $message */
/** @var Exception$exception */

use yii\helpers\Html;
use app\components\Log;

$this->context->layout = (!Yii::$app->user->isGuest) ? 'main' : 'auth';

// Log message
$message_log = Yii::t('app', 'ERROR for user {user}. {title}: {message}', [
    'user' => Yii::$app->user->identity->username ?? 'guest',
    'title' => $name,
    'message' => $message,
]);
try {
    Log::save(Yii::$app->controller->id, Yii::$app->controller->action->id, $message_log);
} catch (\Throwable $error) {
    Yii::warning('Error audit could not be persisted.', __FILE__);
}
// end log message

$this->title = $name;
?>
<div class="site-error">
    <div class="row">
        <div class="col-lg-12 col-12">
            <div class="card card-outline card-danger">
                <div class="card-header">
                    <h1><?= Html::encode($this->title) ?></h1>
                </div>
                <div class="card-body">
                    <div class="alert alert-danger">
                        <?= nl2br(Html::encode($message)) ?>
                    </div>
                    <?php if (Yii::$app->requestedRoute === 'site/activate'): ?>
                        <p><?= Html::a(Yii::t('app', 'Request activation link'), ['site/resend-activation']) ?></p>
                    <?php elseif (Yii::$app->requestedRoute === 'site/reset-password'): ?>
                        <p><?= Html::a(Yii::t('app', 'Request password reset'), ['site/forgot-password']) ?></p>
                    <?php endif; ?>

                    <p><?= Html::encode(Yii::t('app', 'The above error occurred while the web server was processing your request.')) ?></p>
                    <p><?= Html::encode(Yii::t('app', 'Please contact us if you think this is a server error. Thank you.')) ?></p>
                </div>
            </div>
        </div>
    </div>
</div>
