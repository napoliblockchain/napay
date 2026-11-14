<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;
use yii\filters\AccessControl;
use yii\db\Transaction;

use app\components\Crypt;
use app\components\Log;
use app\components\pos\management\ManagementException;
use app\components\pos\management\PosManagement;
use app\components\pos\management\Scope;
use app\models\Merchants;
use app\models\forms\pos\TtsWalletForm;

/**
 * SIP-021 fase 3: wallet TTS del merchant, destinazione di tutti i pagamenti dei suoi POS.
 * Gestore solo sul proprio merchant, admin su tutti (stesso Scope di C4). Prova di possesso non richiesta in fase 3.
 */
class MerchantWalletController extends Controller
{
    private Scope $scope;

    public function behaviors()
    {
        return [
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['index' => ['GET', 'POST']]],
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => ['@'], 'matchCallback' => fn($rule, $action) => Scope::current() !== null]],
            ],
        ];
    }

    public function beforeAction($action)
    {
        if (!parent::beforeAction($action)) return false;
        $scope = Scope::current();
        if ($scope === null) throw new ForbiddenHttpException();
        $this->scope = $scope;
        return true;
    }

    public function actionIndex(?string $merchant = null)
    {
        $merchantId = $this->scope->isAdmin() ? ($merchant === null ? null : $this->decodeId($merchant)) : $this->scope->merchantId;
        if ($merchantId !== null && ($merchantId === 0 || !$this->scope->covers($merchantId) || Merchants::findOne($merchantId) === null)) {
            throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        }
        $form = new TtsWalletForm(['merchant' => $merchant]);
        if ($merchantId !== null && $form->load(Yii::$app->request->post()) && $form->validate()) {
            try {
                $this->save($merchantId, $form->address);
                Yii::$app->session->setFlash('success', Yii::t('app', 'TTS wallet saved.'));
                return $this->redirect($this->scope->isAdmin() ? ['index', 'merchant' => $merchant] : ['index']);
            } catch (ManagementException $e) {
                if ($e->getMessage() === ManagementException::NOT_FOUND) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
                Yii::$app->session->setFlash('danger', Yii::t('app', 'Operation failed. Please retry.'));
            }
        }
        $current = $merchantId === null ? false : Yii::$app->db->createCommand('SELECT address, updated_at FROM {{%merchant_tts_wallets}} WHERE merchant_id=:m', [':m' => $merchantId])->queryOne();
        return $this->render('index', [
            'form' => $form,
            'current' => $current === false ? null : $current,
            'merchants' => $this->scope->isAdmin() ? $this->merchantOptions() : [],
            'merchant' => $merchant,
            'selected' => $merchantId !== null,
        ]);
    }

    /** Lock del merchant e scope ricalcolato (come C4), poi inserimento o aggiornamento con audit. */
    private function save(int $merchantId, string $address): void
    {
        $db = Yii::$app->db;
        $transaction = $db->beginTransaction(Transaction::READ_COMMITTED);
        try {
            PosManagement::lockMerchant($db, $this->scope, $merchantId);
            $previous = $db->createCommand('SELECT address FROM {{%merchant_tts_wallets}} WHERE merchant_id=:m FOR UPDATE', [':m' => $merchantId])->queryScalar();
            $row = ['address' => $address, 'updated_by' => $this->scope->actorId, 'updated_at' => time()];
            if ($previous === false) $db->createCommand()->insert('{{%merchant_tts_wallets}}', ['merchant_id' => $merchantId] + $row)->execute();
            else $db->createCommand()->update('{{%merchant_tts_wallets}}', $row, ['merchant_id' => $merchantId])->execute();
            Log::event(sprintf('Wallet TTS del merchant %d impostato a %s (precedente %s)', $merchantId, $address, $previous === false ? 'nessuno' : $previous), Log::TYPE_DATA);
            $transaction->commit();
        } catch (\Throwable $error) {
            if ($transaction->isActive) $transaction->rollBack();
            if ($error instanceof ManagementException) throw $error;
            Yii::error('Merchant TTS wallet failure: ' . get_class($error), __METHOD__);
            throw new ManagementException(ManagementException::FAILED);
        }
    }

    private function merchantOptions(): array
    {
        $options = [];
        foreach (Merchants::find()->select(['id', 'description'])->orderBy(['description' => SORT_ASC, 'id' => SORT_ASC])->asArray()->all() as $row) {
            $options[Crypt::encrypt($row['id'])] = $row['description'];
        }
        return $options;
    }

    private function decodeId($id): int
    {
        if (!is_string($id) || $id === '' || strlen($id) > 512) return 0;
        try {
            $plain = Crypt::decrypt($id);
        } catch (\Throwable $error) {
            return 0;
        }
        return ctype_digit($plain) ? (int) $plain : 0;
    }
}
