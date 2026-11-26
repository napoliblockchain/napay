<?php

namespace app\components\legacy;

use PDO;
use app\components\db\V2Bootstrap as Db;

/**
 * SIP-030 passo 5c: storico POS v1 negli incassi v2 (stesse tabelle dei pagamenti del POS v2, nessun archivio separato),
 * dentro la transazione di LegacyImporter, dopo merchant, negozi e casse (legacy_pos_terminals).
 * - BTC (np_transactions) -> pos_btc_payments; TTS (np_pos_invoices) -> pos_tts_payments. Solo pagamenti chiusi, senza
 *   apertura, sessione, utente né app POS v2 (vincoli `*-origin`). Mai token, descrizioni o altri campi v1 non mappati.
 * - Stati: complete/confirmed/paid -> settled (TTS: paid), new/expired -> expired, invalid -> invalid.
 * - Importi interi da quelli v1 (float): centesimi e satoshi arrotondati; cambio in centesimi per 1 BTC / 1 TTS.
 * - Tempi: creazione e scadenza v1; conferma = ultimo controllo v1 (current_tempo), chiusura = scadenza.
 * - Chiave naturale: fattura BTCPay (istanza + ID) per i BTC, riferimento derivato dall'ID v1 per i TTS; import ripetibile,
 *   mai riscritto. Riga non pagata non valida per la v2 (importo fuori limiti, fattura malformata) = saltata e contata;
 *   riga pagata non importabile = import interrotto (mai un incasso perso). POS v1 senza cassa = saltata e contata.
 */
final class LegacyPaymentImport
{
    private const BTC_STATES = ['complete' => 'settled', 'confirmed' => 'settled', 'paid' => 'settled', 'new' => 'expired', 'expired' => 'expired', 'invalid' => 'invalid'];
    private const TTS_STATES = ['complete' => 'paid', 'paid' => 'paid', 'new' => 'expired', 'expired' => 'expired', 'invalid' => 'expired'];
    private const MAX_CENTS = 100000;
    /** Istanza BTCPay delle fatture v1 se il server non è configurato all'import (righe chiuse: nessun uso operativo). */
    private const UNKNOWN_INSTANCE = 'napay-v1-btcpay';

    public static function import(PDO $source, PDO $target, int $now): array
    {
        $report = ['btc' => 0, 'btc_settled' => 0, 'btc_skipped_terminal' => 0, 'btc_skipped_invalid' => 0, 'btc_instance' => 'configured',
            'tts' => 0, 'tts_paid' => 0, 'tts_skipped_terminal' => 0, 'tts_skipped_invalid' => 0];
        $terminals = self::terminals($target);
        $instance = self::instance();
        if ($instance === null) { $instance = hash('sha256', self::UNKNOWN_INSTANCE); $report['btc_instance'] = 'unknown'; }

        foreach (Db::rows($source, 'SELECT id_transaction, id_pos, status, btc_price, btc_due, price, currency, id_invoice_bps, invoice_timestamp,'
            . ' expiration_timestamp, current_tempo, btc_paid, rate, bitcoin_address FROM np_transactions ORDER BY id_transaction') as $row) {
            $terminal = $terminals[(int) $row['id_pos']] ?? null;
            $state = self::BTC_STATES[strtolower(trim((string) $row['status']))] ?? null;
            if ($terminal === null) { $report['btc_skipped_terminal']++; continue; }
            $fields = $state === null ? null : self::btc($row, $terminal, $state, $instance, $now);
            if ($fields === null) {
                if ($state === 'settled' || $state === null) throw new LegacyImportException('Legacy BTC payment not importable: ' . (int) $row['id_transaction'] . '.');
                $report['btc_skipped_invalid']++;
                continue;
            }
            $report['btc']++;
            if ($state === 'settled') $report['btc_settled']++;
            if (Db::rows($target, 'SELECT id FROM pos_btc_payments WHERE instance=? AND invoice_id=?', [$instance, $fields['invoice_id']]) !== []) continue;
            self::insert($target, 'pos_btc_payments', $fields);
        }

        foreach (Db::rows($source, 'SELECT id_token, status, token_price, fiat_price, currency, id_pos, invoice_timestamp, expiration_timestamp, rate,'
            . ' to_address, blocknumber, txhash FROM np_pos_invoices ORDER BY id_token') as $row) {
            $pos = trim((string) $row['id_pos']);
            $terminal = ctype_digit($pos) ? ($terminals[(int) $pos] ?? null) : null;
            $state = self::TTS_STATES[strtolower(trim((string) $row['status']))] ?? null;
            if ($terminal === null) { $report['tts_skipped_terminal']++; continue; }
            $fields = $state === null ? null : self::tts($row, $terminal, $state);
            if ($fields === null) {
                if ($state === 'paid' || $state === null) throw new LegacyImportException('Legacy TTS payment not importable: ' . (int) $row['id_token'] . '.');
                $report['tts_skipped_invalid']++;
                continue;
            }
            $report['tts']++;
            if ($state === 'paid') $report['tts_paid']++;
            if (Db::rows($target, 'SELECT id FROM pos_tts_payments WHERE reference=?', [$fields['reference']]) !== []) continue;
            self::insert($target, 'pos_tts_payments', $fields);
        }
        return $report;
    }

