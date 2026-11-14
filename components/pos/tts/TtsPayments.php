<?php
namespace app\components\pos\tts;

use Yii;
use app\components\Log;
use app\components\pos\OpeningException;
use app\components\pos\TerminalOpenings;
use app\models\PosOperatorSessions;
use yii\db\Connection;
use yii\db\IntegrityException;
use yii\db\Transaction;

/**
 * SIP-021 fase 3 (PAGAMENTO-TTS-CONTRATTO): richieste di pagamento TTS dei terminali POS.
 * Destinazione = wallet del merchant congelato alla creazione; abbinamento solo con evento Transfer del token
 * configurato, destinatario e importo esatti, riferimento accodato ai dati della chiamata `transfer` e blocco
 * non successivo alla scadenza. Verifica alla lettura dello stato e nel comando one-shot; nessun daemon.
 * Lock in creazione come aperture e C4: merchant, terminale, sessione, apertura.
 */
final class TtsPayments
{
    public const UUID = TerminalOpenings::UUID;
    public const MAX_CENTS = 100000;
    public const TTL = 900;
    public const MEMO_PREFIX = 'napay:';
    public const HISTORY_LIMIT = 50;
    public const HISTORY_DAYS = 30;
    /** verifiche sulla chain al massimo per lettura dello storico */
    private const HISTORY_SETTLE = 5;
    private const TABLE = '{{%pos_tts_payments}}';
    /** selettore di transfer(address,uint256) */
    private const TRANSFER_SELECTOR = 'a9059cbb';

    /**
     * Creazione con chiave di idempotenza (per sessione). Replay = stessa richiesta in qualunque stato.
     * @return array{0:int, 1:array} [201 nuova | 200 replay, richiesta pubblica]
     */
    public static function create(PosOperatorSessions $session, string $openingId, string $instanceId, string $generation, int $amountCents, string $idempotencyKey): array
    {
        if (preg_match(self::UUID, $idempotencyKey) !== 1 || $amountCents < 1 || $amountCents > self::MAX_CENTS) throw new \InvalidArgumentException('invalid_payment');
        $chain = TtsChain::fromEnv();
        if ($chain === null) throw new PaymentException(PaymentException::UNAVAILABLE);
        $db = Yii::$app->db;
        $key = hash('sha256', $idempotencyKey);
        $request = hash('sha256', $openingId . '|' . $amountCents);
        $replay = self::replay($db, $session, $key, $request);
        if ($replay !== null) return [200, $replay];
        $opening = TerminalOpenings::requireUsable($session, $openingId, $instanceId, $generation);
        $wallet = $db->createCommand('SELECT address FROM {{%merchant_tts_wallets}} WHERE merchant_id=:m', [':m' => (int) $opening['merchant_id']])->queryScalar();
        if ($wallet === false) throw new PaymentException(PaymentException::UNAVAILABLE);
        // blocco di partenza letto prima della transazione: nessuna chiamata di rete sotto lock
        $startBlock = $chain->blockNumber();
        return self::transaction($db, static function () use ($db, $session, $opening, $openingId, $instanceId, $generation, $amountCents, $key, $request, $startBlock) {
            $merchant = (int) $opening['merchant_id'];
            $db->createCommand('SELECT id FROM {{%merchants}} WHERE id=:id FOR UPDATE', [':id' => $merchant])->queryScalar();
            $terminal = $db->createCommand('SELECT id, store_id FROM {{%pos_terminals}} WHERE id=:id AND merchant_id=:m FOR UPDATE',
                [':id' => (int) $opening['terminal_id'], ':m' => $merchant])->queryOne();
            $db->createCommand('SELECT id FROM {{%pos_operator_sessions}} WHERE id=:id FOR UPDATE', [':id' => (int) $session->id])->queryScalar();
            $locked = $db->createCommand('SELECT * FROM {{%pos_terminal_openings}} WHERE id=:id FOR UPDATE', [':id' => (int) $opening['id']])->queryOne();
            $replay = self::replay($db, $session, $key, $request);
            if ($replay !== null) return [200, $replay];
            if ($terminal === false || $locked === false || $locked['state'] !== 'active' || $locked['instance_id'] !== $instanceId || (string) $locked['generation'] !== $generation) {
                throw new OpeningException(OpeningException::INVALID);
            }
            // scope riletto dopo i lock: grant, profilo e terminale ancora validi
            TerminalOpenings::requireUsable($session, $openingId, $instanceId, $generation);
            $wallet = $db->createCommand('SELECT address FROM {{%merchant_tts_wallets}} WHERE merchant_id=:m', [':m' => $merchant])->queryScalar();
            if ($wallet === false) throw new PaymentException(PaymentException::UNAVAILABLE);
            $now = time();
            $row = ['public_id' => self::uuid(), 'merchant_id' => $merchant, 'store_id' => (int) $terminal['store_id'], 'terminal_id' => (int) $terminal['id'],
                'opening_id' => (int) $locked['id'], 'session_id' => (int) $session->id, 'user_id' => (int) $session->user_id,
                'amount_cents' => $amountCents, 'to_address' => $wallet, 'reference' => bin2hex(random_bytes(16)), 'start_block' => $startBlock,
                'state' => 'pending', 'idempotency_sha256' => $key, 'request_sha256' => $request, 'created_at' => $now, 'expires_at' => $now + self::TTL];
            $db->createCommand()->insert(self::TABLE, $row)->execute();
            return [201, self::publicPayment($row + ['tx_hash' => null, 'paid_at' => null], $openingId)];
        });
    }

