<?php
namespace app\components\pos\btc;

use Yii;
use app\components\Log;
use app\components\pos\OpeningException;
use app\components\pos\TerminalOpenings;
use app\components\pos\tts\PaymentException;
use app\models\PosOperatorSessions;
use yii\db\Connection;
use yii\db\Transaction;

/**
 * SIP-033 passo 3: pagamenti BTC dei terminali POS con la fattura dell'app POS di BTCPay del terminale.
 * - Creazione: righe e lock come i pagamenti TTS; checkpoint `creating` salvato PRIMA della rete, poi un solo POST.
 *   Fattura accettata solo se lo stato pubblico conferma ID, riferimento Napay (orderId), store, valuta e importo.
 *   Esito ignoto = `reconcile_required`, mai un secondo POST; lo sweep lo chiude dopo la scadenza.
 * - Esito: webhook firmati dello store (applyEvent) e stato pubblico letto dal server BTCPay (view, sweep).
 *   Mai valori dal browser del POS. D1: `processing` = ricevuto (0 conferme), `settled` = confermato.
 * - Transizioni solo in avanti con CAS su stato e versione; nessuna cancellazione.
 */
final class BtcPayments
{
    public const UUID = TerminalOpenings::UUID;
    public const MAX_CENTS = 100000;
    public const TTL = 900;
    public const CURRENCIES = ['EUR', 'USD'];
    private const TABLE = '{{%pos_btc_payments}}';
    /** stato pubblico BTCPay (2.x e nomi storici) -> stato Napay */
    private const STATUS = ['new' => 'pending', 'processing' => 'processing', 'paid' => 'processing', 'settled' => 'settled', 'confirmed' => 'settled',
        'complete' => 'settled', 'expired' => 'expired', 'invalid' => 'invalid'];
    /** Eventi dello store che fanno rileggere la fattura (mai fonte dello stato). */
    private const EVENTS = ['InvoiceReceivedPayment', 'InvoiceProcessing', 'InvoiceSettled', 'InvoiceExpired', 'InvoiceInvalid'];
    private const NEXT = ['pending' => ['processing', 'settled', 'expired', 'invalid'], 'processing' => ['settled', 'expired', 'invalid']];

