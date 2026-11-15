<?php

namespace app\controllers;

use Yii;
use app\components\pos\management\Scope;
use app\components\pos\tts\TtsPayments;
use app\models\Merchants;
use app\models\PosTerminals;
use app\models\Stores;
use app\models\Users;
use yii\data\ActiveDataProvider;
use yii\db\Query;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Incassi dei POS (al posto della vecchia pagina Transazioni): gestore sul proprio merchant, admin su tutti (Scope di C4).
 * Sola lettura; mai riferimento del pagamento, sessione o chiavi. Oggi solo TTS; BTC (SIP-022) con una colonna Metodo.
 */
class PosPaymentsController extends Controller
{
    public const STATES = ['pending', 'paid', 'expired', 'cancelled'];
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
        [$query, $filters] = $this->filtered();
        return $this->render('index', [
            'payments' => new ActiveDataProvider(['query' => $query, 'sort' => false, 'pagination' => ['pageSize' => 25]]),
            'filters' => $filters,
            'stores' => $this->scope->storeOptions(),
            'admin' => $this->scope->isAdmin(),
        ]);
    }

    public function actionView(string $id)
    {
        if (preg_match(TtsPayments::UUID, $id) !== 1) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        $query = $this->base()->andWhere(['p.public_id' => $id]);
        $payment = $query->one();
        if ($payment === false) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        return $this->render('view', ['payment' => $payment, 'admin' => $this->scope->isAdmin()]);
    }

    /** CSV con separatore ; e BOM UTF-8 (apertura diretta in Excel), stessi filtri dell'elenco. */
    public function actionExport()
    {
        [$query] = $this->filtered();
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        $header = [Yii::t('app', 'Date'), Yii::t('app', 'Store'), Yii::t('app', 'POS'), Yii::t('app', 'Operator'), Yii::t('app', 'Amount'),
            Yii::t('app', 'Currency'), Yii::t('app', 'Status'), Yii::t('app', 'Paid at'), Yii::t('app', 'Transaction'), Yii::t('app', 'ID')];
        if ($this->scope->isAdmin()) array_unshift($header, Yii::t('app', 'Merchant'));
        fputcsv($out, $header, ';', '"', '');
        foreach ($query->limit(self::EXPORT_LIMIT)->each(500) as $row) {
            $line = [date('Y-m-d H:i:s', (int) $row['created_at']), $row['store'], $row['terminal'], trim($row['first_name'] . ' ' . $row['last_name']),
                number_format((int) $row['amount_cents'] / 100, 2, ',', ''), 'TTS', $row['state'],
                $row['paid_at'] === null ? '' : date('Y-m-d H:i:s', (int) $row['paid_at']), (string) $row['tx_hash'], $row['public_id']];
            if ($this->scope->isAdmin()) array_unshift($line, $row['merchant']);
            // celle che iniziano con = + - @ non diventano formule in Excel
            fputcsv($out, array_map(static fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v, $line), ';', '"', '');
        }
        rewind($out);
        return Yii::$app->response->sendContentAsFile(stream_get_contents($out), 'incassi-' . date('Ymd-His') . '.csv', ['mimeType' => 'text/csv']);
    }

    /** Incassi in scope con negozio, POS, operatore e merchant. */
    private function base(): Query
    {
        $query = (new Query())
            ->select(['p.public_id', 'p.amount_cents', 'p.state', 'p.to_address', 'p.tx_hash', 'p.paid_block', 'p.paid_at', 'p.closed_at', 'p.created_at', 'p.expires_at',
                'store' => 's.description', 'terminal' => 't.name', 'u.first_name', 'u.last_name', 'merchant' => 'm.description'])
            ->from(['p' => '{{%pos_tts_payments}}'])
            ->innerJoin(['s' => Stores::tableName()], 's.id=p.store_id')
            ->innerJoin(['t' => PosTerminals::tableName()], 't.id=p.terminal_id')
            ->innerJoin(['u' => Users::tableName()], 'u.id=p.user_id')
            ->innerJoin(['m' => Merchants::tableName()], 'm.id=p.merchant_id')
            ->orderBy(['p.created_at' => SORT_DESC, 'p.id' => SORT_DESC]);
        $this->scope->apply($query, 'p.merchant_id');
        return $query;
    }

    /** Filtri GET validati: periodo (date), negozio (ID cifrato in scope), stato. Valori non validi ignorati. */
    private function filtered(): array
    {
        $request = Yii::$app->request;
        $filters = ['from' => '', 'to' => '', 'store' => '', 'state' => ''];
        $query = $this->base();
        foreach (['from', 'to'] as $key) {
            $value = (string) $request->get($key, '');
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Rome'));
            if ($date === false || $date->format('Y-m-d') !== $value) continue;
            $filters[$key] = $value;
            $query->andWhere($key === 'from' ? ['>=', 'p.created_at', $date->getTimestamp()] : ['<', 'p.created_at', $date->modify('+1 day')->getTimestamp()]);
        }
        $store = (string) $request->get('store', '');
        if ($store !== '' && ($storeId = $this->scope->storeId($store)) !== null) {
            $filters['store'] = $store;
            $query->andWhere(['p.store_id' => $storeId]);
        }
        $state = (string) $request->get('state', '');
        if (in_array($state, self::STATES, true)) {
            $filters['state'] = $state;
            $query->andWhere(['p.state' => $state]);
        }
        return [$query, $filters];
    }
}