    /** Stato della richiesta propria della sessione; se in attesa, verifica sulla chain (errori di rete = stato invariato). */
    public static function view(PosOperatorSessions $session, string $paymentId): array
    {
        $payment = self::own($session, $paymentId);
        if ($payment['state'] === 'pending') $payment = self::settle($payment);
        return self::publicPayment($payment, self::openingPublicId((int) $payment['opening_id']));
    }

    /**
     * Storico del terminale dell'apertura propria della sessione: ultime richieste degli ultimi giorni, più recenti
     * prima. Le poche ancora in attesa vengono verificate (errori di chain = stato invariato). Mai QR o riferimento.
     * @return array{payments: list<array>}
     */
    public static function history(PosOperatorSessions $session, string $openingId): array
    {
        $opening = TerminalOpenings::requireActive($session, $openingId);
        $rows = Yii::$app->db->createCommand('SELECT * FROM ' . self::TABLE . ' WHERE terminal_id=:t AND created_at >= :since ORDER BY id DESC LIMIT ' . self::HISTORY_LIMIT,
            [':t' => (int) $opening['terminal_id'], ':since' => time() - self::HISTORY_DAYS * 86400])->queryAll();
        $settle = self::HISTORY_SETTLE;
        $payments = [];
        foreach ($rows as $row) {
            if ($row['state'] === 'pending' && $settle-- > 0) $row = self::settle($row);
            $payments[] = ['id' => $row['public_id'], 'amount' => self::amount((int) $row['amount_cents']), 'currency' => 'TTS', 'state' => $row['state'],
                'created_at' => (int) $row['created_at'], 'paid_at' => $row['paid_at'] === null ? null : (int) $row['paid_at'], 'tx_hash' => $row['tx_hash'],
                'own' => (int) $row['session_id'] === (int) $session->id];
        }
        return ['payments' => $payments];
    }

    /** Annullamento dalla stessa apertura, solo se ancora in attesa dopo l'ultima verifica sulla chain. */
    public static function cancel(PosOperatorSessions $session, string $paymentId, string $instanceId, string $generation): array
    {
        $payment = self::own($session, $paymentId);
        $opening = Yii::$app->db->createCommand('SELECT public_id, instance_id, generation FROM {{%pos_terminal_openings}} WHERE id=:id', [':id' => (int) $payment['opening_id']])->queryOne();
        if ($opening === false || !hash_equals($opening['instance_id'], $instanceId) || (string) $opening['generation'] !== $generation) throw new OpeningException(OpeningException::INVALID);
        if ($payment['state'] === 'pending') $payment = self::settle($payment, true);
        if ($payment['state'] === 'pending') {
            $now = time();
            Yii::$app->db->createCommand()->update(self::TABLE, ['state' => 'cancelled', 'closed_at' => $now], ['id' => (int) $payment['id'], 'state' => 'pending'])->execute();
            $payment = self::row((int) $payment['id']);
        }
        if ($payment['state'] !== 'cancelled') throw new PaymentException(PaymentException::NOT_PENDING);
        return self::publicPayment($payment, $opening['public_id']);
    }

    /**
     * Comando one-shot: verifica le richieste in attesa già scadute e chiude quelle non pagate.
     * @return array{checked:int, paid:int, expired:int}
     */
    public static function sweep(int $limit = 100): array
    {
        $rows = Yii::$app->db->createCommand('SELECT * FROM ' . self::TABLE . ' WHERE state=\'pending\' AND expires_at < :now ORDER BY expires_at, id LIMIT ' . max(1, min(1000, $limit)),
            [':now' => time()])->queryAll();
        $result = ['checked' => 0, 'paid' => 0, 'expired' => 0];
        foreach ($rows as $row) {
            $after = self::settle($row);
            ++$result['checked'];
            if ($after['state'] === 'paid') ++$result['paid'];
            if ($after['state'] === 'expired') ++$result['expired'];
        }
        return $result;
    }