    /**
     * @return array{0:int, 1:array} [201 nuova | 200 replay, pagamento pubblico]
     */
    public static function create(PosOperatorSessions $session, string $openingId, string $instanceId, string $generation, int $amountCents,
        string $idempotencyKey, ?PosInvoiceGateway $gateway = null): array
    {
        if (preg_match(self::UUID, $idempotencyKey) !== 1 || $amountCents < 1 || $amountCents > self::MAX_CENTS) throw new \InvalidArgumentException('invalid_payment');
        $gateway ??= BtcpayPosGateway::fromEnv();
        if ($gateway === null) throw new PaymentException(PaymentException::UNAVAILABLE);
        $db = Yii::$app->db;
        $key = hash('sha256', $idempotencyKey);
        $request = hash('sha256', 'btc|' . $openingId . '|' . $amountCents);
        $replay = self::replay($db, $session, $key, $request, $gateway);
        if ($replay !== null) return [200, $replay];
        $opening = TerminalOpenings::requireUsable($session, $openingId, $instanceId, $generation);
        if (self::target($db, (int) $opening['terminal_id'], (int) $opening['merchant_id'], $gateway->instance()) === null) throw new PaymentException(PaymentException::UNAVAILABLE);
        $created = self::transaction($db, static function () use ($db, $session, $opening, $openingId, $instanceId, $generation, $amountCents, $key, $request, $gateway) {
            $merchant = (int) $opening['merchant_id'];
            $db->createCommand('SELECT id FROM {{%merchants}} WHERE id=:id FOR UPDATE', [':id' => $merchant])->queryScalar();
            $terminal = $db->createCommand('SELECT id, store_id FROM {{%pos_terminals}} WHERE id=:id AND merchant_id=:m FOR UPDATE',
                [':id' => (int) $opening['terminal_id'], ':m' => $merchant])->queryOne();
            $db->createCommand('SELECT id FROM {{%pos_operator_sessions}} WHERE id=:id FOR UPDATE', [':id' => (int) $session->id])->queryScalar();
            $locked = $db->createCommand('SELECT * FROM {{%pos_terminal_openings}} WHERE id=:id FOR UPDATE', [':id' => (int) $opening['id']])->queryOne();
            $replay = self::replay($db, $session, $key, $request, $gateway);
            if ($replay !== null) return [200, $replay];
            if ($terminal === false || $locked === false || $locked['state'] !== 'active' || $locked['instance_id'] !== $instanceId || (string) $locked['generation'] !== $generation) {
                throw new OpeningException(OpeningException::INVALID);
            }
            TerminalOpenings::requireUsable($session, $openingId, $instanceId, $generation);
            $target = self::target($db, (int) $terminal['id'], $merchant, $gateway->instance());
            if ($target === null) throw new PaymentException(PaymentException::UNAVAILABLE);
            $now = time();
            $row = ['public_id' => self::uuid(), 'merchant_id' => $merchant, 'store_id' => (int) $terminal['store_id'], 'terminal_id' => (int) $terminal['id'],
                'opening_id' => (int) $locked['id'], 'session_id' => (int) $session->id, 'user_id' => (int) $session->user_id,
                'instance' => $gateway->instance(), 'remote_store_id' => $target['remote_store_id'], 'remote_app_id' => $target['remote_app_id'],
                'amount_cents' => $amountCents, 'currency' => $target['currency'], 'state' => 'creating', 'creation_started_at' => $now,
                'idempotency_sha256' => $key, 'request_sha256' => $request, 'created_at' => $now, 'updated_at' => $now, 'expires_at' => $now + self::TTL];
            $db->createCommand()->insert(self::TABLE, $row)->execute();
            return [201, (int) $db->getLastInsertID()];
        });
        if ($created[0] === 200) return $created;
        // checkpoint già salvato: da qui un solo POST, nessuna transazione aperta durante la rete
        $payment = self::row($created[1]);
        try {
            $result = $gateway->createInvoice($payment['remote_app_id'], (int) $payment['amount_cents'], $payment['public_id']);
        } catch (PosInvoiceUnknown $error) {
            self::move($payment, 'reconcile_required', ['last_error_code' => 'invoice_outcome_unknown']);
            return [201, self::publicPayment(self::row((int) $payment['id']), $openingId, $gateway)];
        }
        if ($result['outcome'] !== 'created') {
            self::move($payment, 'failed', ['last_error_code' => $result['code'], 'closed_at' => time()]);
        } else {
            self::accept($payment, $result['invoice_id'], $gateway);
        }
        $payment = self::row((int) $payment['id']);
        Log::save('posapi', 'payments', sprintf('Richiesta di pagamento BTC %s di %s %s: %s', $payment['public_id'], self::amount((int) $payment['amount_cents']), $payment['currency'], $payment['state']));
        return [201, self::publicPayment($payment, $openingId, $gateway)];
    }

    /** Pagamento proprio della sessione; se in attesa o ricevuto, stato pubblico dal server BTCPay (rete assente = invariato). */
    public static function view(PosOperatorSessions $session, string $paymentId, ?PosInvoiceGateway $gateway = null): array
    {
        $gateway ??= BtcpayPosGateway::fromEnv();
        $payment = self::own($session, $paymentId);
        if ($gateway !== null) $payment = self::refresh($payment, $gateway);
        return self::publicPayment($payment, self::openingPublicId((int) $payment['opening_id']), $gateway);
    }

