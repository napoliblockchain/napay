<?php
namespace app\components\pos\btc;

/**
 * SIP-033: fatture BTC dei terminali tramite l'app POS di BTCPay (pagine pubbliche, nessuna chiave).
 * Implementazione reale: BtcpayPosGateway; i test usano una finta.
 */
interface PosInvoiceGateway
{
    /** Digest SHA-256 dell'origine BTCPay (stesso valore di BTCPAY_PROVISION_INSTANCE). */
    public function instance(): string;

    /** Pagina di pagamento della fattura sul server BTCPay (ripiego del QR, decisione D2). */
    public function checkoutUrl(string $invoiceId): string;

    /**
     * POST anonimo all'app POS: importo nella valuta dell'app e riferimento Napay come orderId.
     * @return array{outcome:'created', invoice_id:string}|array{outcome:'rejected', code:string}
     * @throws PosInvoiceUnknown esito ignoto (richiesta forse arrivata): mai ripetere il POST
     */
    public function createInvoice(string $appId, int $amountCents, string $orderId): array;

    /**
     * Stato pubblico della fattura per il pagamento on-chain; null se non disponibile ora (rete, formato).
     * @return array{invoice_id:string, order_id:?string, store_id:string, currency:string, fiat_digits:string, status:string,
     *               address:?string, due_satoshis:?int, confirmations:int, expires_in:?int}|null
     */
    public function status(string $invoiceId): ?array;
}
