<?php

namespace app\controllers;

use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\BadRequestHttpException;
use yii\web\Controller;

use app\components\Crypt;
use app\components\Log;
use app\components\User;
use app\components\btcpay\provisioning\ProvisioningOutbox;

/**
 * Riprova del collegamento BTCPay di un account merchant, un negozio o un terminale (SIP-022 B5): solo admin, POST con CSRF.
 * Accoda un nuovo lavoro (generazione successiva); il worker cerca prima la risorsa esistente e non crea doppioni.
 */
class BtcpayProvisioningController extends Controller
{
    private const TARGETS = ['merchant_account' => 'merchant', 'store_link' => 'store', 'pos_link' => 'terminal'];

    public function behaviors()
    {
        return [
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['retry' => ['POST']]],
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => ['@'], 'matchCallback' => static fn() => User::isAdministrator()]],
            ],
        ];
    }

    public function actionRetry()
    {
        $kind = Yii::$app->request->post('kind');
        $plain = Crypt::decrypt((string) Yii::$app->request->post('id', ''));
        if (!is_string($kind) || !isset(self::TARGETS[$kind]) || !is_string($plain) || !ctype_digit($plain) || strlen($plain) > 10 || (int) $plain < 1) {
            throw new BadRequestHttpException(Yii::t('app', 'Invalid request.'));
        }
        $entity = (int) $plain;
        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $queued = ProvisioningOutbox::retry($db, $kind, $entity, (int) Yii::$app->user->id);
            $transaction->commit();
        } catch (\Throwable $error) {
            if ($transaction->isActive) $transaction->rollBack();
            Yii::error('BTCPay retry request failed: ' . get_class($error), __METHOD__);
            $queued = false;
        }
        if ($queued) {
            Log::event(sprintf('Riprova collegamento BTCPay richiesta: %s %d', self::TARGETS[$kind], $entity), Log::TYPE_DATA);
            Yii::$app->session->setFlash('success', Yii::t('app', 'BTCPay link retry requested: it will be processed shortly.'));
        } else {
            Yii::$app->session->setFlash('warning', Yii::t('app', 'Nothing to retry for this BTCPay link.'));
        }
        return match ($kind) {
            'merchant_account' => $this->redirect(['merchants/view', 'id' => Crypt::encrypt($entity)]),
            'store_link' => $this->redirect(['stores/view', 'id' => Crypt::encrypt($entity)]),
            default => $this->redirect(['pos-management/index']),
        };
    }
}