    /**
     * SIP-033 passo 5: voci BTC dello storico del terminale (PosHistory), più recenti prima. Stato salvato, nessuna chiamata
     * a BTCPay; ricevuto = processing_at (o settled_at se confermato direttamente). Mai indirizzo, QR o ID della fattura.
     */
    public static function history(int $terminalId, int $sessionId, int $since, int $limit): array
    {
        $rows = Yii::$app->db->createCommand('SELECT * FROM ' . self::TABLE . ' WHERE terminal_id=:t AND created_at >= :since ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)),
            [':t' => $terminalId, ':since' => $since])->queryAll();
        $items = [];
        foreach ($rows as $row) {
            $received = $row['processing_at'] ?? $row['settled_at'];
            $items[] = ['id' => $row['public_id'], 'method' => 'btc', 'amount' => self::amount((int) $row['amount_cents']), 'currency' => $row['currency'], 'state' => $row['state'],
                'created_at' => (int) $row['created_at'], 'paid_at' => $received === null ? null : (int) $received,
                'tx_hash' => $row['txids'] === null ? null : explode(',', $row['txids'])[0],
                'rate' => $row['rate_cents'] === null ? null : self::amount((int) $row['rate_cents']),
                'paid_btc' => $row['paid_satoshis'] === null ? null : self::btc((int) $row['paid_satoshis']), 'own' => (int) $row['session_id'] === $sessionId];
        }
        return $items;
    }

    /** Metodi di incasso disponibili per l'apertura propria della sessione (tasti del POS). @return array{tts:bool, btc:bool, btc_currency:?string} */
    public static function methods(PosOperatorSessions $session, string $openingId, ?PosInvoiceGateway $gateway = null): array
    {
        $opening = TerminalOpenings::requireActive($session, $openingId);
        $db = Yii::$app->db;
        $gateway ??= BtcpayPosGateway::fromEnv();
        $tts = $db->createCommand('SELECT 1 FROM {{%pos_terminals}} t JOIN {{%store_tts_wallets}} w ON w.store_id=t.store_id AND w.merchant_id=t.merchant_id WHERE t.id=:t AND t.merchant_id=:m',
            [':t' => (int) $opening['terminal_id'], ':m' => (int) $opening['merchant_id']])->queryScalar() !== false && \app\components\pos\tts\TtsChain::fromEnv() !== null;
        $target = $gateway === null ? null : self::target($db, (int) $opening['terminal_id'], (int) $opening['merchant_id'], $gateway->instance());
        return ['tts' => $tts, 'btc' => $target !== null, 'btc_currency' => $target['currency'] ?? null];
    }

    /**
     * Webhook firmato dello store (già verificato dal chiamante) usato solo come avviso: dal webhook si prende l'ID della
     * fattura, mai lo stato o gli importi. Il pagamento creato da Napay per quello stesso negozio viene riletto dallo stato
     * pubblico della fattura su BTCPay (stato, BTC ricevuti, conferme); BTCPay non raggiungibile = invariato (polling e
     * comando periodico lo riprendono). @return bool true se lo stato del pagamento è cambiato
     */
    public static function applyEvent(string $instance, int $storeId, string $eventType, ?string $invoiceId, ?PosInvoiceGateway $gateway = null): bool
    {
        if (!in_array($eventType, self::EVENTS, true) || $invoiceId === null) return false;
        $payment = Yii::$app->db->createCommand('SELECT * FROM ' . self::TABLE . ' WHERE instance=:i AND invoice_id=:v', [':i' => $instance, ':v' => $invoiceId])->queryOne();
        if ($payment === false) return false;
        if ((int) $payment['store_id'] !== $storeId) {
            Log::security(sprintf('Pagamento BTC %s: evento di un altro negozio ignorato', $payment['public_id']));
            return false;
        }
        $gateway ??= BtcpayPosGateway::fromEnv();
        if ($gateway === null || !hash_equals($gateway->instance(), $instance)) return false;
        $before = $payment['state'];
        if (in_array($before, ['pending', 'processing'], true)) {
            try {
                $after = self::refresh($payment, $gateway);
            } catch (\Throwable $error) {
                return false;
            }
            return $after['state'] !== $before;
        }
        // già confermato: aggiorna solo BTC ricevuti e conferme (stati finali non tornano mai indietro)
        if ($before === 'settled') self::capture($payment, $gateway);
        return false;
    }

