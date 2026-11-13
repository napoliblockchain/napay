<?php
namespace app\components\btcpay\provisioning;

use yii\db\Connection;
use yii\db\Transaction;

/**
 * SIP-022 B1c: coda dei lavori di provisioning (presa, lease, checkpoint, esiti) su btcpay_provisioning_jobs.
 * Solo ID, stati e checkpoint: nessun segreto, nessun log. Ogni scrittura è un CAS su ID, owner, versione e
 * lease non scaduta; un esito tardivo di un worker scaduto non cambia nulla (null).
 * Non serializza la risorsa: lock del mapping e ricontrolli tenant/idoneità restano all'orchestratore.
 */
final class ProvisioningJobs
{
    public const OPERATIONS = ['create_user', 'create_api_key', 'create_store', 'create_pos_app', 'revoke_api_key'];
    /** Risposte remote che provano la mancata esecuzione: solo queste riportano un lavoro in volo a running. */
    public const REMOTE_NOT_EXECUTED = [429];
    public const MAX_ATTEMPTS = 1000;
    private const TABLE = 'btcpay_provisioning_jobs';
    private const SCAN = 20;
    private const CLEAR_LEASE = ', lease_owner = NULL, lease_until = NULL';
    private const CLEAR_FLIGHT = ', in_flight_op = NULL, in_flight_started_at = NULL, in_flight_owner = NULL';

    private readonly \Closure $clock;

    public function __construct(private readonly Connection $db, private readonly string $owner, private readonly int $leaseSeconds = 60, ?\Closure $clock = null)
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $owner) !== 1) throw new \InvalidArgumentException('invalid_owner');
        if ($leaseSeconds < 1 || $leaseSeconds > 3600) throw new \InvalidArgumentException('invalid_lease');
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * Prende il primo lavoro dovuto dell'istanza. In volo con lease scaduta o assente (marker presente, qualunque
     * stato attivo) -> reconcile_required, mai ripreso; tentativi esauriti -> failed; running scaduto senza
     * checkpoint -> ripreso con nuovo owner. Restituisce solo identificativi e versione, o null.
     */
    public function claim(string $instance): ?array
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $instance) !== 1) throw new \InvalidArgumentException('invalid_instance');
        $this->guard();
        $now = $this->now();
        $transaction = $this->db->beginTransaction(Transaction::READ_COMMITTED);
        try {
            for ($i = 0; $i < self::SCAN; ++$i) {
                $job = $this->db->createCommand('SELECT id, kind, instance, merchant_id, entity_id, generation, state, attempts, version, in_flight_op FROM ' . self::TABLE
                    . " WHERE instance = :instance AND ((state IN ('pending', 'retry_wait', 'deferred') AND (next_attempt_at <= :now OR in_flight_op IS NOT NULL))"
                    . " OR (state IN ('running', 'in_flight') AND (lease_until IS NULL OR lease_until <= :now)))"
                    . ' ORDER BY next_attempt_at, id LIMIT 1 FOR UPDATE SKIP LOCKED', [':instance' => $instance, ':now' => $now])->queryOne();
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

    /** Checkpoint prima della chiamata remota: running senza marker -> in_flight, lease rinnovata. */
    public function checkpoint(int $id, int $version, string $operation): ?int
    {
        if (!in_array($operation, self::OPERATIONS, true)) throw new \InvalidArgumentException('invalid_operation');
        $now = $this->now();
        return $this->cas($id, $version, ['running'], true, "state = 'in_flight', in_flight_op = :op, in_flight_started_at = :now, in_flight_owner = :owner, lease_until = :until",
            [':op' => $operation, ':until' => $now + $this->leaseSeconds], $now);
    }

    /** Esito remoto positivo (o lavoro concluso senza chiamata): done, lease e marker azzerati. */
    public function succeed(int $id, int $version): ?int
    {
        return $this->cas($id, $version, ['running', 'in_flight'], false, "state = 'done', last_error_code = NULL" . self::CLEAR_LEASE . self::CLEAR_FLIGHT, [], $this->now());
    }

    /** Errore definitivo con esito remoto noto: failed, lease e marker azzerati. */
    public function fail(int $id, int $version, string $code): ?int
    {
        return $this->cas($id, $version, ['running', 'in_flight'], false, "state = 'failed', last_error_code = :code" . self::CLEAR_LEASE . self::CLEAR_FLIGHT, [':code' => self::code($code)], $this->now());
    }

    /** Esito remoto ignoto o ambiguo: reconcile_required, marker conservati. */
    public function reconcile(int $id, int $version, string $code): ?int
    {
        return $this->cas($id, $version, ['running', 'in_flight'], false, "state = 'reconcile_required', last_error_code = :code" . self::CLEAR_LEASE, [':code' => self::code($code)], $this->now());
    }

    /** In volo -> running solo con una risposta remota che prova la mancata esecuzione (REMOTE_NOT_EXECUTED). */
    public function remoteRejected(int $id, int $version, int $httpStatus): ?int
    {
        if (!in_array($httpStatus, self::REMOTE_NOT_EXECUTED, true)) throw new \InvalidArgumentException('outcome_not_proven');
        return $this->cas($id, $version, ['in_flight'], false, "state = 'running'" . self::CLEAR_FLIGHT, [], $this->now());
    }

    /** Nuovo tentativo a un istante dato (calcolo dell'attesa al chiamante); solo da running senza checkpoint. */
    public function retryLater(int $id, int $version, string $code, int $nextAttemptAt, bool $deferred = false): ?int
    {
        $now = $this->now();
        if ($nextAttemptAt <= $now) throw new \InvalidArgumentException('invalid_next_attempt');
        return $this->cas($id, $version, ['running'], true, 'state = :state, last_error_code = :code, next_attempt_at = :next' . self::CLEAR_LEASE,
            [':state' => $deferred ? 'deferred' : 'retry_wait', ':code' => self::code($code), ':next' => $nextAttemptAt], $now);
    }

    /** Una sola UPDATE: vale solo con stesso owner, stessa versione e lease ancora valida. Restituisce la nuova versione. */
    private function cas(int $id, int $version, array $states, bool $noCheckpoint, string $set, array $params, int $now): ?int
    {
        if ($id < 1 || $version < 0 || $version >= 2147483647) throw new \InvalidArgumentException('invalid_job');
        $this->guard();
        $in = [];
        foreach ($states as $n => $state) { $in[] = ':s' . $n; $params[':s' . $n] = $state; }
        $changed = $this->db->createCommand('UPDATE ' . self::TABLE . ' SET ' . $set . ', version = version + 1, updated_at = :now'
            . ' WHERE id = :id AND version = :version AND lease_owner = :owner AND lease_until > :now AND state IN (' . implode(', ', $in) . ')'
            . ($noCheckpoint ? ' AND in_flight_op IS NULL' : ''),
            $params + [':id' => $id, ':version' => $version, ':owner' => $this->owner, ':now' => $now])->execute();
        return $changed === 1 ? $version + 1 : null;
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