    /** Ogni pagamento v1 pagato su una cassa importata è negli incassi v2. */
    public static function reconcile(PDO $source, PDO $target): void
    {
        $terminals = self::terminals($target);
        $instance = self::instance() ?? hash('sha256', self::UNKNOWN_INSTANCE);
        foreach (Db::rows($source, 'SELECT id_pos, status, id_invoice_bps FROM np_transactions') as $row) {
            if (!isset($terminals[(int) $row['id_pos']]) || (self::BTC_STATES[strtolower(trim((string) $row['status']))] ?? null) !== 'settled') continue;
            if (Db::rows($target, "SELECT id FROM pos_btc_payments WHERE instance=? AND invoice_id=? AND state='settled' AND session_id IS NULL", [$instance, trim((string) $row['id_invoice_bps'])]) === []) {
                throw new LegacyImportException('Legacy reconciliation failed: BTC payments.');
            }
        }
        foreach (Db::rows($source, 'SELECT id_token, id_pos, status FROM np_pos_invoices') as $row) {
            $pos = trim((string) $row['id_pos']);
            if (!ctype_digit($pos) || !isset($terminals[(int) $pos]) || (self::TTS_STATES[strtolower(trim((string) $row['status']))] ?? null) !== 'paid') continue;
            if (Db::rows($target, "SELECT id FROM pos_tts_payments WHERE reference=? AND state='paid'", [self::reference((int) $row['id_token'])]) === []) {
                throw new LegacyImportException('Legacy reconciliation failed: TTS payments.');
            }
        }
    }

    /** @return array<int, array{id:int, store:int, merchant:int, remote:?string}> POS v1 -> cassa v2 con lo store BTCPay del negozio */
    private static function terminals(PDO $target): array
    {
        $map = [];
        foreach (Db::rows($target, 'SELECT l.legacy_id, t.id, t.store_id, t.merchant_id, ss.bps_storeid FROM legacy_pos_terminals l JOIN pos_terminals t ON t.id=l.terminal_id'
            . ' LEFT JOIN storesettings ss ON ss.store_id=t.store_id') as $row) {
            $map[(int) $row['legacy_id']] = ['id' => (int) $row['id'], 'store' => (int) $row['store_id'], 'merchant' => (int) $row['merchant_id'],
                'remote' => is_string($row['bps_storeid']) && $row['bps_storeid'] !== '' ? trim($row['bps_storeid']) : null];
        }
        return $map;
    }

    private static function instance(): ?string
    {
        $value = trim((string) getenv('BTCPAY_PROVISION_INSTANCE'));
        return preg_match('/^[0-9a-f]{64}$/D', $value) === 1 ? $value : null;
    }