    /** BTC ricevuti, conferme, ordine e cambio dallo stato pubblico, senza cambiare lo stato del pagamento. */
    private static function capture(array $payment, PosInvoiceGateway $gateway): void
    {
        if ($payment['invoice_id'] === null || $payment['amount_satoshis'] === null) return;
        try {
            $status = $gateway->status($payment['invoice_id']);
        } catch (\Throwable $error) {
            return;
        }
        // solo se BTCPay conferma lo stesso stato: una lettura discordante non tocca un pagamento chiuso
        if ($status === null || !self::matches($payment, $payment['invoice_id'], $status) || (self::STATUS[$status['status']] ?? null) !== $payment['state']) return;
        $details = self::details($payment, $status, (int) $payment['amount_satoshis']);
        self::move($payment, $payment['state'], ['confirmations' => max((int) $payment['confirmations'], $status['confirmations'])] + $details
            + self::txids($payment, $gateway, $details['paid_satoshis'] ?? null));
    }

    /**
     * Transazioni dalla ricevuta pubblica di BTCPay: lette la prima volta che il pagamento è ricevuto e di nuovo solo se
     * arrivano altri BTC. Lettura non riuscita = nessun cambio. @return array{txids?:string}
     */
    private static function txids(array $payment, ?PosInvoiceGateway $gateway, ?int $paid): array
    {
        if ($gateway === null || $payment['invoice_id'] === null) return [];
        $grew = $paid !== null && $paid > (int) ($payment['paid_satoshis'] ?? 0);
        if ($payment['txids'] !== null && !$grew) return [];
        try {
            $list = $gateway->transactions($payment['invoice_id']);
        } catch (\Throwable $error) {
            return [];
        }
        if ($list === null || $list === []) return [];
        foreach ($list as $txid) if (!is_string($txid) || preg_match('/^[0-9a-f]{64}$/D', $txid) !== 1) return [];
        return ['txids' => implode(',', array_slice($list, 0, 10))];
    }

    /**
     * Comando one-shot: creazioni rimaste senza esito oltre la scadenza chiuse come fallite; pagamenti in attesa o
     * ricevuti oltre la scadenza riletti dal server BTCPay. @return array{checked:int, settled:int, closed:int}
     */
    public static function sweep(int $limit = 100, ?PosInvoiceGateway $gateway = null): array
    {
        $gateway ??= BtcpayPosGateway::fromEnv();
        $rows = Yii::$app->db->createCommand('SELECT * FROM ' . self::TABLE . " WHERE state IN ('creating','reconcile_required','pending','processing') AND expires_at < :now ORDER BY expires_at, id LIMIT " . max(1, min(1000, $limit)),
            [':now' => time()])->queryAll();
        $result = ['checked' => 0, 'settled' => 0, 'closed' => 0];
        foreach ($rows as $row) {
            ++$result['checked'];
            if (in_array($row['state'], ['creating', 'reconcile_required'], true)) {
                // nessuna fattura registrata: il cliente non ha mai visto un QR; la fattura eventuale scade su BTCPay
                if (self::move($row, 'failed', ['last_error_code' => 'invoice_outcome_unknown', 'closed_at' => time()])) ++$result['closed'];
                continue;
            }
            if ($gateway === null) continue;
            $after = self::refresh($row, $gateway);
            if ($after['state'] === 'settled' && $row['state'] !== 'settled') ++$result['settled'];
            if (in_array($after['state'], ['expired', 'invalid'], true)) ++$result['closed'];
        }
        return $result;
    }

