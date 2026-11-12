<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\ForbiddenHttpException;
use yii\filters\VerbFilter;
use yii\filters\AccessControl;
use yii\data\ActiveDataProvider;
use yii\db\Query;

use app\components\Crypt;
use app\components\Log;
use app\components\identity\AccountEligibility;
use app\components\pos\management\ManagementException;
use app\components\pos\management\PosManagement;
use app\components\pos\management\Scope;
use app\models\Merchants;
use app\models\PosOperators;
use app\models\PosStoreOperators;
use app\models\PosTerminals;
use app\models\Stores;
use app\models\Users;
use app\models\forms\pos\GrantForm;
use app\models\forms\pos\TerminalForm;

/**
 * C4 (SIP-021/SIP-023): terminali POS, profili operatore esistenti e grant ai negozi.
 * Admin su tutti i merchant, gestore solo sul proprio; scope nella query e su ogni ID.
 * Disponibilità sempre "Non verificata": nessuna apertura o sblocco da qui.
 */
class PosManagementController extends Controller
{
    private Scope $scope;

    public function behaviors()
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'index' => ['GET'], 'operator' => ['GET'],
                    'terminal-create' => ['GET', 'POST'], 'terminal-update' => ['GET', 'POST'],
                    'terminal-enable' => ['POST'], 'terminal-disable' => ['POST'],
                    'operator-enable' => ['POST'], 'operator-disable' => ['POST'],
                    'grant-create' => ['POST'], 'grant-enable' => ['POST'], 'grant-disable' => ['POST'],
                ],
            ],
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['@'],
                        'matchCallback' => fn($rule, $action) => Scope::current() !== null,
                    ],
                ],
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

    public function actionIndex(?string $store = null)
    {
        $storeId = $store === null ? null : $this->scopedStore($store);
        $terminals = (new Query())
            ->select(['t.public_id', 't.name', 't.is_enabled', 'store' => 's.description', 'merchant' => 'm.description'])
            ->from(['t' => PosTerminals::tableName()])
            ->innerJoin(['s' => Stores::tableName()], 's.id=t.store_id AND s.merchant_id=t.merchant_id')
            ->innerJoin(['m' => Merchants::tableName()], 'm.id=t.merchant_id')
            ->orderBy(['m.description' => SORT_ASC, 's.description' => SORT_ASC, 't.name' => SORT_ASC, 't.id' => SORT_ASC]);
        $this->scope->apply($terminals, 't.merchant_id');
        if ($storeId !== null) $terminals->andWhere(['t.store_id' => $storeId]);

        $operators = (new Query())
            ->select(['o.user_id', 'o.is_enabled', 'u.first_name', 'u.last_name', 'u.email', 'merchant' => 'm.description'])
            ->from(['o' => PosOperators::tableName()])
            ->innerJoin(['u' => Users::tableName()], 'u.id=o.user_id')
            ->innerJoin(['m' => Merchants::tableName()], 'm.id=o.merchant_id')
            ->orderBy(['m.description' => SORT_ASC, 'u.last_name' => SORT_ASC, 'u.first_name' => SORT_ASC, 'o.user_id' => SORT_ASC]);
        $this->scope->apply($operators, 'o.merchant_id');

        return $this->render('index', [
            'admin' => $this->scope->isAdmin(),
            'stores' => $this->storeOptions(),
            'store' => $storeId === null ? null : $store,
            'terminals' => new ActiveDataProvider(['query' => $terminals, 'sort' => false, 'pagination' => ['pageSize' => 20, 'pageParam' => 'tpage']]),
            'operators' => new ActiveDataProvider(['query' => $operators, 'sort' => false, 'pagination' => ['pageSize' => 20, 'pageParam' => 'opage']]),
        ]);
    }

    public function actionTerminalCreate()
    {
        $form = new TerminalForm(['scenario' => 'create']);
        if ($form->load(Yii::$app->request->post()) && $form->validate()) {
            $storeId = $this->decodeId($form->store);
            try {
                PosManagement::createTerminal($this->scope, $storeId, $form->name);
                Yii::$app->session->setFlash('success', Yii::t('app', 'Terminale creato, disabilitato.'));
                return $this->redirect(['index']);
            } catch (ManagementException $e) {
                // negozio fuori scope o inesistente: stesso esito di un dato non valido
                $form->addError('store', $e->getMessage() === ManagementException::NOT_FOUND ? Yii::t('app', 'Negozio non valido.') : $e->userMessage());
            }
        }
        return $this->render('terminal-form', ['form' => $form, 'stores' => $this->storeOptions(), 'terminal' => null]);
    }

    public function actionTerminalUpdate(string $id)
    {
        $terminal = $this->scopedTerminal($id);
        $form = new TerminalForm(['scenario' => 'update', 'name' => $terminal['name']]);
        if ($form->load(Yii::$app->request->post()) && $form->validate()) {
            return $this->write(fn() => PosManagement::renameTerminal($this->scope, $terminal['public_id'], $form->name),
                Yii::t('app', 'Terminale aggiornato.'), ['index']);
        }
        return $this->render('terminal-form', ['form' => $form, 'stores' => [], 'terminal' => $terminal]);
    }

    public function actionTerminalEnable(string $id)
    {
        return $this->write(fn() => PosManagement::setTerminalEnabled($this->scope, $id, true), Yii::t('app', 'Terminale abilitato.'), ['index']);
    }

    public function actionTerminalDisable(string $id)
    {
        return $this->write(fn() => PosManagement::setTerminalEnabled($this->scope, $id, false), Yii::t('app', 'Terminale disabilitato.'), ['index']);
    }

    public function actionOperator(string $id)
    {
        $userId = $this->decodeId($id);
        $query = (new Query())
            ->select(['o.user_id', 'o.merchant_id', 'o.is_enabled', 'u.first_name', 'u.last_name', 'u.email', 'merchant' => 'm.description'])
            ->from(['o' => PosOperators::tableName()])
            ->innerJoin(['u' => Users::tableName()], 'u.id=o.user_id')
            ->innerJoin(['m' => Merchants::tableName()], 'm.id=o.merchant_id')
            ->where(['o.user_id' => $userId]);
        $this->scope->apply($query, 'o.merchant_id');
        $operator = $query->one();
        if ($operator === false) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));

        $grants = (new Query())->select(['g.store_id', 'g.is_enabled', 'store' => 's.description'])
            ->from(['g' => PosStoreOperators::tableName()])
            ->innerJoin(['s' => Stores::tableName()], 's.id=g.store_id AND s.merchant_id=g.merchant_id')
            ->where(['g.user_id' => $userId, 'g.merchant_id' => $operator['merchant_id']])
            ->orderBy(['s.description' => SORT_ASC, 's.id' => SORT_ASC])->all();
        $available = Stores::find()->select(['description', 'id'])
            ->where(['merchant_id' => $operator['merchant_id']])
            ->andWhere(['not in', 'id', array_column($grants, 'store_id')])
            ->orderBy(['description' => SORT_ASC, 'id' => SORT_ASC])->asArray()->all();

        return $this->render('operator', [
            'operator' => $operator,
            'grants' => $grants,
            'available' => $available,
            // solo presentazione: il server ricontrolla i gate a ogni POST
            'eligible' => AccountEligibility::qualifiedMerchant((int) $operator['merchant_id']) && AccountEligibility::isOperatorAccount($userId),
            'grantForm' => new GrantForm(),
            'admin' => $this->scope->isAdmin(),
        ]);
    }

    public function actionOperatorEnable(string $id)
    {
        $userId = $this->decodeId($id);
        return $this->write(fn() => PosManagement::setOperatorEnabled($this->scope, $userId, true),
            Yii::t('app', 'Operatore abilitato. Dovrà accedere di nuovo al POS.'), ['operator', 'id' => $id]);
    }

    public function actionOperatorDisable(string $id)
    {
        $userId = $this->decodeId($id);
        return $this->write(fn() => PosManagement::setOperatorEnabled($this->scope, $userId, false),
            Yii::t('app', 'Operatore disabilitato e disconnesso dal POS.'), ['operator', 'id' => $id]);
    }

    public function actionGrantCreate(string $id)
    {
        $userId = $this->decodeId($id);
        $form = new GrantForm();
        if (!$form->load(Yii::$app->request->post()) || !$form->validate()) {
            Yii::$app->session->setFlash('danger', Yii::t('app', 'Dati non validi.'));
            return $this->redirect(['operator', 'id' => $id]);
        }
        $storeId = $this->decodeId($form->store);
        return $this->write(fn() => PosManagement::createGrant($this->scope, $userId, $storeId),
            Yii::t('app', 'Negozio assegnato, disabilitato.'), ['operator', 'id' => $id]);
    }

    public function actionGrantEnable(string $id, string $store)
    {
        return $this->grant($id, $store, true);
    }

    public function actionGrantDisable(string $id, string $store)
    {
        return $this->grant($id, $store, false);
    }

    private function grant($id, $store, bool $enabled)
    {
        $userId = $this->decodeId($id);
        $storeId = $this->decodeId($store);
        return $this->write(fn() => PosManagement::setGrantEnabled($this->scope, $userId, $storeId, $enabled),
            $enabled ? Yii::t('app', 'Negozio abilitato.') : Yii::t('app', 'Negozio disabilitato.'), ['operator', 'id' => $id]);
    }

    /** Esito della scrittura: record fuori scope = 404, altri esiti come messaggio generico. */
    private function write(callable $operation, string $success, array $redirect)
    {
        try {
            $operation();
            Yii::$app->session->setFlash('success', $success);
        } catch (ManagementException $e) {
            if ($e->getMessage() === ManagementException::NOT_FOUND) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
            // concessione negata dai gate di identità: fuori dalla transazione annullata
            if ($e->getMessage() === ManagementException::NOT_ALLOWED) Log::security(sprintf('Concessione POS rifiutata: %s', $this->action->id));
            Yii::$app->session->setFlash('danger', $e->userMessage());
        }
        return $this->redirect($redirect);
    }

    private function scopedTerminal($id): array
    {
        if (!PosManagement::validPublicId($id)) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        $query = (new Query())->select(['t.public_id', 't.name', 'store' => 's.description'])
            ->from(['t' => PosTerminals::tableName()])
            ->innerJoin(['s' => Stores::tableName()], 's.id=t.store_id AND s.merchant_id=t.merchant_id')
            ->where(['t.public_id' => $id]);
        $this->scope->apply($query, 't.merchant_id');
        $terminal = $query->one();
        if ($terminal === false) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        return $terminal;
    }

    private function scopedStore($store): int
    {
        $query = Stores::find()->where(['id' => $this->decodeId($store)]);
        $this->scope->apply($query, 'merchant_id');
        $id = $query->select('id')->scalar();
        if ($id === false) throw new NotFoundHttpException(Yii::t('app', 'The requested page does not exist.'));
        return (int) $id;
    }

    /** Negozi in scope: chiave = ID cifrato, etichetta con il merchant per l'admin. */
    private function storeOptions(): array
    {
        $query = (new Query())->select(['s.id', 's.description', 'merchant' => 'm.description'])
            ->from(['s' => Stores::tableName()])
            ->innerJoin(['m' => Merchants::tableName()], 'm.id=s.merchant_id')
            ->orderBy(['m.description' => SORT_ASC, 's.description' => SORT_ASC, 's.id' => SORT_ASC]);
        $this->scope->apply($query, 's.merchant_id');
        $options = [];
        foreach ($query->all() as $row) {
            $options[Crypt::encrypt($row['id'])] = $this->scope->isAdmin() ? $row['merchant'] . ' - ' . $row['description'] : $row['description'];
        }
        return $options;
    }

    private function decodeId($id): int
    {
        if (!is_string($id) || $id === '' || strlen($id) > 512) return 0;
        try {
            $plain = Crypt::decrypt($id);
        } catch (\Throwable $error) {
            return 0;
        }
        return ctype_digit($plain) ? (int) $plain : 0;
    }
}
