<?php

namespace app\components\association;

use app\components\Log;
use app\components\payments\BTCPay;
use app\models\MembershipPayments;

/**
 * Esito BTCPay di un pagamento quota (TIP-020 passo 5c): stesso controllo per webhook e "Aggiorna stato" del socio.
 * Stato sempre riletto da BTCPay; la fattura deve corrispondere al pagamento (riferimento, EUR, importo).
 */
final class BtcpayPayments
{
    /**
     * @return string paid | invalid | expired | processing | already
     * @throws \app\components\payments\PaymentGatewayException BTCPay non raggiungibile
     */
    public static function sync(MembershipPayments $payment): string
    {
        if ($payment->status !== 'pending') return 'already';
        $invoice = BTCPay::getInvoice((string) $payment->btcpay_invoice_id);
        $amount = is_numeric($invoice['amount']) ? number_format((float) $invoice['amount'], 2, '.', '') : '';
        if ($invoice['id'] !== $payment->btcpay_invoice_id || $invoice['orderId'] !== BTCPay::orderId((int) $payment->id)
            || $invoice['currency'] !== 'EUR' || $amount !== $payment->amount) {
            MembershipPayments::updateAll(['status' => 'invalid'], ['id' => $payment->id, 'status' => 'pending']);
            Log::security("Fattura BTCPay non corrispondente per il pagamento quota #{$payment->id}");
            return 'invalid';
        }
        switch ($invoice['status']) {
            case 'Settled':
                $payment->status = 'paid';
                $payment->registered_on = date('Y-m-d');
                if (!$payment->saveWithReceipt(true)) return 'already';
                Log::event("Pagamento quota #{$payment->id} saldato su BTCPay");
                return 'paid';
            case 'Expired':
            case 'Invalid':
                MembershipPayments::updateAll(['status' => 'invalid'], ['id' => $payment->id, 'status' => 'pending']);
                return 'expired';
        }
        // New / Processing: pagamento ricevuto o in attesa di conferme, resta in corso
        return 'processing';
    }
}
