<?php

namespace app\components\pos\tts;

use Yii;
use yii\db\Connection;
use yii\db\Query;
use yii\db\Transaction;
use app\components\Log;
use app\components\pos\management\ManagementException;
use app\components\pos\management\PosManagement;
use app\components\pos\management\Scope;

/**
 * SIP-031: seed perso del wallet TTS di un negozio. La segnalazione blocca subito gli incassi: l'indirizzo esce da tutti
 * i negozi del merchant e le richieste ancora in attesa verso di lui vengono annullate (il QR mostrato non vale più).
 * Le aperture dei POS restano: senza wallet il negozio risponde "incasso non disponibile". Nessun annullamento della
 * segnalazione; se l'indirizzo viene salvato di nuovo (seed ritrovato) torna nei totali e nel saldo.
 */
final class LostWallets
{
    public const TABLE = '{{%tts_lost_wallets}}';

    /** @return array{address:string, stores:int, cancelled:int} */
    public static function report(Scope $scope, int $storeId, int $merchantId, string $reason): array
    {
        $db = Yii::$app->db;
        $transaction = $db->beginTransaction(Transaction::READ_COMMITTED);
        try {
            PosManagement::lockMerchant($db, $scope, $merchantId);
            $address = $db->createCommand('SELECT address FROM {{%store_tts_wallets}} WHERE store_id=:s AND merchant_id=:m FOR UPDATE',
                [':s' => $storeId, ':m' => $merchantId])->queryScalar();
            if ($address === false) throw new ManagementException(ManagementException::NOT_FOUND);
            $now = time();
            $db->createCommand()->insert(self::TABLE, ['merchant_id' => $merchantId, 'store_id' => $storeId, 'address' => $address,
                'reason' => $reason, 'reported_by' => $scope->actorId, 'reported_at' => $now])->execute();
            // lo stesso indirizzo usato da altri negozi del merchant è perso anche lì
            $stores = $db->createCommand()->delete('{{%store_tts_wallets}}', ['merchant_id' => $merchantId, 'address' => $address])->execute();
            $cancelled = $db->createCommand()->update('{{%pos_tts_payments}}', ['state' => 'cancelled', 'closed_at' => $now],
                ['merchant_id' => $merchantId, 'to_address' => $address, 'state' => 'pending'])->execute();
            Log::event(sprintf('Wallet TTS %s del negozio %d segnalato perso: tolto da %d negozi, %d richieste in attesa annullate', $address, $storeId, $stores, $cancelled), Log::TYPE_SECURITY);
            $transaction->commit();
            return ['address' => $address, 'stores' => $stores, 'cancelled' => $cancelled];
        } catch (\Throwable $error) {
            if ($transaction->isActive) $transaction->rollBack();
            if ($error instanceof ManagementException) throw $error;
            Yii::error('Lost wallet report failure: ' . get_class($error), __METHOD__);
            throw new ManagementException(ManagementException::FAILED);
        }
    }

    /** Indirizzo salvato di nuovo dopo una segnalazione (seed ritrovato): chiamato nella transazione del salvataggio. */
    public static function recover(Connection $db, int $merchantId, string $address, int $actorId): bool
    {
        $recovered = $db->createCommand()->update(self::TABLE, ['recovered_at' => time(), 'recovered_by' => $actorId],
            ['merchant_id' => $merchantId, 'address' => $address, 'recovered_at' => null])->execute();
        if ($recovered > 0) Log::event(sprintf('Wallet TTS %s ritrovato: torna nei totali e nel saldo', $address), Log::TYPE_SECURITY);
        return $recovered > 0;
    }

    public static function isLost(int $merchantId, string $address): bool
    {
        return (new Query())->from(self::TABLE)->where(['merchant_id' => $merchantId, 'address' => $address, 'recovered_at' => null])->exists();
    }

    /** Condizione SQL: incasso p pagato su un indirizzo perso e non ritrovato del suo merchant. */
    public static function lostCondition(string $alias = 'p'): string
    {
        return "EXISTS (SELECT 1 FROM " . Yii::$app->db->quoteTableName(Yii::$app->db->tablePrefix . 'tts_lost_wallets')
            . " lw WHERE lw.merchant_id = $alias.merchant_id AND lw.address = $alias.to_address AND lw.recovered_at IS NULL)";
    }
}
