<?php
namespace app\components\pos;

use Yii;
use app\models\PosOperators;
use app\models\PosStoreOperators;
use app\models\PosTerminals;
use app\models\Stores;
use app\models\Users;
use app\models\Privileges;
use app\components\User;
use yii\db\Query;
use yii\helpers\Json;
use yii\web\BadRequestHttpException;

/** Read-only, scoped before pagination; cursors never act as authorization. */
final class TerminalCatalog
{
    private const MAX_BYTES = 16384;

    public static function page(int $user, string $token, string $client, int $limit, ?string $cursor): array
    {
        $key = hash('sha256', 'pos-catalog|' . $client . '|' . $token, true);
        $after = 0;
        if ($cursor !== null) {
            if (!preg_match('/^[A-Za-z0-9_-]{1,512}$/D', $cursor)) throw new BadRequestHttpException();
            $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
            if ($raw === false || self::encode($raw) !== $cursor) throw new BadRequestHttpException();
            $plain = Yii::$app->security->decryptByKey($raw, $key);
            if ($plain === false) throw new BadRequestHttpException();
            try { $state = json_decode($plain, true, 3, JSON_THROW_ON_ERROR); }
            catch (\JsonException $e) { throw new BadRequestHttpException(); }
            if (!is_array($state) || count($state) !== 3 || ($state['v'] ?? null) !== 1
                || ($state['limit'] ?? null) !== $limit || !is_int($state['after'] ?? null)
                || $state['after'] < 1 || $state['after'] > 2147483647) throw new BadRequestHttpException();
            $after = $state['after'];
        }
        $rows = (new Query())->select(['t.id','t.public_id','t.name','store_name'=>'s.description'])
            ->from(['o'=>PosOperators::tableName()])
            ->innerJoin(['u'=>Users::tableName()], 'u.id=o.user_id')
            ->innerJoin(['p'=>Privileges::tableName()], 'p.id=u.privilege_id')
            ->innerJoin(['g'=>PosStoreOperators::tableName()], 'g.user_id=o.user_id AND g.merchant_id=o.merchant_id')
            ->innerJoin(['s'=>Stores::tableName()], 's.id=g.store_id AND s.merchant_id=g.merchant_id')
            ->innerJoin(['t'=>PosTerminals::tableName()], 't.store_id=s.id AND t.merchant_id=s.merchant_id')
            ->where(['o.user_id'=>$user,'o.is_enabled'=>1,'g.is_enabled'=>1,'t.is_enabled'=>1,'u.is_active'=>1,'p.level'=>User::ROLE_USER])
            ->andWhere(['>','t.id',$after])->orderBy(['t.id'=>SORT_ASC])->limit($limit+1)->all();
        $items = []; $last = 0;
        foreach ($rows as $row) {
            if (count($items) >= $limit) break;
            if (!is_string($row['public_id']) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $row['public_id'])) throw new \RuntimeException('Invalid catalog data.');
            foreach (['name','store_name'] as $field) {
                if (!is_string($row[$field]) || !mb_check_encoding($row[$field], 'UTF-8')
                    || mb_strlen($row[$field]) > 256 || !preg_match('/\S/u', $row[$field])) throw new \RuntimeException('Invalid catalog data.');
            }
            $item = ['id'=>$row['public_id'],'store_name'=>$row['store_name'],'name'=>$row['name'],'availability'=>'unknown'];
            // Reserve the entire allowed cursor size, not just the current cipher length.
            if (strlen(Json::encode(['items'=>array_merge($items,[$item]),'next_cursor'=>str_repeat('x',512)])) > self::MAX_BYTES) break;
            $items[] = $item; $last = (int)$row['id'];
        }
        if ($rows !== [] && $items === []) throw new \RuntimeException('Catalog page exceeds limit.');
        $next = null;
        if (count($rows) > count($items)) {
            $next = self::encode(Yii::$app->security->encryptByKey(Json::encode(['v'=>1,'after'=>$last,'limit'=>$limit]), $key));
            if (strlen($next) > 512) throw new \RuntimeException('Catalog cursor exceeds limit.');
        }
        return ['items'=>$items,'next_cursor'=>$next];
    }

    private static function encode(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
}
