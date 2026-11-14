<?php
namespace app\components\btcpay\provisioning;

use yii\db\Connection;
use yii\db\Transaction;

/**
 * SIP-022 B1c: coda dei lavori di provisioning (presa, lease, checkpoint, esiti) su btcpay_provisioning_jobs.
 * Solo ID, stati e checkpoint: nessun segreto, nessun log. Ogni scrittura è un CAS su ID, owner, versione e
 * lease non scaduta; un esito tardivo di un worker scaduto non cambia nulla (null).
 * Non serializza la risorsa: lock del mapping e ricontrolli tenant/idoneità restano all'orchestratore.
 * W3a: ogni esito accetta un callback opzionale che modifica mapping/credenziali nella stessa transazione INTERNA
 * della coda, eseguito solo dopo aver bloccato la riga e verificato owner, versione e lease; il CAS finale usa un
 * tempo fresco. Lease scaduta, versione cambiata o callback in errore = rollback di tutto. HTTP sempre fuori.
 */
final class ProvisioningJobs
{
    public const OPERATIONS = ['create_user', 'create_api_key', 'create_store', 'create_pos_app', 'revoke_api_key'];
    /** Risposte remote che provano la mancata esecuzione: solo queste riportano un lavoro in volo a running. */
    public const REMOTE_NOT_EXECUTED = [429];
    public const MAX_ATTEMPTS = 1000;
    public const KINDS = ['merchant_account', 'store_link', 'pos_link', 'key_rotation', 'key_revoke'];
    private const TABLE = 'btcpay_provisioning_jobs';
    private const SCAN = 20;
    private const CLEAR_LEASE = ', lease_owner = NULL, lease_until = NULL';
    private const CLEAR_FLIGHT = ', in_flight_op = NULL, in_flight_started_at = NULL, in_flight_owner = NULL';

    private readonly \Closure $clock;

