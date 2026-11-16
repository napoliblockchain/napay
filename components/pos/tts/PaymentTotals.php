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

    /** @return array<string, array{count:int, cents:int}> uno per stato, anche a zero */
    public static function byState(Query $filtered): array
    {
        $rows = (clone $filtered)->select(['state' => 'p.state', 'n' => 'COUNT(*)', 'cents' => 'COALESCE(SUM(p.amount_cents), 0)'])
            ->orderBy(null)->groupBy('p.state')->all();
        $totals = array_fill_keys(self::STATES, ['count' => 0, 'cents' => 0]);
        foreach ($rows as $row) {
            if (isset($totals[$row['state']])) $totals[$row['state']] = ['count' => (int) $row['n'], 'cents' => (int) $row['cents']];
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
