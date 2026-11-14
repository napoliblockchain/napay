<?php
namespace app\components\pos;

use Yii;
use app\models\PosOperatorSessions;
use yii\db\Connection;
use yii\db\Transaction;

/**
 * SIP-021 fase 2 (APERTURE-CONTRATTO): apertura esclusiva dei terminali POS. Lock persistente nel DB (al massimo
 * una riga `active` per terminale, vincolo UNIQUE), fencing con istanza e generazione, nessuno sblocco per tempo.
 * Un'apertura appartiene alla sessione (token) che l'ha creata; altrui = 404, propria non più valida = 409.
 */
final class TerminalOpenings
{
    public const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';
    private const TABLE = '{{%pos_terminal_openings}}';

    /**
     * Acquisizione atomica con chiave di idempotenza (per sessione).
     * @return array{0:int, 1:array} [201 nuova | 200 replay, apertura pubblica]
     */
    public static function open(PosOperatorSessions $session, string $terminalId, string $instanceId, string $idempotencyKey): array
    {
        foreach ([$terminalId, $instanceId, $idempotencyKey] as $uuid) if (preg_match(self::UUID, $uuid) !== 1) throw new \InvalidArgumentException('invalid_uuid');
        $db = Yii::$app->db;
        $terminal = $db->createCommand('SELECT id, merchant_id, store_id FROM {{%pos_terminals}} WHERE public_id=:p', [':p' => $terminalId])->queryOne();
        if ($terminal === false) throw new OpeningException(OpeningException::NOT_FOUND);
        $request = hash('sha256', $terminalId . '|' . $instanceId);
        $key = hash('sha256', $idempotencyKey);
        return self::transaction($db, static function () use ($db, $session, $terminal, $terminalId, $instanceId, $request, $key) {
            $merchant = (int) $terminal['merchant_id'];
            $user = (int) $session->user_id;
            // Ordine dei lock come C4 e login: merchant, profilo, utente, negozio, grant, terminale, sessione.
            if ($db->createCommand('SELECT id FROM {{%merchants}} WHERE id=:id FOR UPDATE', [':id' => $merchant])->queryScalar() === false) throw new OpeningException(OpeningException::NOT_FOUND);
            $profile = $db->createCommand('SELECT merchant_id, is_enabled FROM {{%pos_operators}} WHERE user_id=:u FOR UPDATE', [':u' => $user])->queryOne();
            $db->createCommand('SELECT id FROM {{%users}} WHERE id=:id FOR UPDATE', [':id' => $user])->queryScalar();
            $db->createCommand('SELECT id FROM {{%stores}} WHERE id=:id FOR UPDATE', [':id' => (int) $terminal['store_id']])->queryScalar();
            $grant = $db->createCommand('SELECT is_enabled FROM {{%pos_store_operators}} WHERE user_id=:u AND store_id=:s AND merchant_id=:m FOR UPDATE',
                [':u' => $user, ':s' => (int) $terminal['store_id'], ':m' => $merchant])->queryScalar();
            $locked = $db->createCommand('SELECT id, merchant_id, store_id, is_enabled FROM {{%pos_terminals}} WHERE id=:id FOR UPDATE', [':id' => (int) $terminal['id']])->queryOne();
            $db->createCommand('SELECT id FROM {{%pos_operator_sessions}} WHERE id=:id FOR UPDATE', [':id' => (int) $session->id])->queryScalar();
            $current = PosOperatorSessions::find()->where(['id' => (int) $session->id])->with('user')->one();
            $inScope = $current !== null && PosApi::sessionUsable($current) && $profile !== false && (int) $profile['merchant_id'] === $merchant
                && (int) $profile['is_enabled'] === 1 && (string) $grant === '1' && $locked !== false && (int) $locked['is_enabled'] === 1
                && (int) $locked['merchant_id'] === $merchant && (int) $locked['store_id'] === (int) $terminal['store_id'];
            if (!$inScope) throw new OpeningException(OpeningException::NOT_FOUND);
            $replay = $db->createCommand('SELECT * FROM ' . self::TABLE . ' WHERE session_id=:s AND idempotency_sha256=:k FOR UPDATE',
                [':s' => (int) $session->id, ':k' => $key])->queryOne();
            if ($replay !== false) {
                if (!hash_equals($replay['request_sha256'], $request)) throw new OpeningException(OpeningException::IDEMPOTENCY);
                if ($replay['state'] !== 'active') throw new OpeningException(OpeningException::INVALID);
                return [200, self::publicOpening($replay, $terminalId)];
            }
            $active = $db->createCommand('SELECT * FROM ' . self::TABLE . ' WHERE terminal_id=:t AND state=\'active\' FOR UPDATE', [':t' => (int) $terminal['id']])->queryOne();
            $now = time();
            if ($active !== false) {
                // Titolare ancora in grado di agire: nessuna sostituzione, nemmeno per lo stesso utente (altra scheda).
                if (self::ownerCanAct($active, $terminal)) throw new OpeningException(OpeningException::IN_USE);
                $db->createCommand()->update(self::TABLE, ['state' => 'revoked', 'closed_at' => $now, 'closed_reason' => 'owner_ineligible'],
                    ['id' => (int) $active['id'], 'state' => 'active'])->execute();
            }
            $generation = (int) $db->createCommand('SELECT COALESCE(MAX(generation), 0) + 1 FROM ' . self::TABLE . ' WHERE terminal_id=:t', [':t' => (int) $terminal['id']])->queryScalar();
            $row = ['public_id' => self::uuid(), 'terminal_id' => (int) $terminal['id'], 'merchant_id' => $merchant, 'session_id' => (int) $session->id,
                'user_id' => $user, 'instance_id' => $instanceId, 'generation' => $generation, 'state' => 'active',
                'idempotency_sha256' => $key, 'request_sha256' => $request, 'opened_at' => $now, 'last_seen_at' => $now];
            $db->createCommand()->insert(self::TABLE, $row)->execute();
            return [201, self::publicOpening($row, $terminalId)];
        });
    }

