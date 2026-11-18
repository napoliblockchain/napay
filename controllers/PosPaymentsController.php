<?php

namespace app\controllers;

use Yii;
use app\components\pos\management\Scope;
use app\components\pos\tts\LostWallets;
use app\components\pos\tts\PaymentTotals;
use app\components\pos\tts\TtsPayments;
use app\models\Merchants;
use app\models\PosTerminals;
use app\models\Stores;
use app\models\Users;
use yii\data\ActiveDataProvider;
use yii\db\Expression;
use yii\db\Query;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Incassi dei POS (al posto della vecchia pagina Transazioni): gestore sul proprio merchant, admin su tutti (Scope di C4).
 * Sola lettura; mai riferimento del pagamento, sessione, chiavi o ID remoti BTCPay. SIP-033 passo 5: TTS e BTC insieme
 * (colonna e filtro Metodo); totali TTS per stato come prima, BTC per valuta con i BTC incassati.
 */
class PosPaymentsController extends Controller
{
    /** Stati del filtro: per i BTC "paid" = ricevuto o confermato, "cancelled" = non valido, non creato o esito sconosciuto. */
    public const STATES = ['pending', 'paid', 'expired', 'cancelled'];
    public const METHODS = ['tts', 'btc'];
    private const BTC_STATES = ['pending' => ['creating', 'pending'], 'paid' => ['processing', 'settled'], 'expired' => ['expired'],
        'cancelled' => ['invalid', 'failed', 'reconcile_required']];
    private const EXPORT_LIMIT = 10000;

    private Scope $scope;

