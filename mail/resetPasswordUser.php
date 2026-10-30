<?php

use yii\helpers\Html;
use app\components\sendMail;

$URL = $resetUrl;
?>

<style>
    body {
        font-family: Arial, sans-serif;
    }

    p,
    button {
        font-family: Georgia, serif;
    }

    h1 {
        font-family: "Helvetica Neue", Arial, sans-serif;
    }

    .card {
        max-width: 600px;
        margin: 0 auto;
        padding: 20px;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        border-radius: 4px;
    }

    .card-header {
        background-color: #f8f9fa;
        border-bottom: none;
        padding: 0;
        font-size: 40px;
        font-weight: bold;
    }

    .card-header img {
        width: 80px;
        margin-right: 10px;
        vertical-align: middle;
    }

    .card-title {
        text-align: center;
        font-weight: bold;
        margin-bottom: 20px;
    }

    .card-content {
        margin-bottom: 20px;
    }

    .card-content p {
        margin-bottom: 10px;
    }

    .card-content p.salutation {
        font-weight: bold;
    }

    .card-footer {
        padding: 10px;
        background-color: #f8f9fa;
        border-top: 1px solid #dee2e6;
    }
</style>

<div class="card">
    <div class="card-header">
        <img src="<?= Html::encode(sendMail::applicationUrl('bundles/site/images/logo-60.png')) ?>" alt="Logo">
        <span><?= Html::encode(Yii::$app->name) ?></span>
    </div>
    <div class="card-title">
        <h2>Reimpostazione password</h2>
    </div>
    <div class="card-content">
        <p class="salutation">Ciao <?= Html::encode($user->first_name ?: $user->username) ?>,</p>
        <p>qualcuno ha richiesto la reimpostazione password del tuo account. Hai tempo 60 minuti per collegarti all'indirizzo fornito di seguito e concludere il processo di ripristino.</p>

        <p><b>Se non sei stato tu ad effettuare questa richiesta, ignora semplicemente questa email oppure contatta un amministratore.</b></p>

        <div style="margin-top: 20px;">
            <a href="<?= Html::encode($URL) ?>" rel="noreferrer">
                <button type="button" style="padding: 3px 3px 3px 3px;
                    				outline: none;
                              		cursor: pointer;
                    				background-color: blue;
									color: white;
                    				border: none;
                    				border-radius: 5px;
                    				box-shadow: 0 3px #555;
                    				min-width: 100px;
									min-height: 30px;
                    				text-shadow: 1px 1px 2px black;">
                    <?php echo Yii::t('app', 'Collegati'); ?>
                </button>

            </a>
        </div>
        <div>
            <p style="margin-top: 28px;font-size: 14px;">Buona giornata,
                <br><strong><?= Html::encode(Yii::$app->params['adminName']) ?></strong>
            </p>
        </div>

    </div>
    <div class="card-footer">
        <div>
            <p style="font-size: 14px;">
                <strong><?= Html::encode(Yii::$app->name) ?></strong>
                <br><?= Yii::t('app', 'Phone: ') ?><?= Html::encode(Yii::$app->params['adminPhone']) ?>
                <br><?= Html::encode(Yii::$app->params['adminEmail'] . ' | ' . Yii::$app->params['website']) ?>
            </p>
        </div>
        <div>
            <p style="font-size: 10px;"><?php echo Yii::t('app', 'You receive this email because you have registered on our site and / or you have used our services and you have given consent to receive email communications from us.'); ?>
            </p>
        </div>
        <div>
            <p style="font-size: 10px;">---<br><strong><?php echo Yii::t('app', 'Confidentiality and security of the message'); ?></strong><br><?php echo Yii::t('app', 'The content of the e-mail is reserved and is addressed exclusively to the identified recipient (s). Therefore it is forbidden to read it, copy it, disclose it or use it by anyone except the recipient (s). If you are not the recipient, we invite you to delete the message and any attachments by immediately sending us written communication by e-mail. Although the sender undertakes to take the most appropriate measures to ensure the absence of viruses within any attachments to this e-mail communication, such measures do not constitute an absolute guarantee and therefore we invite you to put in place your antivirus checks before opening any attachment. The sender therefore assumes no responsibility for any damage that you may suffer due to viruses contained in the messages.'); ?>
            </p>
        </div>
    </div>
</div>

<?php
//  exit;
?>