    /** Sola verifica: non acquisisce né riprende. */
    public static function view(PosOperatorSessions $session, string $openingId): array
    {
        $opening = self::own($session, $openingId);
        if (!self::usable($opening)) throw new OpeningException(OpeningException::INVALID);
        return self::publicOpening($opening, self::terminalPublicId((int) $opening['terminal_id']));
    }

    /**
     * SIP-021 fase 3: apertura propria della sessione, attiva, nello scope del titolare e con prova di istanza e
     * generazione. Altrui = 404, propria non più valida = 409. Lettura senza lock: chi scrive la riconferma sotto lock.
     */
    public static function requireUsable(PosOperatorSessions $session, string $openingId, string $instanceId, string $generation): array
    {
        $opening = self::own($session, $openingId);
        if (!self::proof($opening, $instanceId, $generation) || !self::usable($opening)) throw new OpeningException(OpeningException::INVALID);
        return $opening;
    }

    /** Registra il contatto (solo informativo per il manager); mai riattiva un'apertura chiusa. */
    public static function heartbeat(PosOperatorSessions $session, string $openingId, string $instanceId, string $generation): void
    {
        $opening = self::own($session, $openingId);
        if (!self::proof($opening, $instanceId, $generation) || !self::usable($opening)) throw new OpeningException(OpeningException::INVALID);
        $changed = Yii::$app->db->createCommand('UPDATE ' . self::TABLE . ' SET last_seen_at=GREATEST(last_seen_at, :now)'
            . ' WHERE id=:id AND state=\'active\' AND instance_id=:i AND generation=:g',
            [':now' => time(), ':id' => (int) $opening['id'], ':i' => $instanceId, ':g' => (int) $generation])->execute();
        if ($changed !== 1 && self::row((int) $opening['id'])['state'] !== 'active') throw new OpeningException(OpeningException::INVALID);
    }

    /** Chiusura normale del titolare; ripetuta su un'apertura già chiusa da lui stesso = nessun errore. */
    public static function release(PosOperatorSessions $session, string $openingId, string $instanceId, string $generation): void
    {
        $opening = self::own($session, $openingId);
        if (!self::proof($opening, $instanceId, $generation)) throw new OpeningException(OpeningException::INVALID);
        $changed = Yii::$app->db->createCommand()->update(self::TABLE, ['state' => 'released', 'closed_at' => time(), 'closed_reason' => 'released'],
            ['id' => (int) $opening['id'], 'session_id' => (int) $session->id, 'state' => 'active', 'instance_id' => $instanceId, 'generation' => (int) $generation])->execute();
        if ($changed === 1) return;
        $now = self::row((int) $opening['id']);
        if ($now['state'] !== 'released') throw new OpeningException(OpeningException::INVALID);
    }

    /** Logout: chiude le aperture attive della sessione, nella transazione del chiamante. */
    public static function closeForSession(Connection $db, int $sessionId): int
    {
        return $db->createCommand()->update(self::TABLE, ['state' => 'released', 'closed_at' => time(), 'closed_reason' => 'logout'],
            ['session_id' => $sessionId, 'state' => 'active'])->execute();
    }