    /**
     * Negozio con wallet BTC (xpub, non v1), store e app POS del terminale collegati sulla stessa istanza, valuta ammessa.
     * @return array{remote_store_id:string, remote_app_id:string, currency:string}|null
     */
    private static function target(Connection $db, int $terminalId, int $merchantId, string $instance): ?array
    {
        $row = $db->createCommand('SELECT s.remote_store_id, p.remote_app_id, ss.defaultCurrency AS currency, ss.derivationScheme AS scheme, ss.bps_storeid AS legacy
            FROM {{%pos_terminals}} t
            JOIN {{%stores}} st ON st.id=t.store_id AND st.merchant_id=t.merchant_id AND st.archived_at IS NULL
            JOIN {{%storesettings}} ss ON ss.store_id=t.store_id
            JOIN {{%btcpay_store_links}} s ON s.store_id=t.store_id AND s.merchant_id=t.merchant_id AND s.instance=:i AND s.state=\'synced\'
            JOIN {{%btcpay_pos_links}} p ON p.terminal_id=t.id AND p.store_id=t.store_id AND p.merchant_id=t.merchant_id AND p.instance=:i AND p.state=\'synced\'
            WHERE t.id=:t AND t.merchant_id=:m', [':t' => $terminalId, ':m' => $merchantId, ':i' => $instance])->queryOne();
        if ($row === false || !is_string($row['remote_store_id']) || !is_string($row['remote_app_id']) || !in_array($row['currency'], self::CURRENCIES, true)
            || !is_string($row['scheme']) || trim($row['scheme']) === '' || !empty($row['legacy'])) return null;
        return ['remote_store_id' => $row['remote_store_id'], 'remote_app_id' => $row['remote_app_id'], 'currency' => $row['currency']];
    }

    /** Fattura creata: verificata sullo stato pubblico prima di registrarla. Stato non leggibile ora = registrata senza indirizzo. */
    private static function accept(array $payment, string $invoiceId, PosInvoiceGateway $gateway): void
    {
        $status = $gateway->status($invoiceId);
        if ($status !== null && !self::matches($payment, $invoiceId, $status)) {
            Log::security(sprintf('Pagamento BTC %s: fattura %s non corrispondente alla richiesta', $payment['public_id'], $invoiceId));
            self::move($payment, 'invalid', ['invoice_id' => $invoiceId, 'last_error_code' => 'invoice_mismatch', 'closed_at' => time()]);
            return;
        }
        $changes = ['invoice_id' => $invoiceId];
        if ($status !== null) $changes += self::address($status);
        if (isset($changes['amount_satoshis'])) $changes += self::details($payment, $status, $changes['amount_satoshis']);
        if ($status !== null && $status['expires_in'] !== null) $changes['expires_at'] = max((int) $payment['created_at'] + 1, time() + $status['expires_in']);
        if (!self::move($payment, 'pending', $changes)) return;
        // stato pubblico già oltre "nuova" (pagamento rapidissimo): stesso percorso delle letture successive
        if ($status !== null && (self::STATUS[$status['status']] ?? 'pending') !== 'pending') self::refresh(self::row((int) $payment['id']), null, $status);
    }

    /** Stato pubblico da BTCPay applicato a un pagamento con fattura (in attesa o ricevuto). */
    private static function refresh(array $payment, ?PosInvoiceGateway $gateway, ?array $status = null): array
    {
        if (!in_array($payment['state'], ['pending', 'processing'], true) || $payment['invoice_id'] === null) return $payment;
        $status ??= $gateway?->status($payment['invoice_id']);
        if ($status === null) return $payment;
        if (!self::matches($payment, $payment['invoice_id'], $status)) {
            Log::security(sprintf('Pagamento BTC %s: stato della fattura non corrispondente', $payment['public_id']));
            return $payment;
        }
        $state = self::STATUS[$status['status']] ?? null;
        $extra = ['confirmations' => $status['confirmations']];
        if ($payment['btc_address'] === null) $extra += self::address($status);
        $total = $extra['amount_satoshis'] ?? ($payment['amount_satoshis'] === null ? null : (int) $payment['amount_satoshis']);
        if ($total !== null) $extra += self::details($payment, $status, $total);
        if (in_array($state ?? $payment['state'], ['processing', 'settled'], true)) $extra += self::txids($payment, $gateway, $extra['paid_satoshis'] ?? null);
        if ($state === null || $state === $payment['state']) {
            self::move($payment, $payment['state'], $extra);
        } else {
            self::advance($payment, $state, $extra);
        }
        return self::row((int) $payment['id']);
    }

    private static function matches(array $payment, string $invoiceId, array $status): bool
    {
        return $status['invoice_id'] === $invoiceId && $status['order_id'] === $payment['public_id'] && $status['store_id'] === $payment['remote_store_id']
            && $status['currency'] === $payment['currency'] && $status['fiat_digits'] === (string) (int) $payment['amount_cents'];
    }

    /** @return array{btc_address?:string, amount_satoshis?:int} indirizzo e totale richiesto (ancora dovuto + già ricevuto) solo in coppia */
    private static function address(array $status): array
    {
        if ($status['address'] === null || $status['due_satoshis'] === null) return [];
        return ['btc_address' => $status['address'], 'amount_satoshis' => $status['due_satoshis'] + max(0, (int) ($status['paid_satoshis'] ?? 0))];
    }

    /**
     * SIP-033 passo 5: BTC ricevuti (sempre aggiornati), importo dell'ordine e cambio (una volta, poi fissi). Il cambio di BTCPay
     * è accettato solo se coerente con euro e satoshi dell'ordine (scarto massimo 0,2% oltre l'arrotondamento al satoshi).
     */
    private static function details(array $payment, array $status, int $total): array
    {
        $changes = [];
        $paid = $status['paid_satoshis'] ?? null;
        // i BTC ricevuti non diminuiscono mai (lettura parziale o discordante = valore precedente)
        if (is_int($paid) && $paid >= 0 && $paid <= 2100000000000000 && ($payment['paid_satoshis'] === null || $paid >= (int) $payment['paid_satoshis'])) $changes['paid_satoshis'] = $paid;
        $order = $status['order_satoshis'] ?? null;
        if ($payment['order_satoshis'] !== null || !is_int($order) || $order < 1 || $order > $total) return $changes;
        $changes['order_satoshis'] = $order;
        $rate = $status['rate_cents'] ?? null;
        $fiat = (int) $payment['amount_cents'] * 100000000;
        if (is_int($rate) && $rate >= 1 && $rate <= 10000000000000 && 1000 * abs($rate * $order - $fiat) <= 2 * $fiat + 1000 * $rate) $changes['rate_cents'] = $rate;
        return $changes;
    }

    /** Transizione in avanti (NEXT) con timestamp coerenti con i vincoli dello schema. */
    private static function advance(array $payment, string $state, ?array $extra): bool
    {
        if (!in_array($state, self::NEXT[$payment['state']] ?? [], true)) return false;
        $now = time();
        $changes = $extra ?? [];
        if ($state === 'processing') $changes['processing_at'] = $now;
        if ($state === 'settled') $changes['settled_at'] = $now;
        if (in_array($state, ['expired', 'invalid'], true)) $changes['closed_at'] = $now;
        return self::move($payment, $state, $changes);
    }

    /** CAS su stato e versione: un aggiornamento concorrente vince, questo non scrive. */
    private static function move(array $payment, string $state, array $changes): bool
    {
        $now = max(time(), (int) $payment['updated_at']);
        foreach (['processing_at', 'settled_at', 'closed_at'] as $field) if (isset($changes[$field])) $changes[$field] = max((int) $changes[$field], (int) $payment['created_at']);
        $changes += ['state' => $state, 'updated_at' => $now, 'version' => (int) $payment['version'] + 1];
        foreach (['processing_at', 'settled_at', 'closed_at'] as $field) if (isset($changes[$field])) $changes['updated_at'] = max($changes['updated_at'], $changes[$field]);
        return Yii::$app->db->createCommand()->update(self::TABLE, $changes, ['id' => (int) $payment['id'], 'state' => $payment['state'], 'version' => (int) $payment['version']])->execute() === 1;
    }

    private static function replay(Connection $db, PosOperatorSessions $session, string $key, string $request, PosInvoiceGateway $gateway): ?array
    {
        $row = $db->createCommand('SELECT * FROM ' . self::TABLE . ' WHERE session_id=:s AND idempotency_sha256=:k', [':s' => (int) $session->id, ':k' => $key])->queryOne();
        if ($row === false) return null;
        if (!hash_equals($row['request_sha256'], $request)) throw new PaymentException(PaymentException::IDEMPOTENCY);
        return self::publicPayment($row, self::openingPublicId((int) $row['opening_id']), $gateway);
    }

    private static function own(PosOperatorSessions $session, string $paymentId): array
    {
        if (preg_match(self::UUID, $paymentId) !== 1) throw new PaymentException(PaymentException::NOT_FOUND);
        $payment = Yii::$app->db->createCommand('SELECT * FROM ' . self::TABLE . ' WHERE public_id=:p', [':p' => $paymentId])->queryOne();
        if ($payment === false || (int) $payment['session_id'] !== (int) $session->id) throw new PaymentException(PaymentException::NOT_FOUND);
        return $payment;
    }

    private static function transaction(Connection $db, callable $work)
    {
        if ($db->getTransaction() !== null) throw new \LogicException('caller_transaction_open');
        $transaction = $db->beginTransaction(Transaction::READ_COMMITTED);
        try {
            $result = $work();
            $transaction->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($transaction->isActive) $transaction->rollBack();
            throw $error;
        }
    }

    private static function row(int $id): array
    {
        return Yii::$app->db->createCommand('SELECT * FROM ' . self::TABLE . ' WHERE id=:id', [':id' => $id])->queryOne();
    }

    private static function openingPublicId(int $id): string
    {
        return (string) Yii::$app->db->createCommand('SELECT public_id FROM {{%pos_terminal_openings}} WHERE id=:id', [':id' => $id])->queryScalar();
    }

    /**
     * Proiezione pubblica: mai sessione, operatore, ID interni o ID remoti dello store/app. QR = URI BIP21 costruito da
     * indirizzo e importo salvati; checkout_url = ripiego D2 (pagina di pagamento di BTCPay).
     */
    private static function publicPayment(array $row, string $openingId, ?PosInvoiceGateway $gateway): array
    {
        $btc = $row['amount_satoshis'] === null ? null : self::btc((int) $row['amount_satoshis']);
        $qr = $btc === null || $row['btc_address'] === null ? null : 'bitcoin:' . $row['btc_address'] . '?amount=' . rtrim(rtrim($btc, '0'), '.');
        $open = in_array($row['state'], ['pending', 'processing'], true);
        return ['id' => $row['public_id'], 'opening_id' => $openingId, 'method' => 'btc', 'amount' => self::amount((int) $row['amount_cents']), 'currency' => $row['currency'],
            'state' => $row['state'], 'expires_at' => (int) $row['expires_at'], 'btc_address' => $row['btc_address'], 'amount_btc' => $btc, 'qr' => $open ? $qr : null,
            'checkout_url' => $open && $row['invoice_id'] !== null && $gateway !== null ? $gateway->checkoutUrl($row['invoice_id']) : null,
            'confirmations' => (int) $row['confirmations'],
            // SIP-033 passo 5: cambio (valuta per 1 BTC), ordine senza fee, fee di rete aggiunta da BTCPay, BTC ricevuti
            'rate' => $row['rate_cents'] === null ? null : self::amount((int) $row['rate_cents']),
            'order_btc' => $row['order_satoshis'] === null ? null : self::btc((int) $row['order_satoshis']),
            'network_fee_btc' => $row['order_satoshis'] === null || $row['amount_satoshis'] === null ? null : self::btc((int) $row['amount_satoshis'] - (int) $row['order_satoshis']),
            'paid_btc' => $row['paid_satoshis'] === null ? null : self::btc((int) $row['paid_satoshis']),
            'txids' => $row['txids'] === null ? [] : explode(',', $row['txids']),
            'processing_at' => $row['processing_at'] === null ? null : (int) $row['processing_at'], 'settled_at' => $row['settled_at'] === null ? null : (int) $row['settled_at'],
            'error' => $row['state'] === 'failed' || $row['state'] === 'invalid' || $row['state'] === 'reconcile_required' ? $row['last_error_code'] : null];
    }

    private static function amount(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function btc(int $satoshis): string
    {
        return intdiv($satoshis, 100000000) . '.' . str_pad((string) ($satoshis % 100000000), 8, '0', STR_PAD_LEFT);
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
