<?php
namespace app\components\pos\management;

use Yii;
use app\components\Log;
use app\components\identity\AccountEligibility;
use app\components\pos\PosApi;
use app\models\PosOperators;
use app\models\PosOperatorSessions;
use app\models\PosStoreOperators;
use app\models\PosTerminals;
use yii\db\Connection;
use yii\db\IntegrityException;

/**
 * C4: scritture su terminali, profili operatore esistenti e grant.
 * Ogni scrittura: lock del merchant, scope ricalcolato, lock del record, gate di concessione, audit.
 * Concessioni (creare/abilitare) solo con merchant qualificato e account operatore coerente;
 * riduzioni (disabilitare) sempre possibili. Nessun delete, trasferimento o grant implicito.
 */
final class PosManagement
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    public static function createTerminal(Scope $scope, int $storeId, string $name): string
    {
        return self::write(function (Connection $db) use ($scope, $storeId, $name) {
            $merchantId = self::storeMerchant($db, $storeId);
            self::lockMerchant($db, $scope, $merchantId);
            self::lockStore($db, $storeId, $merchantId);
            self::requireQualified($merchantId);
            $terminal = new PosTerminals(['merchant_id' => $merchantId, 'store_id' => $storeId, 'name' => $name, 'is_enabled' => 0]);
            if (!$terminal->save()) throw new ManagementException(ManagementException::INVALID);
            Log::event(sprintf('Terminale POS %s creato (negozio %d, merchant %d, nome %s)', $terminal->public_id, $storeId, $merchantId, Log::quote($terminal->name)), Log::TYPE_DATA);
            return $terminal->public_id;
        });
    }

    public static function renameTerminal(Scope $scope, string $publicId, string $name): void
    {
        self::write(function (Connection $db) use ($scope, $publicId, $name) {
            $terminal = self::lockTerminal($db, $scope, $publicId);
            $terminal->name = $name;
            if (!$terminal->save(true, ['name', 'updated_at'])) throw new ManagementException(ManagementException::INVALID);
            Log::event(sprintf('Terminale POS %s rinominato in %s', $terminal->public_id, Log::quote($terminal->name)), Log::TYPE_DATA);
        });
    }

    public static function setTerminalEnabled(Scope $scope, string $publicId, bool $enabled): void
    {
        self::write(function (Connection $db) use ($scope, $publicId, $enabled) {
            $terminal = self::lockTerminal($db, $scope, $publicId);
            if ($enabled) self::requireQualified((int) $terminal->merchant_id);
            $terminal->is_enabled = $enabled ? 1 : 0;
            if (!$terminal->save(false, ['is_enabled', 'updated_at'])) throw new ManagementException(ManagementException::FAILED);
            Log::event(sprintf('Terminale POS %s %s', $terminal->public_id, $enabled ? 'abilitato' : 'disabilitato'), Log::TYPE_DATA);
        });
    }

    /** D3: la disabilitazione revoca nella stessa transazione le sessioni POS attive. Restituisce le sessioni revocate. */
    public static function setOperatorEnabled(Scope $scope, int $userId, bool $enabled): int
    {
        return self::write(function (Connection $db) use ($scope, $userId, $enabled) {
            $operator = self::lockOperator($db, $scope, $userId);
            if ($enabled) self::requireOperator((int) $operator->merchant_id, $userId);
            $operator->is_enabled = $enabled ? 1 : 0;
            if (!$operator->save(false, ['is_enabled', 'updated_at'])) throw new ManagementException(ManagementException::FAILED);
            $revoked = 0;
            if (!$enabled) {
                $sessions = PosOperatorSessions::find()->where(['user_id' => $userId, 'revoked_at' => null])
                    ->andWhere(['>', 'expires_at', time()])->all();
                foreach ($sessions as $session) {
                    PosApi::revoke($session, 'operator_disabled');
                    ++$revoked;
                }
            }
            Log::event(sprintf('Operatore POS %d %s (merchant %d, sessioni revocate %d)', $userId, $enabled ? 'abilitato' : 'disabilitato', $operator->merchant_id, $revoked), Log::TYPE_DATA);
            return $revoked;
        });
    }

    public static function createGrant(Scope $scope, int $userId, int $storeId): void
    {
        self::write(function (Connection $db) use ($scope, $userId, $storeId) {
            $operator = self::lockOperator($db, $scope, $userId);
            $merchantId = (int) $operator->merchant_id;
            // negozio di un altro merchant: come inesistente
            self::lockStore($db, $storeId, $merchantId);
            self::requireOperator($merchantId, $userId);
            $grant = new PosStoreOperators(['user_id' => $userId, 'merchant_id' => $merchantId, 'store_id' => $storeId, 'is_enabled' => 0]);
            if (!$grant->save()) throw new ManagementException(ManagementException::CONFLICT);
            Log::event(sprintf('Grant POS creato: operatore %d, negozio %d (merchant %d)', $userId, $storeId, $merchantId), Log::TYPE_DATA);
        });
    }

    public static function setGrantEnabled(Scope $scope, int $userId, int $storeId, bool $enabled): void
    {
        self::write(function (Connection $db) use ($scope, $userId, $storeId, $enabled) {
            $operator = self::lockOperator($db, $scope, $userId);
            $merchantId = (int) $operator->merchant_id;
            $grant = PosStoreOperators::findBySql('SELECT * FROM {{%pos_store_operators}} WHERE user_id=:u AND store_id=:s AND merchant_id=:m FOR UPDATE',
                [':u' => $userId, ':s' => $storeId, ':m' => $merchantId])->one();
            if ($grant === null) throw new ManagementException(ManagementException::NOT_FOUND);
            if ($enabled) self::requireOperator($merchantId, $userId);
            $grant->is_enabled = $enabled ? 1 : 0;
            if (!$grant->save(false, ['is_enabled', 'updated_at'])) throw new ManagementException(ManagementException::FAILED);
            Log::event(sprintf('Grant POS %s: operatore %d, negozio %d (merchant %d)', $enabled ? 'abilitato' : 'disabilitato', $userId, $storeId, $merchantId), Log::TYPE_DATA);
        });
    }

    public static function validPublicId($value): bool
    {
        return is_string($value) && preg_match(self::UUID, $value) === 1;
    }

    private static function write(callable $work)
    {
        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $result = $work($db);
            $transaction->commit();
            return $result;
        } catch (ManagementException $e) {
            $transaction->rollBack();
            throw $e;
        } catch (IntegrityException $e) {
            $transaction->rollBack();
            throw new ManagementException(ManagementException::CONFLICT);
        } catch (\Throwable $e) {
            if ($transaction->isActive) $transaction->rollBack();
            // solo la classe: messaggi e query possono contenere dati
            Yii::error('Gestione POS non riuscita: ' . get_class($e), __METHOD__);
            throw new ManagementException(ManagementException::FAILED);
        }
    }

    /** Lock del merchant e scope dell'attore ricalcolato dentro la transazione. */
    private static function lockMerchant(Connection $db, Scope $scope, int $merchantId): void
    {
        if ($db->createCommand('SELECT id FROM {{%merchants}} WHERE id=:id FOR UPDATE', [':id' => $merchantId])->queryScalar() === false)
            throw new ManagementException(ManagementException::NOT_FOUND);
        $fresh = Scope::current();
        if ($fresh === null || $fresh->actorId !== $scope->actorId || !$fresh->covers($merchantId))
            throw new ManagementException(ManagementException::NOT_FOUND);
    }

    private static function storeMerchant(Connection $db, int $storeId): int
    {
        $merchant = $db->createCommand('SELECT merchant_id FROM {{%stores}} WHERE id=:id', [':id' => $storeId])->queryScalar();
        if ($merchant === false || $merchant === null) throw new ManagementException(ManagementException::NOT_FOUND);
        return (int) $merchant;
    }

    private static function lockStore(Connection $db, int $storeId, int $merchantId): void
    {
        if ($db->createCommand('SELECT id FROM {{%stores}} WHERE id=:id AND merchant_id=:m FOR UPDATE', [':id' => $storeId, ':m' => $merchantId])->queryScalar() === false)
            throw new ManagementException(ManagementException::NOT_FOUND);
    }

    private static function lockTerminal(Connection $db, Scope $scope, string $publicId): PosTerminals
    {
        if (!self::validPublicId($publicId)) throw new ManagementException(ManagementException::NOT_FOUND);
        $merchant = $db->createCommand('SELECT merchant_id FROM {{%pos_terminals}} WHERE public_id=:p', [':p' => $publicId])->queryScalar();
        if ($merchant === false) throw new ManagementException(ManagementException::NOT_FOUND);
        self::lockMerchant($db, $scope, (int) $merchant);
        $terminal = PosTerminals::findBySql('SELECT * FROM {{%pos_terminals}} WHERE public_id=:p AND merchant_id=:m FOR UPDATE',
            [':p' => $publicId, ':m' => (int) $merchant])->one();
        if ($terminal === null) throw new ManagementException(ManagementException::NOT_FOUND);
        return $terminal;
    }

    private static function lockOperator(Connection $db, Scope $scope, int $userId): PosOperators
    {
        $merchant = $db->createCommand('SELECT merchant_id FROM {{%pos_operators}} WHERE user_id=:u', [':u' => $userId])->queryScalar();
        if ($merchant === false) throw new ManagementException(ManagementException::NOT_FOUND);
        self::lockMerchant($db, $scope, (int) $merchant);
        $operator = PosOperators::findBySql('SELECT * FROM {{%pos_operators}} WHERE user_id=:u AND merchant_id=:m FOR UPDATE',
            [':u' => $userId, ':m' => (int) $merchant])->one();
        if ($operator === null) throw new ManagementException(ManagementException::NOT_FOUND);
        return $operator;
    }

    private static function requireQualified(int $merchantId): void
    {
        if (!AccountEligibility::qualifiedMerchant($merchantId)) throw new ManagementException(ManagementException::NOT_ALLOWED);
    }

    private static function requireOperator(int $merchantId, int $userId): void
    {
        self::requireQualified($merchantId);
        if (!AccountEligibility::isOperatorAccount($userId)) throw new ManagementException(ManagementException::NOT_ALLOWED);
    }
}