    public function behaviors()
    {
        return [
            'verbs' => ['class' => VerbFilter::class, 'actions' => ['index' => ['GET'], 'view' => ['GET'], 'export' => ['GET']]],
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => ['@'], 'matchCallback' => fn($rule, $action) => Scope::current() !== null]],
            ],
        ];
    }

    public function beforeAction($action)
    {
        if (!parent::beforeAction($action)) return false;
        $scope = Scope::current();
        if ($scope === null) throw new ForbiddenHttpException();
        $this->scope = $scope;
        return true;
    }

    public function actionIndex()
    {
        [$tts, $btc, $filters] = $this->filtered();
        return $this->render('index', [
            'totals' => PaymentTotals::byState($tts),
            'btcTotals' => self::btcTotals($btc),
            'payments' => new ActiveDataProvider(['query' => self::merged($tts, $btc), 'sort' => false, 'pagination' => ['pageSize' => 25]]),
            'filters' => $filters,
            'stores' => $this->scope->storeOptions(),
            'admin' => $this->scope->isAdmin(),
        ]);
    }

    public function actionView(string $id)
    {
        if (preg_match(TtsPayments::UUID, $id) !== 1) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        $payment = self::select($this->ttsBase(), 'tts')->andWhere(['p.public_id' => $id])->one()
            ?: self::select($this->btcBase(), 'btc')->andWhere(['p.public_id' => $id])->one();
        if ($payment === false) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        return $this->render('view', ['payment' => $payment, 'admin' => $this->scope->isAdmin()]);
    }

    /** CSV con separatore ; e BOM UTF-8 (apertura diretta in Excel), stessi filtri dell'elenco. */
    public function actionExport()
    {
        [$tts, $btc] = $this->filtered();
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        $header = [Yii::t('app', 'Date'), Yii::t('app', 'Method'), Yii::t('app', 'Store'), Yii::t('app', 'POS'), Yii::t('app', 'Operator'), Yii::t('app', 'Amount'),
            Yii::t('app', 'Currency'), Yii::t('app', 'Status'), Yii::t('app', 'Paid at'), Yii::t('app', 'Transaction'), Yii::t('app', 'Lost funds'),
            Yii::t('app', 'Rate (1 BTC)'), Yii::t('app', 'BTC paid'), Yii::t('app', 'ID')];
        if ($this->scope->isAdmin()) array_unshift($header, Yii::t('app', 'Merchant'));
        fputcsv($out, $header, ';', '"', '');
        foreach (self::merged($tts, $btc)->limit(self::EXPORT_LIMIT)->each(500) as $row) {
            $line = [date('Y-m-d H:i:s', (int) $row['created_at']), strtoupper($row['method']), $row['store'], $row['terminal'], trim($row['first_name'] . ' ' . $row['last_name']),
                number_format((int) $row['amount_cents'] / 100, 2, ',', ''), $row['currency'], $row['state'],
                $row['paid_at'] === null ? '' : date('Y-m-d H:i:s', (int) $row['paid_at']), (string) $row['tx_hash'],
                $row['state'] === 'paid' && (int) $row['lost'] === 1 ? Yii::t('app', 'Yes') : '',
                $row['rate_cents'] === null ? '' : number_format((int) $row['rate_cents'] / 100, 2, ',', ''),
                $row['paid_satoshis'] === null ? '' : self::btc((int) $row['paid_satoshis']), $row['public_id']];
            if ($this->scope->isAdmin()) array_unshift($line, $row['merchant']);
            // celle che iniziano con = + - @ non diventano formule in Excel
            fputcsv($out, array_map(static fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v, $line), ';', '"', '');
        }
        rewind($out);
        return Yii::$app->response->sendContentAsFile(stream_get_contents($out), 'incassi-' . date('Ymd-His') . '.csv', ['mimeType' => 'text/csv']);
    }

    /** "0.00024680" da satoshi interi (mai float). */
    public static function btc(int $satoshis): string
    {
        return intdiv($satoshis, 100000000) . '.' . str_pad((string) ($satoshis % 100000000), 8, '0', STR_PAD_LEFT);
    }

    /** Incassi TTS in scope con negozio, POS, operatore e merchant (alias p, come PaymentTotals). */
    private function ttsBase(): Query
    {
        return $this->joined('{{%pos_tts_payments}}');
    }

    /** Incassi BTC in scope (SIP-033), stesse relazioni. */
    private function btcBase(): Query
    {
        return $this->joined('{{%pos_btc_payments}}');
    }

    private function joined(string $table): Query
    {
        $query = (new Query())->from(['p' => $table])
            ->innerJoin(['s' => Stores::tableName()], 's.id=p.store_id')
            ->innerJoin(['t' => PosTerminals::tableName()], 't.id=p.terminal_id')
            ->innerJoin(['u' => Users::tableName()], 'u.id=p.user_id')
            ->innerJoin(['m' => Merchants::tableName()], 'm.id=p.merchant_id');
        $this->scope->apply($query, 'p.merchant_id');
        return $query;
    }

    /** Stesse colonne per TTS e BTC (UNION): i campi dell'altro metodo restano NULL. Mai ID remoti o fattura BTCPay. */
    private static function select(Query $query, string $method): Query
    {
        $null = new Expression('NULL');
        $tts = $method === 'tts';
        return (clone $query)->select([
            'method' => new Expression($tts ? "'tts'" : "'btc'"), 'p.public_id', 'p.amount_cents', 'currency' => $tts ? new Expression("'TTS'") : 'p.currency', 'p.state',
            'to_address' => $tts ? 'p.to_address' : $null, 'tx_hash' => $tts ? 'p.tx_hash' : 'p.txids', 'paid_block' => $tts ? 'p.paid_block' : $null,
            'paid_at' => $tts ? 'p.paid_at' : new Expression('COALESCE(p.processing_at, p.settled_at)'), 'p.closed_at', 'p.created_at', 'p.expires_at',
            'rate_cents' => $tts ? $null : 'p.rate_cents', 'order_satoshis' => $tts ? $null : 'p.order_satoshis', 'paid_satoshis' => $tts ? $null : 'p.paid_satoshis',
            'amount_satoshis' => $tts ? $null : 'p.amount_satoshis', 'btc_address' => $tts ? $null : 'p.btc_address', 'confirmations' => $tts ? $null : 'p.confirmations',
            'settled_at' => $tts ? $null : 'p.settled_at', 'last_error_code' => $tts ? $null : 'p.last_error_code',
            'store' => 's.description', 'terminal' => 't.name', 'u.first_name', 'u.last_name', 'merchant' => 'm.description',
            // SIP-031: pagato su un wallet segnalato perso e non ritrovato (solo TTS)
            'lost' => $tts ? LostWallets::lostCondition('p') : new Expression('0'),
        ]);
    }

    /** Elenco unico TTS + BTC, più recenti prima. */
    private static function merged(Query $tts, Query $btc): Query
    {
        return (new Query())->from(['x' => self::select($tts, 'tts')->union(self::select($btc, 'btc'), true)])
            ->orderBy(['x.created_at' => SORT_DESC, 'x.method' => SORT_DESC, 'x.public_id' => SORT_DESC]);
    }

    /**
     * Totali BTC per valuta del negozio, negli stessi gruppi dei TTS (incassato = ricevuto o confermato, in attesa, scaduto,
     * non riuscito): conteggio e importo; per l'incassato anche i BTC pagati.
     * @return array<string, array<string, array{count:int, cents:int, satoshis?:int}>>
     */
    private static function btcTotals(Query $btc): array
    {
        $rows = (clone $btc)->select(['currency' => 'p.currency', 'state' => 'p.state', 'n' => 'COUNT(*)', 'cents' => 'COALESCE(SUM(p.amount_cents), 0)',
            'sats' => 'COALESCE(SUM(COALESCE(p.paid_satoshis, p.amount_satoshis, 0)), 0)'])->orderBy(null)->groupBy(['p.currency', 'p.state'])->all();
        $totals = [];
        foreach ($rows as $row) {
            $totals[$row['currency']] ??= ['paid' => ['count' => 0, 'cents' => 0, 'satoshis' => 0], 'pending' => ['count' => 0, 'cents' => 0],
                'expired' => ['count' => 0, 'cents' => 0], 'cancelled' => ['count' => 0, 'cents' => 0]];
            foreach (self::BTC_STATES as $group => $states) {
                if (!in_array($row['state'], $states, true)) continue;
                $totals[$row['currency']][$group]['count'] += (int) $row['n'];
                $totals[$row['currency']][$group]['cents'] += (int) $row['cents'];
                if ($group === 'paid') $totals[$row['currency']]['paid']['satoshis'] += (int) $row['sats'];
            }
        }
        ksort($totals);
        return $totals;
    }

    /** Filtri GET validati: periodo (date), negozio (ID cifrato in scope), metodo, stato. Valori non validi ignorati. */
    private function filtered(): array
    {
        $request = Yii::$app->request;
        $filters = ['from' => '', 'to' => '', 'store' => '', 'method' => '', 'state' => ''];
        $tts = $this->ttsBase();
        $btc = $this->btcBase();
        foreach (['from', 'to'] as $key) {
            $value = (string) $request->get($key, '');
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Rome'));
            if ($date === false || $date->format('Y-m-d') !== $value) continue;
            $filters[$key] = $value;
            $condition = $key === 'from' ? ['>=', 'p.created_at', $date->getTimestamp()] : ['<', 'p.created_at', $date->modify('+1 day')->getTimestamp()];
            $tts->andWhere($condition);
            $btc->andWhere($condition);
        }
        $store = (string) $request->get('store', '');
        if ($store !== '' && ($storeId = $this->scope->storeId($store)) !== null) {
            $filters['store'] = $store;
            $tts->andWhere(['p.store_id' => $storeId]);
            $btc->andWhere(['p.store_id' => $storeId]);
        }
        $method = (string) $request->get('method', '');
        if (in_array($method, self::METHODS, true)) {
            $filters['method'] = $method;
            ($method === 'tts' ? $btc : $tts)->andWhere('0=1');
        }
        $state = (string) $request->get('state', '');
        if (in_array($state, self::STATES, true)) {
            $filters['state'] = $state;
            $tts->andWhere(['p.state' => $state]);
            $btc->andWhere(['p.state' => self::BTC_STATES[$state]]);
        }
        return [$tts, $btc, $filters];
    }
}