    /**
     * Sblocco forzato del manager, dentro la transazione C4 che ha già bloccato merchant e terminale.
     * @return bool false se il terminale non aveva aperture attive
     */
    public static function unlock(Connection $db, int $terminalId, int $actorId): bool
    {
        $active = $db->createCommand('SELECT id FROM ' . self::TABLE . ' WHERE terminal_id=:t AND state=\'active\' FOR UPDATE', [':t' => $terminalId])->queryScalar();
        if ($active === false) return false;
        $db->createCommand()->update(self::TABLE, ['state' => 'revoked', 'closed_at' => time(), 'closed_reason' => 'manager_unlock', 'closed_by' => $actorId],
            ['id' => (int) $active, 'state' => 'active'])->execute();
        return true;
    }

    /** Aperture attive per il backoffice: terminale interno => operatore, apertura e ultimo contatto. */
    public static function activeFor(array $terminalIds): array
    {
        if ($terminalIds === []) return [];
        $rows = (new \yii\db\Query())->select(['o.terminal_id', 'o.opened_at', 'o.last_seen_at', 'u.first_name', 'u.last_name'])
            ->from(['o' => self::TABLE])->innerJoin(['u' => '{{%users}}'], 'u.id=o.user_id')
            ->where(['o.terminal_id' => array_map('intval', $terminalIds), 'o.state' => 'active'])->all();
        return array_column($rows, null, 'terminal_id');
    }

    private static function own(PosOperatorSessions $session, string $openingId): array
    {
        if (preg_match(self::UUID, $openingId) !== 1) throw new OpeningException(OpeningException::NOT_FOUND);
        $opening = Yii::$app->db->createCommand('SELECT * FROM ' . self::TABLE . ' WHERE public_id=:p', [':p' => $openingId])->queryOne();
        // Apertura di un'altra sessione (anche dello stesso utente): non esiste per questo chiamante.
        if ($opening === false || (int) $opening['session_id'] !== (int) $session->id) throw new OpeningException(OpeningException::NOT_FOUND);
        return $opening;
    }

    private static function proof(array $opening, string $instanceId, string $generation): bool
    {
        return hash_equals($opening['instance_id'], $instanceId) && preg_match('/^[1-9][0-9]{0,18}$/D', $generation) === 1
            && (string) $opening['generation'] === $generation;
    }

    /** Attiva e ancora nello scope del titolare: profilo dello stesso merchant, grant del negozio, terminale abilitato. */
    private static function usable(array $opening): bool
    {
        if ($opening['state'] !== 'active') return false;
        $terminal = Yii::$app->db->createCommand('SELECT id, merchant_id, store_id, is_enabled FROM {{%pos_terminals}} WHERE id=:id', [':id' => (int) $opening['terminal_id']])->queryOne();
        return $terminal !== false && (int) $terminal['is_enabled'] === 1 && (int) $terminal['merchant_id'] === (int) $opening['merchant_id']
            && self::ownerCanAct($opening, $terminal);
    }

    private static function ownerCanAct(array $opening, array $terminal): bool
    {
        $owner = PosOperatorSessions::find()->where(['id' => (int) $opening['session_id']])->with('user')->one();
        if ($owner === null || !PosApi::sessionUsable($owner)) return false;
        $db = Yii::$app->db;
        $profile = $db->createCommand('SELECT merchant_id FROM {{%pos_operators}} WHERE user_id=:u AND is_enabled=1', [':u' => (int) $opening['user_id']])->queryScalar();
        $grant = $db->createCommand('SELECT is_enabled FROM {{%pos_store_operators}} WHERE user_id=:u AND store_id=:s AND merchant_id=:m',
            [':u' => (int) $opening['user_id'], ':s' => (int) $terminal['store_id'], ':m' => (int) $terminal['merchant_id']])->queryScalar();
        return (int) $owner->user_id === (int) $opening['user_id'] && $profile !== false && (int) $profile === (int) $terminal['merchant_id'] && (string) $grant === '1';
    }

    private static function transaction(Connection $db, callable $work)
    {
        if ($db->getTransaction() !== null) throw new \LogicException('caller_transaction_open');
        $transaction = $db->beginTransaction(Transaction::READ_COMMITTED);
        try {
            $result = $work();
            $transaction->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($transaction->isActive) $transaction->rollBack();
            throw $error;
        }
    }

    private static function row(int $id): array
    {
        return Yii::$app->db->createCommand('SELECT * FROM ' . self::TABLE . ' WHERE id=:id', [':id' => $id])->queryOne();
    }

    private static function terminalPublicId(int $id): string
    {
        return (string) Yii::$app->db->createCommand('SELECT public_id FROM {{%pos_terminals}} WHERE id=:id', [':id' => $id])->queryScalar();
    }

    private static function publicOpening(array $row, string $terminalId): array
    {
        return ['id' => $row['public_id'], 'terminal_id' => $terminalId, 'instance_id' => $row['instance_id'], 'generation' => (string) $row['generation'], 'state' => 'active'];
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
