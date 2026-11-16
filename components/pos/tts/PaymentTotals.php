<?php

namespace app\components\pos\tts;

use Yii;
use app\components\pos\management\Scope;
use yii\db\Query;

/**
 * Totali degli incassi TTS per stato (pagina Incassi e dashboard): conteggio e somma in centesimi.
 * Riceve la query già filtrata e limitata allo scope del chiamante.
 */
final class PaymentTotals
{
    public const STATES = ['paid', 'pending', 'expired', 'cancelled'];

    /**
     * Uno per stato, anche a zero, più "lost" (SIP-031): pagati su un wallet segnalato perso e non ritrovato,
     * tolti da "paid" (incassato) e contati a parte come fondi persi.
     * @return array<string, array{count:int, cents:int}>
     */
    public static function byState(Query $filtered): array
    {
        $rows = (clone $filtered)->select(['state' => 'p.state', 'lost' => LostWallets::lostCondition('p'), 'n' => 'COUNT(*)', 'cents' => 'COALESCE(SUM(p.amount_cents), 0)'])
            ->orderBy(null)->groupBy(['p.state', 'lost'])->all();
        $totals = array_fill_keys([...self::STATES, 'lost'], ['count' => 0, 'cents' => 0]);
        foreach ($rows as $row) {
            $key = $row['state'] === 'paid' && (int) $row['lost'] === 1 ? 'lost' : $row['state'];
            if (!isset($totals[$key])) continue;
            $totals[$key]['count'] += (int) $row['n'];
            $totals[$key]['cents'] += (int) $row['cents'];
        }
        return $totals;
    }

    /** Incassi nello scope (gestore: il suo merchant; admin: tutti), dal momento indicato. */
    public static function scoped(Scope $scope, ?int $since = null): Query
    {
        $query = (new Query())->from(['p' => '{{%pos_tts_payments}}']);
        $scope->apply($query, 'p.merchant_id');
        if ($since !== null) $query->andWhere(['>=', 'p.created_at', $since]);
        return $query;
    }

    /**
     * Riepilogo per la dashboard: incassato oggi e nel mese (ora di Roma), richieste in attesa e, solo per il gestore,
     * il saldo reale dei wallet TTS dei suoi negozi letto dalla chain: somma degli indirizzi distinti
     * (null se nessuno è impostato o la chain non risponde).
     * @return array{today:int, month:int, pending:int, wallets:string[], balance:?int}
     */
    public static function dashboard(Scope $scope): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Rome'));
        $summary = [
            'today' => self::byState(self::scoped($scope, $now->setTime(0, 0)->getTimestamp()))['paid']['cents'],
            'month' => self::byState(self::scoped($scope, $now->modify('first day of this month')->setTime(0, 0)->getTimestamp()))['paid']['cents'],
            'pending' => self::byState(self::scoped($scope))['pending']['count'],
            'wallets' => [],
            'balance' => null,
        ];
        if ($scope->isAdmin()) return $summary;
        $summary['wallets'] = Yii::$app->db->createCommand('SELECT DISTINCT address FROM {{%store_tts_wallets}} WHERE merchant_id=:m ORDER BY address', [':m' => $scope->merchantId])->queryColumn();
        if ($summary['wallets'] === []) return $summary;
        try {
            $chain = TtsChain::fromEnv();
            if ($chain === null) return $summary;
            $total = '0';
            foreach ($summary['wallets'] as $wallet) $total = gmp_strval(gmp_add($total, $chain->balanceOf($wallet)));
            $summary['balance'] = strlen($total) < 16 ? (int) $total : null;
        } catch (\Throwable $e) {
            // chain non raggiungibile o non configurata: la dashboard resta disponibile senza saldo
            $summary['balance'] = null;
        }
        return $summary;
    }

    /** Importo in TTS con due decimali nel formato della lingua dell'app. */
    public static function format(int $cents): string
    {
        return Yii::$app->formatter->asDecimal($cents / 100, 2) . ' TTS';
    }
}
