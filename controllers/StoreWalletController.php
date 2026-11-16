<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;
use yii\filters\AccessControl;
use yii\db\Query;
use yii\db\Transaction;

use app\components\Log;
use app\components\pos\management\ManagementException;
use app\components\pos\management\PosManagement;
use app\components\pos\management\Scope;
use app\components\pos\tts\LostWallets;
use app\models\forms\pos\TtsWalletForm;

/**
 * SIP-030 punto 3: wallet TTS del negozio, destinazione dei pagamenti TTS dei POS di quel negozio (come il wallet BTC).
 * Gestore solo sui negozi del proprio merchant, admin su tutti (stesso Scope di C4). Prova di possesso non richiesta.
 */
class StoreWalletController extends Controller
{
    private Scope $scope;

    public function behaviors()
    {
        return [
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['index' => ['GET', 'POST'], 'delete' => ['POST'], 'report-lost' => ['POST']]],
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

    /**
     * Negozio scelto (ID cifrato; l'unico negozio in scope se non indicato): indirizzo con Copia, Modifica ed Elimina;
     * il form solo se manca o in modifica (?edit=1).
     */
    public function actionIndex(?string $store = null, ?string $edit = null)
    {
        $stores = $this->scope->storeOptions();
        if ($store === null && count($stores) === 1) $store = array_key_first($stores);
        $target = $this->target($store);
        $form = new TtsWalletForm();
        if ($target !== null && $form->load(Yii::$app->request->post()) && $form->validate()) {
            try {
                $this->save($target, $form->address);
                Yii::$app->session->setFlash('success', Yii::t('app', 'TTS wallet saved.'));
                return $this->redirect(['index', 'store' => $store]);
            } catch (ManagementException $e) {
                if ($e->getMessage() === ManagementException::NOT_FOUND) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
                Yii::$app->session->setFlash('danger', Yii::t('app', 'Operation failed. Please retry.'));
            }
        }
        $current = $target === null ? false : Yii::$app->db->createCommand('SELECT address, updated_at FROM {{%store_tts_wallets}} WHERE store_id=:s', [':s' => $target['id']])->queryOne();
        $current = $current === false ? null : $current;
        if ($current !== null && $form->address === null) $form->address = $current['address'];
        $lost = $target === null ? [] : (new Query())->select(['address', 'reported_at', 'recovered_at'])->from(LostWallets::TABLE)
            ->where(['store_id' => $target['id']])->orderBy(['reported_at' => SORT_DESC])->limit(20)->all();
        return $this->render('index', [
            'form' => $form,
            'current' => $current,
            'lost' => $lost,
            'editing' => $current === null || $edit === '1' || $form->hasErrors(),
            'stores' => $stores,
            'store' => $target === null ? null : $store,
        ]);
    }

    /** Rimozione: i nuovi incassi TTS del negozio vengono rifiutati; le richieste già create tengono l'indirizzo congelato. */
    public function actionDelete(?string $store = null)
    {
        $target = $this->target($store);
        if ($target === null) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        $db = Yii::$app->db;
        $transaction = $db->beginTransaction(Transaction::READ_COMMITTED);
        try {
            PosManagement::lockMerchant($db, $this->scope, $target['merchant_id']);
            $previous = $db->createCommand('SELECT address FROM {{%store_tts_wallets}} WHERE store_id=:s FOR UPDATE', [':s' => $target['id']])->queryScalar();
            if ($previous !== false) {
                $db->createCommand()->delete('{{%store_tts_wallets}}', ['store_id' => $target['id']])->execute();
                Log::event(sprintf('Wallet TTS del negozio %d rimosso (era %s)', $target['id'], $previous), Log::TYPE_DATA);
            }
            $transaction->commit();
            Yii::$app->session->setFlash('success', Yii::t('app', 'TTS wallet removed: TTS payments of the store are disabled until a new address is saved.'));
        } catch (\Throwable $error) {
            if ($transaction->isActive) $transaction->rollBack();
            if ($error instanceof ManagementException && $error->getMessage() === ManagementException::NOT_FOUND) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
            Yii::error('Store TTS wallet removal failure: ' . get_class($error), __METHOD__);
            Yii::$app->session->setFlash('danger', Yii::t('app', 'Operation failed. Please retry.'));
        }
        return $this->redirect(['index', 'store' => $store]);
    }

    /**
     * SIP-031: seed perso. Doppia conferma (parola CONFERMA/CONFIRM nella lingua dell'utente) e motivazione; non si annulla.
     * Blocca subito gli incassi TTS: indirizzo tolto dai negozi del merchant, richieste in attesa annullate.
     */
    public function actionReportLost(?string $store = null)
    {
        $target = $this->target($store);
        if ($target === null) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        $request = Yii::$app->request;
        $reason = trim((string) $request->post('reason', ''));
        $confirm = mb_strtoupper(trim((string) $request->post('confirm', '')));
        if ($confirm !== Yii::t('app', 'CONFIRM') || mb_strlen($reason) < 3 || mb_strlen($reason) > 1000) {
            Yii::$app->session->setFlash('danger', Yii::t('app', 'Not reported: write the reason and type {word} to confirm.', ['word' => Yii::t('app', 'CONFIRM')]));
            return $this->redirect(['index', 'store' => $store]);
        }
        try {
            $result = LostWallets::report($this->scope, $target['id'], $target['merchant_id'], $reason);
            Yii::$app->session->setFlash('warning', Yii::t('app', 'Lost seed reported: TTS payments on this address are blocked ({stores} stores), {cancelled} pending requests cancelled. Save a new address to collect again.',
                ['stores' => $result['stores'], 'cancelled' => $result['cancelled']]));
        } catch (ManagementException $e) {
            if ($e->getMessage() === ManagementException::NOT_FOUND) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
            Yii::$app->session->setFlash('danger', Yii::t('app', 'Operation failed. Please retry.'));
        }
        return $this->redirect(['index', 'store' => $store]);
    }

    /** Negozio della pagina: null se non indicato; fuori scope, inesistente o malformato = 404. */
    private function target(?string $store): ?array
    {
        if ($store === null) return null;
        $id = $this->scope->storeId($store);
        $merchant = $id === null ? false : Yii::$app->db->createCommand('SELECT merchant_id FROM {{%stores}} WHERE id=:id', [':id' => $id])->queryScalar();
        if ($merchant === false) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        return ['id' => $id, 'merchant_id' => (int) $merchant];
    }

    /** Lock del merchant e scope ricalcolato (come C4), poi inserimento o aggiornamento con audit. */
    private function save(array $target, string $address): void
    {
        $db = Yii::$app->db;
        $transaction = $db->beginTransaction(Transaction::READ_COMMITTED);
        try {
            PosManagement::lockMerchant($db, $this->scope, $target['merchant_id']);
            if ($db->createCommand('SELECT id FROM {{%stores}} WHERE id=:s AND merchant_id=:m FOR UPDATE', [':s' => $target['id'], ':m' => $target['merchant_id']])->queryScalar() === false) {
                throw new ManagementException(ManagementException::NOT_FOUND);
            }
            $previous = $db->createCommand('SELECT address FROM {{%store_tts_wallets}} WHERE store_id=:s FOR UPDATE', [':s' => $target['id']])->queryScalar();
            $row = ['address' => $address, 'updated_by' => $this->scope->actorId, 'updated_at' => time()];
            if ($previous === false) $db->createCommand()->insert('{{%store_tts_wallets}}', ['store_id' => $target['id'], 'merchant_id' => $target['merchant_id']] + $row)->execute();
            else $db->createCommand()->update('{{%store_tts_wallets}}', $row, ['store_id' => $target['id']])->execute();
            Log::event(sprintf('Wallet TTS del negozio %d impostato a %s (precedente %s)', $target['id'], $address, $previous === false ? 'nessuno' : $previous), Log::TYPE_DATA);
            // SIP-031: indirizzo segnalato perso e salvato di nuovo = seed ritrovato
            LostWallets::recover($db, $target['merchant_id'], $address, $this->scope->actorId);
            $transaction->commit();
        } catch (\Throwable $error) {
            if ($transaction->isActive) $transaction->rollBack();
            if ($error instanceof ManagementException) throw $error;
            Yii::error('Store TTS wallet failure: ' . get_class($error), __METHOD__);
            throw new ManagementException(ManagementException::FAILED);
        }
    }
}