    private static function btc(array $row, array $terminal, string $state, string $instance, int $now): ?array
    {
        $invoice = trim((string) $row['id_invoice_bps']);
        $cents = (int) round((float) $row['price'] * 100);
        $currency = strtoupper(trim((string) $row['currency']));
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $invoice) !== 1 || $cents < 1 || $cents > self::MAX_CENTS || preg_match('/^[A-Z]{3}$/D', $currency) !== 1
            || $terminal['remote'] === null || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $terminal['remote']) !== 1) return null;
        [$created, $expires, $seen] = self::times($row['invoice_timestamp'], $row['expiration_timestamp'], $row['current_tempo'], $now);
        $address = trim((string) $row['bitcoin_address']);
        $due = self::satoshis($row['btc_due']);
        $pair = preg_match('/^[A-Za-z0-9]{14,90}$/D', $address) === 1 && $due >= 1;
        $order = self::satoshis($row['btc_price']);
        $paid = self::satoshis($row['btc_paid']);
        $rate = (int) round((float) $row['rate'] * 100);
        if ($state === 'settled' && $paid < 1) return null;
        $closed = $state === 'settled' ? null : ($state === 'invalid' ? $seen : $expires);
        $id = (int) $row['id_transaction'];
        return ['public_id' => self::uuid(), 'merchant_id' => $terminal['merchant'], 'store_id' => $terminal['store'], 'terminal_id' => $terminal['id'],
            'opening_id' => null, 'session_id' => null, 'user_id' => null, 'instance' => $instance, 'remote_store_id' => $terminal['remote'], 'remote_app_id' => null,
            'invoice_id' => $invoice, 'amount_cents' => $cents, 'currency' => $currency, 'state' => $state,
            'btc_address' => $pair ? $address : null, 'amount_satoshis' => $pair ? $due : null, 'confirmations' => 0,
            'creation_started_at' => $created, 'processing_at' => null, 'settled_at' => $state === 'settled' ? $seen : null, 'closed_at' => $closed,
            'idempotency_sha256' => hash('sha256', 'napay-v1-btc|' . $id), 'request_sha256' => hash('sha256', 'napay-v1-btc-request|' . $id),
            'last_error_code' => null, 'version' => 0, 'created_at' => $created, 'updated_at' => max($seen, $expires), 'expires_at' => $expires,
            'rate_cents' => $rate >= 1 && $rate <= 10000000000000 ? $rate : null,
            'order_satoshis' => $pair && $order >= 1 && $order <= $due ? $order : null, 'paid_satoshis' => $paid >= 0 && $paid <= 2100000000000000 ? $paid : null];
    }

    private static function tts(array $row, array $terminal, string $state): ?array
    {
        $amount = (int) round((float) $row['token_price'] * 100);
        $fiat = (int) round((float) $row['fiat_price'] * 100);
        $rate = (int) round((float) $row['rate'] * 100);
        $to = trim((string) $row['to_address']);
        $tx = strtolower(trim((string) $row['txhash']));
        if ($amount < 1 || $amount > self::MAX_CENTS || $fiat < 1 || $fiat > self::MAX_CENTS || $rate < 1 || $rate > 100000000
            || strtoupper(trim((string) $row['currency'])) !== 'EUR' || preg_match('/^0x[0-9a-fA-F]{40}$/D', $to) !== 1) return null;
        if ($state === 'paid' && preg_match('/^0x[0-9a-f]{64}$/D', $tx) !== 1) return null;
        [$created, $expires] = self::times($row['invoice_timestamp'], $row['expiration_timestamp'], null, 0);
        $block = max(0, (int) round((float) $row['blocknumber']));
        $id = (int) $row['id_token'];
        return ['public_id' => self::uuid(), 'merchant_id' => $terminal['merchant'], 'store_id' => $terminal['store'], 'terminal_id' => $terminal['id'],
            'opening_id' => null, 'session_id' => null, 'user_id' => null, 'amount_cents' => $amount, 'to_address' => $to, 'reference' => self::reference($id),
            'start_block' => $state === 'paid' ? 0 : $block, 'state' => $state, 'tx_hash' => $state === 'paid' ? $tx : null,
            'paid_block' => $state === 'paid' ? $block : null, 'paid_at' => $state === 'paid' ? $created : null, 'closed_at' => $state === 'paid' ? null : $expires,
            'idempotency_sha256' => hash('sha256', 'napay-v1-tts|' . $id), 'request_sha256' => hash('sha256', 'napay-v1-tts-request|' . $id),
            'created_at' => $created, 'expires_at' => $expires, 'fiat_cents' => $fiat, 'rate_cents' => $rate];
    }

    /** [creazione, scadenza > creazione, ultimo controllo tra creazione e adesso]. */
    private static function times($created, $expires, $seen, int $now): array
    {
        $created = max(0, (int) $created);
        $expires = max($created + 1, (int) $expires);
        $seen = $seen === null ? $created : min(max($created, (int) $seen), max($created, $now ?: (int) $seen));
        return [$created, $expires, $seen];
    }

    private static function satoshis($btc): int
    {
        return (int) round((float) $btc * 100000000);
    }

    /** Riferimento TTS stabile dall'ID v1 (32 esadecimali, come quelli del POS v2). */
    private static function reference(int $id): string
    {
        return substr(hash('sha256', 'napay-v1-tts-reference|' . $id), 0, 32);
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private static function insert(PDO $target, string $table, array $fields): void
    {
        $columns = implode(',', array_map(static fn($c) => "`$c`", array_keys($fields)));
        $query = $target->prepare("INSERT INTO $table ($columns) VALUES (" . implode(',', array_fill(0, count($fields), '?')) . ')');
        $i = 0;
        foreach ($fields as $value) $query->bindValue(++$i, $value, $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR));
        $query->execute();
        $query->closeCursor();
    }
}
