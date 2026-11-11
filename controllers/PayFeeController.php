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
use app\components\association\BtcpayPayments;
use app\components\association\PayPalPayments;
use app\components\payments\BTCPay;
use app\components\payments\PaymentGatewayException;
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
                'actions' => ['index' => ['GET'], 'start' => ['POST'], 'paypal-return' => ['GET'], 'paypal-capture' => ['POST'], 'paypal-cancel' => ['GET'],
                    'btcpay-return' => ['GET'], 'btcpay-refresh' => ['POST']],
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
            'bitcoin' => $this->bitcoinAvailable(),
            'readOnly' => LegacyImporter::active(),
            'payments' => MembershipPayments::find()->where(['user_id' => $user->id])->with(['method', 'fee'])
                ->orderBy(['registered_on' => SORT_DESC, 'id' => SORT_DESC])->all(),
        ]);
    }

    /** Crea il pagamento in corso e l'ordine PayPal o la fattura BTCPay, poi reindirizza solo verso il gateway */
    public function actionStart()
    {
        $user = Yii::$app->user->identity;
        $quote = MembershipFee::quote($user);
        $gateway = Yii::$app->request->post('method');
        $available = ['paypal' => $this->paypalAvailable(), 'bitcoin' => $this->bitcoinAvailable()];
        $method = is_string($gateway) && ($available[$gateway] ?? false) ? $this->method($gateway) : null;
        if (LegacyImporter::active() || !$quote['payable'] || $method === null) {
            Yii::$app->session->setFlash('danger', Yii::t('app', 'Pagamento online non disponibile.'));
            return $this->redirect(['index']);
        }
        // un solo pagamento in corso per socio e metodo: i tentativi precedenti non conclusi decadono
        MembershipPayments::updateAll(['status' => 'invalid'], ['user_id' => $user->id, 'status' => 'pending', 'payment_method_id' => $method->id]);
        $payment = new MembershipPayments([
            'user_id' => $user->id, 'fee_id' => $quote['fee']->id, 'payment_method_id' => $method->id,
            'amount' => $quote['amount'], 'registered_on' => date('Y-m-d'), 'starts_on' => $quote['starts_on'],
            'expires_on' => $quote['expires_on'], 'status' => 'pending',
        ]);
        $payment->save(false);
        $base = rtrim((string) Yii::$app->params['webapp_link'], '/');
        $description = $quote['fee']->description . ' ' . $quote['year'];
        try {
            if ($gateway === 'paypal') {
                [$payment->paypal_order_id, $target] = PayPal::createOrder((int) $payment->id, $quote['amount'], $description,
                    $base . Url::to(['paypal-return']), $base . Url::to(['paypal-cancel']));
            } else {
                [$payment->btcpay_invoice_id, $target] = BTCPay::createInvoice((int) $payment->id, $quote['amount'], $description,
                    $base . Url::to(['btcpay-return']));
            }
        } catch (PayPalException | PaymentGatewayException $error) {
            $payment->status = 'invalid';
            $payment->save(false);
            Yii::error("Payment gateway $gateway failed: " . $error->getMessage(), __METHOD__);
            Yii::$app->session->setFlash('danger', Yii::t('app', 'Il servizio di pagamento non è raggiungibile. Riprova più tardi.'));
            return $this->redirect(['index']);
        }
        $payment->save(false);
        Log::save('pay-fee', 'start', "Pagamento quota #{$payment->id} avviato con $gateway");
        return $this->redirect($target);
    }

    /** Ritorno dal checkout BTCPay: lo stato arriva dal webhook o da "Aggiorna stato" (POST) */
    public function actionBtcpayReturn()
    {
        Yii::$app->session->setFlash('info', Yii::t('app', 'Pagamento in Bitcoin in elaborazione: lo stato si aggiorna appena la rete lo conferma.'));
        return $this->redirect(['index']);
    }

    /** Rilegge da BTCPay lo stato del proprio pagamento in Bitcoin in corso */
    public function actionBtcpayRefresh()
    {
        $method = $this->method('bitcoin');
        $payment = $method === null ? null : MembershipPayments::find()
            ->where(['user_id' => Yii::$app->user->id, 'status' => 'pending', 'payment_method_id' => $method->id])
            ->andWhere(['not', ['btcpay_invoice_id' => null]])->orderBy(['id' => SORT_DESC])->one();
        if ($payment === null || !BTCPay::enabled()) return $this->redirect(['index']);
        try {
            $result = BtcpayPayments::sync($payment);
        } catch (PaymentGatewayException $error) {
            Yii::error('BTCPay refresh failed: ' . $error->getMessage(), __METHOD__);
            $result = 'unavailable';
        }
        $messages = [
            'paid' => ['success', 'Pagamento completato. Grazie!'],
            'processing' => ['info', 'Pagamento in Bitcoin ancora in attesa di conferma.'],
            'expired' => ['warning', 'Fattura scaduta: nessun pagamento ricevuto. Puoi riprovare.'],
            'invalid' => ['danger', 'Pagamento non corrispondente: contatta l\'associazione.'],
            'already' => ['info', 'Pagamento già registrato.'],
            'unavailable' => ['danger', 'Il servizio di pagamento non è raggiungibile. Riprova più tardi.'],
        ];
        Yii::$app->session->setFlash($messages[$result][0], Yii::t('app', $messages[$result][1]));
        return $this->redirect(['index']);
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
            $result = PayPalPayments::captureAndComplete($payment);
        } catch (PayPalException $error) {
            Yii::error('PayPal capture failed for payment #' . $payment->id . ': ' . $error->getMessage(), __METHOD__);
            Yii::$app->session->setFlash('danger', Yii::t('app', 'Il pagamento non è stato completato. Nessun addebito confermato: riprova.'));
            return $this->redirect(['index']);
        }
        $messages = [
            'paid' => ['success', 'Pagamento completato. Grazie!'],
            'already' => ['info', 'Pagamento già registrato.'],
            'invalid' => ['danger', 'Pagamento non corrispondente: contatta l\'associazione.'],
        ];
        Yii::$app->session->setFlash($messages[$result][0], Yii::t('app', $messages[$result][1]));
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

    /** Metodo di pagamento online ('paypal' | 'bitcoin'), pagabile dal socio (livello minimo 0) */
    private function method(string $name): ?PaymentMethods
    {
        return PaymentMethods::find()->where(['LOWER(description)' => $name, 'min_level' => 0])->one();
    }

    private function paypalAvailable(): bool
    {
        return PayPal::enabled() && trim((string) Yii::$app->params['webapp_link']) !== '' && $this->method('paypal') !== null;
    }

    private function bitcoinAvailable(): bool
    {
        return BTCPay::enabled() && trim((string) Yii::$app->params['webapp_link']) !== '' && $this->method('bitcoin') !== null;
    }
}
