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
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['index' => ['GET', 'POST'], 'delete' => ['POST']]],
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

    /** Indirizzo salvato con Copia, Modifica ed Elimina; il form solo se manca o in modifica (?edit=1). */
    public function actionIndex(?string $merchant = null, ?string $edit = null)
    {
        $merchantId = $this->merchantId($merchant);
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
        $current = $current === false ? null : $current;
        if ($current !== null && $form->address === null) $form->address = $current['address'];
        return $this->render('index', [
            'form' => $form,
            'current' => $current,
            'editing' => $current === null || $edit === '1' || $form->hasErrors(),
            'merchants' => $this->scope->isAdmin() ? $this->merchantOptions() : [],
            'merchant' => $merchant,
            'selected' => $merchantId !== null,
        ]);
    }

    /** Rimozione: i nuovi incassi TTS vengono rifiutati; le richieste già create tengono l'indirizzo congelato. */
    public function actionDelete(?string $merchant = null)
    {
        $merchantId = $this->merchantId($merchant);
        if ($merchantId === null) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        $db = Yii::$app->db;
        $transaction = $db->beginTransaction(Transaction::READ_COMMITTED);
        try {
            PosManagement::lockMerchant($db, $this->scope, $merchantId);
            $previous = $db->createCommand('SELECT address FROM {{%merchant_tts_wallets}} WHERE merchant_id=:m FOR UPDATE', [':m' => $merchantId])->queryScalar();
            if ($previous !== false) {
                $db->createCommand()->delete('{{%merchant_tts_wallets}}', ['merchant_id' => $merchantId])->execute();
                Log::event(sprintf('Wallet TTS del merchant %d rimosso (era %s)', $merchantId, $previous), Log::TYPE_DATA);
            }
            $transaction->commit();
            Yii::$app->session->setFlash('success', Yii::t('app', 'TTS wallet removed: TTS payments are disabled until a new address is saved.'));
        } catch (\Throwable $error) {
            if ($transaction->isActive) $transaction->rollBack();
            if ($error instanceof ManagementException && $error->getMessage() === ManagementException::NOT_FOUND) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
            Yii::error('Merchant TTS wallet removal failure: ' . get_class($error), __METHOD__);
            Yii::$app->session->setFlash('danger', Yii::t('app', 'Operation failed. Please retry.'));
        }
        return $this->redirect($this->scope->isAdmin() ? ['index', 'merchant' => $merchant] : ['index']);
    }

    /** Merchant della pagina: il proprio per il gestore, quello scelto (ID cifrato) per l'admin; fuori scope = 404. */
    private function merchantId(?string $merchant): ?int
    {
        $merchantId = $this->scope->isAdmin() ? ($merchant === null ? null : $this->decodeId($merchant)) : $this->scope->merchantId;
        if ($merchantId !== null && ($merchantId === 0 || !$this->scope->covers($merchantId) || Merchants::findOne($merchantId) === null)) {
            throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        }
        return $merchantId;
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
        return Scope::decodeId($id);
    }
}
