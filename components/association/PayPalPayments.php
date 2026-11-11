<?php

namespace app\components\association;

use app\components\Log;
use app\components\payments\PayPal;
use app\components\payments\PayPalException;
use app\models\MembershipPayments;

/**
 * Esito PayPal di un pagamento quota (TIP-020 5b/5b-bis): stesso controllo per la conferma del socio e per i webhook.
 * La capture deve corrispondere esattamente al pagamento registrato (EUR, importo, riferimento).
 */
final class PayPalPayments
{
    /**
     * Cattura (idempotente lato PayPal) e chiude il pagamento in corso.
     * @return string paid | already | invalid
     * @throws PayPalException PayPal non raggiungibile o capture non completata
     */
    public static function captureAndComplete(MembershipPayments $payment): string
    {
        return self::complete($payment, PayPal::captureOrder((string) $payment->paypal_order_id, (int) $payment->id));
    }

    /** @return string paid | already | invalid */
    public static function complete(MembershipPayments $payment, array $capture): string
    {
        if ($payment->status !== 'pending') return 'already';
        if ($capture['currency'] !== 'EUR' || $capture['amount'] !== $payment->amount || $capture['custom_id'] !== (string) $payment->id
            || !preg_match('/^[A-Z0-9]{8,60}$/D', $capture['id'])) {
            MembershipPayments::updateAll(['status' => 'invalid'], ['id' => $payment->id, 'status' => 'pending']);
            $payment->status = 'invalid';
            Log::security("Capture PayPal non corrispondente per il pagamento quota #{$payment->id}");
            return 'invalid';
        }
        $payment->status = 'paid';
        $payment->paypal_txn_id = $capture['id'];
        $payment->registered_on = date('Y-m-d');
        if (!$payment->saveWithReceipt(true)) return 'already';
        Log::event("Pagamento quota #{$payment->id} confermato da PayPal");
        return 'paid';
    }
}
