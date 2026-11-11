<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;
use yii\filters\AccessControl;
use yii\helpers\Url;

use app\components\Log;
use app\components\association\MembershipFee;
use app\components\legacy\LegacyImporter;
use app\components\payments\PayPal;
use app\components\payments\PayPalException;
use app\models\MembershipPayments;
use app\models\PaymentMethods;

/**
 * Paga quota (TIP-020 passo 5b): il socio paga la propria quota associativa online.
 * Raggiungibile anche da fuori dall'app: /quota porta qui dopo il login.
 * Importo, periodo e quota decisi dal server; ogni socio vede e conferma solo i propri pagamenti.
 */
class PayFeeController extends Controller
{
    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['index' => ['GET'], 'start' => ['POST'], 'paypal-return' => ['GET'], 'paypal-capture' => ['POST'], 'paypal-cancel' => ['GET']],
            ],
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => ['@']]],
            ],
        ];
    }

    public function actionIndex()
    {
        $user = Yii::$app->user->identity;
        return $this->render('index', [
            'quote' => MembershipFee::quote($user),
            'paypal' => $this->paypalAvailable(),
            'readOnly' => LegacyImporter::active(),
            'payments' => MembershipPayments::find()->where(['user_id' => $user->id])->with(['method', 'fee'])
                ->orderBy(['registered_on' => SORT_DESC, 'id' => SORT_DESC])->all(),
        ]);
    }

    /** Crea il pagamento in corso e l'ordine PayPal, poi reindirizza solo verso PayPal */
    public function actionStart()
    {
        $user = Yii::$app->user->identity;
        $quote = MembershipFee::quote($user);
        $method = $this->paypalMethod();
        if (LegacyImporter::active() || !$quote['payable'] || $method === null || !$this->paypalAvailable()
            || Yii::$app->request->post('method') !== 'paypal') {
            Yii::$app->session->setFlash('danger', Yii::t('app', 'Pagamento online non disponibile.'));
            return $this->redirect(['index']);
        }
        // un solo pagamento PayPal in corso per socio: i tentativi precedenti non confermati decadono
        MembershipPayments::updateAll(['status' => 'invalid'], ['user_id' => $user->id, 'status' => 'pending', 'payment_method_id' => $method->id]);
        $payment = new MembershipPayments([
            'user_id' => $user->id, 'fee_id' => $quote['fee']->id, 'payment_method_id' => $method->id,
            'amount' => $quote['amount'], 'registered_on' => date('Y-m-d'), 'starts_on' => $quote['starts_on'],
            'expires_on' => $quote['expires_on'], 'status' => 'pending',
        ]);
        $payment->save(false);
        try {
            $base = rtrim((string) Yii::$app->params['webapp_link'], '/');
            [$orderId, $approveUrl] = PayPal::createOrder((int) $payment->id, $quote['amount'],
                $quote['fee']->description . ' ' . $quote['year'], $base . Url::to(['paypal-return']), $base . Url::to(['paypal-cancel']));
        } catch (PayPalException $error) {
            $payment->status = 'invalid';
            $payment->save(false);
            Yii::error('PayPal order failed: ' . $error->getMessage(), __METHOD__);
            Yii::$app->session->setFlash('danger', Yii::t('app', 'PayPal non è raggiungibile. Riprova più tardi.'));
            return $this->redirect(['index']);
        }
        $payment->paypal_order_id = $orderId;
        $payment->save(false);
        Log::save('pay-fee', 'start', "Pagamento quota #{$payment->id} avviato con PayPal");
        return $this->redirect($approveUrl);
    }

    /** Ritorno da PayPal: riepilogo e conferma esplicita (POST), nessun addebito in GET */
    public function actionPaypalReturn($token = null)
    {
        $payment = $this->ownPendingPayment($token);
        if ($payment === null) {
            Yii::$app->session->setFlash('danger', Yii::t('app', 'Pagamento non trovato o già concluso.'));
            return $this->redirect(['index']);
        }
        return $this->render('review', ['payment' => $payment]);
    }

    public function actionPaypalCapture()
    {
        $token = Yii::$app->request->post('token');
        $payment = $this->ownPendingPayment($token);
        if ($payment === null) {
            Yii::$app->session->setFlash('info', Yii::t('app', 'Pagamento non trovato o già concluso.'));
            return $this->redirect(['index']);
        }
        try {
            $capture = PayPal::captureOrder($payment->paypal_order_id, (int) $payment->id);
        } catch (PayPalException $error) {
            Yii::error('PayPal capture failed for payment #' . $payment->id . ': ' . $error->getMessage(), __METHOD__);
            Yii::$app->session->setFlash('danger', Yii::t('app', 'Il pagamento non è stato completato. Nessun addebito confermato: riprova.'));
            return $this->redirect(['index']);
        }
        // la capture deve corrispondere esattamente al pagamento registrato
        if ($capture['currency'] !== 'EUR' || $capture['amount'] !== $payment->amount || $capture['custom_id'] !== (string) $payment->id
            || !preg_match('/^[A-Z0-9]{8,60}$/D', $capture['id'])) {
            $payment->status = 'invalid';
            $payment->save(false);
            Log::security("Capture PayPal non corrispondente per il pagamento quota #{$payment->id}");
            Yii::$app->session->setFlash('danger', Yii::t('app', 'Pagamento non corrispondente: contatta l\'associazione.'));
            return $this->redirect(['index']);
        }
        $payment->status = 'paid';
        $payment->paypal_txn_id = $capture['id'];
        $payment->registered_on = date('Y-m-d');
        if ($payment->saveWithReceipt(true)) {
            Log::save('pay-fee', 'paypal-capture', "Pagamento quota #{$payment->id} confermato da PayPal");
            Yii::$app->session->setFlash('success', Yii::t('app', 'Pagamento completato. Grazie!'));
        } else {
            Yii::$app->session->setFlash('info', Yii::t('app', 'Pagamento già registrato.'));
        }
        return $this->redirect(['index']);
    }

    public function actionPaypalCancel()
    {
        Yii::$app->session->setFlash('info', Yii::t('app', 'Pagamento annullato: nessun addebito.'));
        return $this->redirect(['index']);
    }

    /** Pagamento PayPal in corso dell'utente collegato, cercato per ID ordine (mai di un altro socio) */
    private function ownPendingPayment($token): ?MembershipPayments
    {
        if (!is_string($token) || !preg_match('/^[A-Z0-9]{8,36}$/D', $token)) return null;
        return MembershipPayments::findOne(['paypal_order_id' => $token, 'user_id' => Yii::$app->user->id, 'status' => 'pending']);
    }

    private function paypalMethod(): ?PaymentMethods
    {
        return PaymentMethods::find()->where(['LOWER(description)' => 'paypal', 'min_level' => 0])->one();
    }

    private function paypalAvailable(): bool
    {
        return PayPal::enabled() && trim((string) Yii::$app->params['webapp_link']) !== '' && $this->paypalMethod() !== null;
    }
}