    public function __construct(#[\SensitiveParameter] private readonly Connection $db, private readonly string $owner, private readonly int $leaseSeconds = 60, #[\SensitiveParameter] ?\Closure $clock = null)
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $owner) !== 1) throw new \InvalidArgumentException('invalid_owner');
        if ($leaseSeconds < 1 || $leaseSeconds > 3600) throw new \InvalidArgumentException('invalid_lease');
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * Prende il primo lavoro dovuto dell'istanza, mai con una lease ancora valida. deferred non è mai preso: richiede
     * l'admin (SIP B1 §4). Marker in volo con lease scaduta o assente (qualunque stato attivo) -> reconcile_required,
     * mai ripreso; tentativi esauriti -> failed; running scaduto senza checkpoint -> ripreso con nuovo owner.
     * Restituisce solo identificativi e versione, o null.
     */
    public function claim(string $instance, ?array $kinds = null): ?array
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $instance) !== 1) throw new \InvalidArgumentException('invalid_instance');
        // W3b: filtro facoltativo per tipo (default invariato: tutti i tipi).
        $filter = ''; $kindParams = [];
        if ($kinds !== null) {
            if ($kinds === [] || array_diff($kinds, self::KINDS) !== []) throw new \InvalidArgumentException('invalid_kind');
            foreach (array_values(array_unique($kinds)) as $n => $kind) $kindParams[':k' . $n] = $kind;
            $filter = ' AND kind IN (' . implode(', ', array_keys($kindParams)) . ')';
        }
        $this->guard();
        $now = $this->now();
        $transaction = $this->db->beginTransaction(Transaction::READ_COMMITTED);
        try {
            for ($i = 0; $i < self::SCAN; ++$i) {
                $job = $this->db->createCommand('SELECT id, kind, instance, merchant_id, entity_id, generation, state, attempts, version, in_flight_op FROM ' . self::TABLE
                    . " WHERE instance = :instance" . $filter . " AND (lease_until IS NULL OR lease_until <= :now)"
                    . " AND ((state IN ('pending', 'retry_wait') AND (next_attempt_at <= :now OR in_flight_op IS NOT NULL)) OR state IN ('running', 'in_flight'))"
                    . ' ORDER BY next_attempt_at, id LIMIT 1 FOR UPDATE SKIP LOCKED', [':instance' => $instance, ':now' => $now] + $kindParams)->queryOne();
                if ($job === false) break;
                $where = ' WHERE id = :id AND version = :version';
                $params = [':id' => $job['id'], ':version' => $job['version'], ':now' => $now];
                if ($job['in_flight_op'] !== null) {
                    // Esito remoto ignoto: solo riconciliazione, i marker restano come prova del passo.
                    $this->db->createCommand('UPDATE ' . self::TABLE . " SET state = 'reconcile_required', lease_owner = NULL, lease_until = NULL, last_error_code = 'in_flight_expired', version = version + 1, updated_at = :now" . $where, $params)->execute();
                    continue;
                }
                if ((int) $job['attempts'] >= self::MAX_ATTEMPTS) {
                    $this->db->createCommand('UPDATE ' . self::TABLE . " SET state = 'failed', lease_owner = NULL, lease_until = NULL, last_error_code = 'attempts_exhausted', version = version + 1, updated_at = :now" . $where, $params)->execute();
                    continue;
                }
                $this->db->createCommand('UPDATE ' . self::TABLE . " SET state = 'running', lease_owner = :owner, lease_until = :until, attempts = attempts + 1, version = version + 1, updated_at = :now" . $where,
                    $params + [':owner' => $this->owner, ':until' => $now + $this->leaseSeconds])->execute();
                $transaction->commit();
                return ['id' => (int) $job['id'], 'kind' => $job['kind'], 'instance' => $job['instance'], 'merchant_id' => (int) $job['merchant_id'],
                    'entity_id' => (int) $job['entity_id'], 'generation' => (int) $job['generation'], 'attempts' => (int) $job['attempts'] + 1,
                    'version' => (int) $job['version'] + 1, 'lease_until' => $now + $this->leaseSeconds];
            }
            $transaction->commit();
            return null;
        } catch (\Throwable $error) {
            if ($transaction->isActive) $transaction->rollBack();
            throw $error;
        }
    }

    /** Guarded local preparation/read snapshot; no HTTP marker, no transaction owned by the caller. */
    public function prepare(int $id, int $version, #[\SensitiveParameter] callable $mutate): ?int
    {
        $now = $this->now();
        return $this->cas($id, $version, ['running'], true, "state = 'running', lease_until = :until",
            [':until' => $now + $this->leaseSeconds], $now, $mutate);
    }

    /** Checkpoint prima della chiamata remota: running senza marker -> in_flight, lease rinnovata. */
    public function checkpoint(int $id, int $version, string $operation, #[\SensitiveParameter] ?callable $mutate = null): ?int
    {
        if (!in_array($operation, self::OPERATIONS, true)) throw new \InvalidArgumentException('invalid_operation');
        $now = $this->now();
        return $this->cas($id, $version, ['running'], true, "state = 'in_flight', in_flight_op = :op, in_flight_started_at = :now, in_flight_owner = :owner, lease_until = :until",
            [':op' => $operation, ':until' => $now + $this->leaseSeconds], $now, $mutate);
    }

    /**
     * W3b: passo remoto riuscito ma lavoro non concluso (es. utente creato, ora la chiave): in_flight -> running,
     * marker azzerati, lease rinnovata. Il mapping del passo va scritto nel callback, nella stessa transazione.
     */
    public function advance(int $id, int $version, #[\SensitiveParameter] callable $mutate): ?int
    {
        $now = $this->now();
        return $this->cas($id, $version, ['in_flight'], false, "state = 'running', lease_until = :until" . self::CLEAR_FLIGHT,
            [':until' => $now + $this->leaseSeconds], $now, $mutate);
    }

    /** Esito remoto positivo (o lavoro concluso senza chiamata): done, lease e marker azzerati. */
    public function succeed(int $id, int $version, #[\SensitiveParameter] ?callable $mutate = null): ?int
    {
        return $this->cas($id, $version, ['running', 'in_flight'], false, "state = 'done', last_error_code = NULL" . self::CLEAR_LEASE . self::CLEAR_FLIGHT, [], $this->now(), $mutate);
    }

    /** Errore definitivo con esito remoto noto: failed, lease e marker azzerati. */
    public function fail(int $id, int $version, string $code, #[\SensitiveParameter] ?callable $mutate = null): ?int
    {
        return $this->cas($id, $version, ['running', 'in_flight'], false, "state = 'failed', last_error_code = :code" . self::CLEAR_LEASE . self::CLEAR_FLIGHT, [':code' => self::code($code)], $this->now(), $mutate);
    }

    /** Esito remoto ignoto o ambiguo: reconcile_required, marker conservati. */
    public function reconcile(int $id, int $version, string $code, #[\SensitiveParameter] ?callable $mutate = null): ?int
    {
        return $this->cas($id, $version, ['running', 'in_flight'], false, "state = 'reconcile_required', last_error_code = :code" . self::CLEAR_LEASE, [':code' => self::code($code)], $this->now(), $mutate);
    }

    /** In volo -> running solo con una risposta remota che prova la mancata esecuzione (REMOTE_NOT_EXECUTED). */
    public function remoteRejected(int $id, int $version, int $httpStatus, #[\SensitiveParameter] ?callable $mutate = null): ?int
    {
        if (!in_array($httpStatus, self::REMOTE_NOT_EXECUTED, true)) throw new \InvalidArgumentException('outcome_not_proven');
        return $this->cas($id, $version, ['in_flight'], false, "state = 'running'" . self::CLEAR_FLIGHT, [], $this->now(), $mutate);
    }

    /** Nuovo tentativo a un istante dato (calcolo dell'attesa al chiamante); solo da running senza checkpoint. deferred: poi solo admin. */
    public function retryLater(int $id, int $version, string $code, int $nextAttemptAt, bool $deferred = false, #[\SensitiveParameter] ?callable $mutate = null): ?int
    {
        $now = $this->now();
        if ($nextAttemptAt <= $now) throw new \InvalidArgumentException('invalid_next_attempt');
        return $this->cas($id, $version, ['running'], true, 'state = :state, last_error_code = :code, next_attempt_at = :next' . self::CLEAR_LEASE,
            [':state' => $deferred ? 'deferred' : 'retry_wait', ':code' => self::code($code), ':next' => $nextAttemptAt], $now, $mutate);
    }

    /**
     * Una sola UPDATE: vale solo con stesso owner, stessa versione e lease ancora valida. Restituisce la nuova versione.
     * Con $mutate: transazione interna, riga del lavoro bloccata e owner/versione/lease verificati PRIMA del callback (nessuna
     * chiamata se già invalido), poi CAS con tempo fresco; qualunque fallimento annulla anche le modifiche del callback.
     */
    private function cas(int $id, int $version, array $states, bool $noCheckpoint, string $set, array $params, int $now, #[\SensitiveParameter] ?callable $mutate = null): ?int
    {
        if ($id < 1 || $version < 0 || $version >= 2147483647) throw new \InvalidArgumentException('invalid_job');
        $this->guard();
        $in = [];
        foreach ($states as $n => $state) { $in[] = ':s' . $n; $params[':s' . $n] = $state; }
        $sql = 'UPDATE ' . self::TABLE . ' SET ' . $set . ', version = version + 1, updated_at = :now'
            . ' WHERE id = :id AND version = :version AND lease_owner = :owner AND lease_until > :now AND state IN (' . implode(', ', $in) . ')'
            . ($noCheckpoint ? ' AND in_flight_op IS NULL' : '');
        $params += [':id' => $id, ':version' => $version, ':owner' => $this->owner];
        if ($mutate === null) return $this->db->createCommand($sql, $params + [':now' => $now])->execute() === 1 ? $version + 1 : null;
        $transaction = $this->db->beginTransaction(Transaction::READ_COMMITTED);
        try {
            $row = $this->db->createCommand('SELECT state, version, lease_owner, lease_until, in_flight_op FROM ' . self::TABLE . ' WHERE id = :id FOR UPDATE', [':id' => $id])->queryOne();
            $locked = $this->now();
            if ($row === false || (int) $row['version'] !== $version || $row['lease_owner'] !== $this->owner || $row['lease_until'] === null
                || (int) $row['lease_until'] <= $locked || !in_array($row['state'], $states, true) || ($noCheckpoint && $row['in_flight_op'] !== null)) {
                $transaction->rollBack();
                return null;
            }
            $mutate($this->db);
            // Tempo fresco: una lease scaduta durante il callback invalida l'intero esito.
            $fresh = $this->now();
            if (array_key_exists(':until', $params)) $params[':until'] = $fresh + $this->leaseSeconds;
            if ($this->db->createCommand($sql, $params + [':now' => $fresh])->execute() !== 1) {
                $transaction->rollBack();
                return null;
            }
            $transaction->commit();
            return $version + 1;
        } catch (\Throwable $error) {
            if ($transaction->isActive) $transaction->rollBack();
            throw $error;
        }
    }

    /** La coda non entra in transazioni del chiamante: un suo rollback annullerebbe presa o esito già comunicati. */
    private function guard(): void
    {
        if ($this->db->getTransaction() !== null) throw new \LogicException('caller_transaction_open');
    }

    private function now(): int
    {
        $now = ($this->clock)();
        if (!is_int($now) || $now < 0) throw new \UnexpectedValueException('invalid_clock');
        return $now;
    }

    private static function code(string $code): string
    {
        if (preg_match('/^[a-z0-9_]{1,64}$/D', $code) !== 1) throw new \InvalidArgumentException('invalid_error_code');
        return $code;
    }
}
