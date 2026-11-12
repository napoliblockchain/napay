<?php
namespace app\components\identity;

use app\components\User;
use app\models\MemberProfiles;
use app\models\MembershipPayments;
use app\models\Merchants;
use app\models\PosOperators;
use app\models\Privileges;
use app\models\Users;
use yii\db\Expression;
use yii\db\Query;

/** SIP-023 R1: positive identity evidence only, no cache, writes or role promotion. */
final class AccountEligibility
{
    public const MEMBER_PF = 'member_pf';
    public const MEMBER_PG = 'member_pg';
    public const OPERATOR = 'operator';
    public const PLATFORM_ADMIN = 'platform_admin';
    public const UNCLASSIFIED = 'unclassified';
    public const CONFLICT = 'conflict';

    public static function kind(int $userId): string
    {
        $account = self::account($userId);
        return $account === null ? self::UNCLASSIFIED : self::classify($account);
    }

    public static function isOperatorAccount(int $userId): bool
    {
        $account = self::account($userId);
        return $account !== null && (int)$account['is_active'] === 1 && (int)$account['level'] === User::ROLE_USER
            && self::classify($account) === self::OPERATOR;
    }

    public static function merchantForManager(int $userId): ?int
    {
        $account = self::account($userId);
        if ($account === null || (int)$account['is_active'] !== 1 || (int)$account['level'] !== User::ROLE_MERCHANT
            || self::classify($account) !== self::MEMBER_PG) return null;
        $ids = self::merchantIds($userId);
        return count($ids) === 1 ? (int)$ids[0] : null;
    }

    public static function qualifiedMerchant(int $merchantId): bool
    {
        $owner = Merchants::find()->select('user_id')->where(['id'=>$merchantId])->scalar();
        if ($owner === false || $owner === null) return false;
        $account = self::account((int)$owner);
        if ($account === null || (int)$account['level'] !== User::ROLE_MERCHANT || self::classify($account) !== self::MEMBER_PG) return false;
        $ids = self::merchantIds((int)$owner);
        return count($ids) === 1 && (int)$ids[0] === $merchantId;
    }

    public static function canActivateMerchant(int $userId): bool
    {
        $account = self::account($userId);
        return $account !== null && (int)$account['is_active'] === 1 && (int)$account['level'] === User::ROLE_USER
            && self::classify($account) === self::MEMBER_PG && self::merchantIds($userId) === [];
    }

    private static function account(int $userId): ?array
    {
        if ($userId < 1) return null;
        $row = (new Query())->select(['u.id','u.email','u.is_active','p.level'])->from(['u'=>Users::tableName()])
            ->innerJoin(['p'=>Privileges::tableName()], 'p.id=u.privilege_id')->where(['u.id'=>$userId])->one();
        return $row === false ? null : $row;
    }

    private static function classify(array $account): string
    {
        $id = (int)$account['id'];
        $email = strtolower(trim((string)$account['email']));
        if ($email === '' || (int)(new Query())->from(Users::tableName())
            ->where(['=',new Expression('LOWER(TRIM([[email]]))'),$email])->count() !== 1) return self::CONFLICT;
        $member = MemberProfiles::find()->select(['is_corporate','denomination'])->where(['user_id'=>$id])->asArray()->one();
        $operator = PosOperators::find()->where(['user_id'=>$id])->exists();
        $payments = MembershipPayments::find()->where(['user_id'=>$id])->exists();
        $merchants = self::merchantIds($id);
        if (count($merchants) > 1 || ($operator && ($member !== null || $payments || $merchants !== []))) return self::CONFLICT;
        if ($member !== null) {
            $type = (string)$member['is_corporate'];
            if (!in_array($type,['0','1'],true) || ($type === '0' && $merchants !== [])) return self::CONFLICT;
            if ($type === '1' && (!is_string($member['denomination']) || !mb_check_encoding($member['denomination'],'UTF-8')
                || mb_strlen($member['denomination']) > 250 || !preg_match('/\S/u',$member['denomination']))) return self::CONFLICT;
            return $type === '1' ? self::MEMBER_PG : self::MEMBER_PF;
        }
        if ($merchants !== []) return self::CONFLICT;
        if ($operator) return self::OPERATOR;
        // Historical fee activity without a profile is not enough to invent PF/PG.
        if ($payments) return self::UNCLASSIFIED;
        return (int)$account['level'] >= User::ROLE_ADMIN ? self::PLATFORM_ADMIN : self::UNCLASSIFIED;
    }

    private static function merchantIds(int $userId): array
    {
        return Merchants::find()->select('id')->where(['user_id'=>$userId])->orderBy('id')->limit(2)->column();
    }
}
