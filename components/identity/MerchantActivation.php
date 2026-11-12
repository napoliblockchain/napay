<?php
namespace app\components\identity;

use Yii;
use app\components\Log;
use app\components\User;
use app\models\Merchants;
use app\models\Privileges;
use app\models\Users;
use yii\db\Transaction;

/** SIP-023 R3b: local activation only; no remote calls or payment readiness. */
final class MerchantActivation
{
    public static function activate(int $actorId, int $ownerId, Merchants $merchant): ?int
    {
        $db = Yii::$app->db;
        if ($db->getTransaction() !== null || !$merchant->isNewRecord) {
            throw new \LogicException('Activation requires a new model and its own transaction.');
        }
        $tx = $db->beginTransaction(Transaction::READ_COMMITTED);
        try {
            // Every application writer creating a merchant serializes on its owner.
            if ($db->createCommand('SELECT id FROM {{%users}} WHERE id=:id FOR UPDATE', [':id'=>$ownerId])->queryScalar() === false) {
                throw new \DomainException('Merchant activation unavailable.');
            }
            $db->createCommand('SELECT user_id FROM {{%member_profiles}} WHERE user_id=:id FOR UPDATE', [':id'=>$ownerId])->queryScalar();
            $actor = Users::findOne($actorId);
            if ($actor === null || !$actor->validateStatus()
                || ($actorId !== $ownerId && (int) $actor->privilege->level !== User::ROLE_ADMIN)) {
                throw new \DomainException('Merchant activation unavailable.');
            }
            $existing = AccountEligibility::merchantForManager($ownerId);
            if ($existing !== null) {
                $tx->commit();
                return $existing;
            }
            if (!AccountEligibility::canActivateMerchant($ownerId)) {
                throw new \DomainException('Merchant activation unavailable.');
            }
            $merchant->scenario = Merchants::SCENARIO_ACTIVATION;
            $merchant->user_id = $ownerId;
            if (!$merchant->validate()) {
                $tx->rollBack();
                return null;
            }
            $roles = Privileges::find()->select('id')->where(['level'=>User::ROLE_MERCHANT])->limit(2)->column();
            if (count($roles) !== 1) throw new \DomainException('Merchant activation unavailable.');
            if (!$merchant->save(false)) throw new \RuntimeException('Merchant activation failed.');
            if ($db->createCommand()->update(Users::tableName(), ['privilege_id'=>(int)$roles[0], 'is_merchant'=>1], ['id'=>$ownerId])->execute() !== 1) {
                throw new \RuntimeException('Merchant activation failed.');
            }
            Log::event(sprintf('Merchant %d attivato per account %d', $merchant->id, $ownerId), Log::TYPE_DATA);
            $tx->commit();
            return (int) $merchant->id;
        } catch (\Throwable $error) {
            if ($tx->isActive) $tx->rollBack();
            throw $error;
        }
    }
}
