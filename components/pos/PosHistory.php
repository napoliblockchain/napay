<?php
namespace app\components\pos;

use Yii;
use app\components\pos\btc\BtcPayments;
use app\components\pos\tts\TtsPayments;
use app\models\PosOperatorSessions;

/**
 * SIP-033 passo 5: storico del POS (TTS e BTC insieme) e totali di oggi, per il terminale dell'apertura propria della
 * sessione. Ultimi 50 pagamenti degli ultimi 30 giorni, più recenti prima; totali dalla mezzanotte (ora di Roma) dei soli
 * incassi riusciti: TTS pagati, BTC ricevuti o confermati (in valuta del negozio e in BTC). Mai QR, indirizzi o riferimenti.
 */
final class PosHistory
{
    /** amount/currency = quanto paga il cliente (TTS o valuta del negozio); fiat_amount = prezzo in valuta; rate = valuta per 1 TTS o 1 BTC */
    public const ITEM = ['id', 'method', 'amount', 'currency', 'fiat_amount', 'state', 'created_at', 'paid_at', 'tx_hash', 'rate', 'paid_btc', 'own'];

    /** @return array{payments: list<array>, totals: array} */
    public static function forOpening(PosOperatorSessions $session, string $openingId): array
    {
        // verifica l'apertura e chiude le richieste TTS in attesa già pagate o scadute (come prima)
        $tts = TtsPayments::history($session, $openingId)['payments'];
        $opening = TerminalOpenings::requireActive($session, $openingId);
        $terminal = (int) $opening['terminal_id'];
        $items = [];
        foreach ($tts as $item) $items[] = ['id' => $item['id'], 'method' => 'tts', 'amount' => $item['amount'], 'currency' => $item['currency'], 'fiat_amount' => $item['fiat_amount'],
            'state' => $item['state'], 'created_at' => $item['created_at'], 'paid_at' => $item['paid_at'], 'tx_hash' => $item['tx_hash'], 'rate' => $item['rate'], 'paid_btc' => null,
            'own' => $item['own']];
        foreach (BtcPayments::history($terminal, (int) $session->id, time() - TtsPayments::HISTORY_DAYS * 86400, TtsPayments::HISTORY_LIMIT) as $item) $items[] = $item;
        // ordinamento stabile (PHP 8): a parità di secondo resta l'ordine di ciascuna tabella (ID decrescente)
        usort($items, static fn(array $a, array $b): int => $b['created_at'] <=> $a['created_at']);
        return ['payments' => array_slice($items, 0, TtsPayments::HISTORY_LIMIT), 'totals' => self::totals($terminal)];
    }

    /** Totali di oggi del terminale: conteggio e importo per metodo (BTC per valuta del negozio, con i BTC ricevuti). */
    public static function totals(int $terminalId, ?int $now = null): array
    {
        $since = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new \DateTimeZone('Europe/Rome'))->setTime(0, 0)->getTimestamp();
        $db = Yii::$app->db;
        $tts = $db->createCommand("SELECT COUNT(*) AS n, COALESCE(SUM(amount_cents), 0) AS cents, COALESCE(SUM(fiat_cents), 0) AS fiat FROM {{%pos_tts_payments}} WHERE terminal_id=:t AND state='paid' AND paid_at >= :s",
            [':t' => $terminalId, ':s' => $since])->queryOne();
        $btc = [];
        foreach ($db->createCommand("SELECT currency, COUNT(*) AS n, SUM(amount_cents) AS cents, SUM(COALESCE(paid_satoshis, amount_satoshis, 0)) AS sats FROM {{%pos_btc_payments}}
            WHERE terminal_id=:t AND state IN ('processing','settled') AND COALESCE(processing_at, settled_at) >= :s GROUP BY currency ORDER BY currency",
            [':t' => $terminalId, ':s' => $since])->queryAll() as $row) {
            $btc[] = ['currency' => $row['currency'], 'count' => (int) $row['n'], 'amount' => self::amount((int) $row['cents']), 'btc' => self::btc((int) $row['sats'])];
        }
        return ['since' => $since, 'tts' => ['count' => (int) $tts['n'], 'amount' => self::amount((int) $tts['cents']), 'fiat_amount' => self::amount((int) $tts['fiat'])], 'btc' => $btc];
    }

    private static function amount(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function btc(int $satoshis): string
    {
        return intdiv($satoshis, 100000000) . '.' . str_pad((string) ($satoshis % 100000000), 8, '0', STR_PAD_LEFT);
    }
}