    /**
     * Cerca il pagamento sulla chain e aggiorna lo stato con CAS (solo da `pending`). Chain non disponibile = invariato,
     * mai scadenza decisa senza una lettura completa. $strict: errori di chain propagati (annullamento).
     */
    private static function settle(array $payment, bool $strict = false): array
    {
        try {
            $chain = TtsChain::fromEnv();
            if ($chain === null) throw new TtsChainException();
            $match = self::find($chain, $payment);
        } catch (TtsChainException $error) {
            if ($strict) throw new PaymentException(PaymentException::UNAVAILABLE);
            return $payment;
        }
        $db = Yii::$app->db;
        if ($match !== null) {
            try {
                $db->createCommand()->update(self::TABLE, ['state' => 'paid', 'tx_hash' => $match['tx'], 'paid_block' => $match['block'], 'paid_at' => $match['time']],
                    ['id' => (int) $payment['id'], 'state' => 'pending'])->execute();
            } catch (IntegrityException $error) {
                // transazione già associata a un'altra richiesta: mai a due
                Log::security(sprintf('Pagamento TTS %s: transazione già usata da un\'altra richiesta', $payment['public_id']));
            }
        } elseif (time() > (int) $payment['expires_at']) {
            $db->createCommand()->update(self::TABLE, ['state' => 'expired', 'closed_at' => time()], ['id' => (int) $payment['id'], 'state' => 'pending'])->execute();
        }
        return self::row((int) $payment['id']);
    }

    /**
     * Transfer verso l'indirizzo congelato con il riferimento della richiesta. Importo diverso o blocco dopo la scadenza:
     * anomalia registrata per il gestore, nessun cambio di stato.
     * @return array{tx:string, block:int, time:int}|null
     */
    private static function find(TtsChain $chain, array $payment): ?array
    {
        $expires = (int) $payment['expires_at'];
        $times = [];
        $time = static function (int $block) use ($chain, &$times): int { return $times[$block] ??= $chain->blockTimestamp($block); };
        // oltre la scadenza servono solo le anomalie del primo tratto: stop al primo tratto che la supera
        $transfers = $chain->transfersTo($payment['to_address'], (int) $payment['start_block'], static fn(int $end) => $time($end) > $expires);
        $amount = str_pad(dechex((int) $payment['amount_cents']), 64, '0', STR_PAD_LEFT);
        $expected = self::TRANSFER_SELECTOR . TtsAddress::word($payment['to_address']) . $amount . $payment['reference'];
        foreach ($transfers as $transfer) {
            $input = $chain->transactionInput($transfer['tx']);
            if (!str_ends_with($input, $payment['reference']) || strlen($input) !== strlen($expected)) continue;
            if ($input !== $expected || $transfer['value'] !== $amount) {
                Log::security(sprintf('Pagamento TTS %s: transazione %s con riferimento ma importo o destinatario diversi', $payment['public_id'], $transfer['tx']));
                continue;
            }
            $minedAt = $time($transfer['block']);
            if ($minedAt > $expires) {
                Log::security(sprintf('Pagamento TTS %s: transazione %s arrivata dopo la scadenza', $payment['public_id'], $transfer['tx']));
                continue;
            }
            return ['tx' => $transfer['tx'], 'block' => $transfer['block'], 'time' => $minedAt];
        }
        return null;
    }

    private static function replay(Connection $db, PosOperatorSessions $session, string $key, string $request): ?array
    {
        $row = $db->createCommand('SELECT * FROM ' . self::TABLE . ' WHERE session_id=:s AND idempotency_sha256=:k', [':s' => (int) $session->id, ':k' => $key])->queryOne();
        if ($row === false) return null;
        if (!hash_equals($row['request_sha256'], $request)) throw new PaymentException(PaymentException::IDEMPOTENCY);
        return self::publicPayment($row, self::openingPublicId((int) $row['opening_id']));
    }

    private static function own(PosOperatorSessions $session, string $paymentId): array
    {
        if (preg_match(self::UUID, $paymentId) !== 1) throw new PaymentException(PaymentException::NOT_FOUND);
        $payment = Yii::$app->db->createCommand('SELECT * FROM ' . self::TABLE . ' WHERE public_id=:p', [':p' => $paymentId])->queryOne();
        // richiesta di un'altra sessione (anche dello stesso utente): non esiste per questo chiamante
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

    /** Proiezione pubblica: mai sessione, operatore o ID interni. QR nel formato letto dal wallet. */
    private static function publicPayment(array $row, string $openingId): array
    {
        $amount = self::amount((int) $row['amount_cents']);
        return ['id' => $row['public_id'], 'opening_id' => $openingId, 'amount' => $amount, 'currency' => 'TTS', 'state' => $row['state'],
            'to_address' => $row['to_address'], 'expires_at' => (int) $row['expires_at'],
            'qr' => $row['to_address'] . '&amount=' . $amount . '&message=' . self::MEMO_PREFIX . $row['reference'],
            'tx_hash' => $row['tx_hash'], 'paid_at' => $row['paid_at'] === null ? null : (int) $row['paid_at']];
    }

    private static function amount(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
