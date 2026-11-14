<?php
namespace app\components\btcpay\provisioning;

use yii\base\InvalidConfigException;
use yii\db\Connection;
use yii\db\IntegrityException;

/**
 * SIP-022 B1 W4a: accodamento web dei lavori `store_link` / `pos_link` (generazione 1) nella STESSA transazione
 * che crea negozio o terminale: rollback del chiamante = nessun lavoro. Solo le colonne WEB_ENQUEUE concesse al
 * web (INSERT esplicito: nessun UPDATE, nessun INSERT IGNORE); il duplicato 1062 dello stesso scope è un replay
 * idempotente, qualunque altro errore risale. Nessuna chiave, credenziale o chiamata HTTP nel web.
 * BTCPAY_PROVISION_INSTANCE (digest pubblico SHA-256 dell'origine del worker): vuota = funzione non attiva,
 * nessun lavoro; non vuota e malformata = eccezione (la transazione del chiamante va annullata).
 * La Connection (contiene la password del DB) è sempre un parametro sensibile: mai negli argomenti delle trace.
 */
final class ProvisioningOutbox
{
    private const TABLE = 'btcpay_provisioning_jobs';

    /** Negozio appena creato: lavoro store_link. false se la funzione non è attiva, true se il lavoro esiste. */
    public static function enqueueStore(#[\SensitiveParameter] Connection $db, int $storeId, int $merchantId, int $actorId): bool
    {
        self::ids($storeId, $merchantId, $actorId);
        $instance = self::open($db);
        if ($instance === null) return false;
        $owner = $db->createCommand('SELECT merchant_id FROM {{%stores}} WHERE id=:id FOR SHARE', [':id' => $storeId])->queryScalar();
        if ($owner === false || (int) $owner !== $merchantId) throw new \InvalidArgumentException('scope_mismatch');
        return self::insert($db, 'store_link', $instance, $merchantId, $storeId, $actorId);
    }

    /** Terminale appena creato: lavoro pos_link, con terminale, negozio e merchant coerenti. */
    public static function enqueueTerminal(#[\SensitiveParameter] Connection $db, int $terminalId, int $storeId, int $merchantId, int $actorId): bool
    {
        self::ids($terminalId, $storeId, $merchantId, $actorId);
        $instance = self::open($db);
        if ($instance === null) return false;
        $row = $db->createCommand('SELECT t.store_id, t.merchant_id, s.merchant_id AS store_merchant FROM {{%pos_terminals}} t'
            . ' JOIN {{%stores}} s ON s.id = t.store_id WHERE t.id=:id FOR SHARE', [':id' => $terminalId])->queryOne();
        if ($row === false || (int) $row['store_id'] !== $storeId || (int) $row['merchant_id'] !== $merchantId || (int) $row['store_merchant'] !== $merchantId)
            throw new \InvalidArgumentException('scope_mismatch');
        return self::insert($db, 'pos_link', $instance, $merchantId, $terminalId, $actorId);
    }

    /** Digest dell'istanza configurata, null se la funzione non è attiva. */
    public static function instance(): ?string
    {
        $value = env('BTCPAY_PROVISION_INSTANCE', '');
        if ($value === '' || $value === null) return null;
        if (!is_string($value) || preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) throw new InvalidConfigException('invalid_provision_instance');
        return $value;
    }

    /** Transazione del chiamante obbligatoria anche a funzione spenta: l'errore d'uso emerge sempre. */
    private static function open(#[\SensitiveParameter] Connection $db): ?string
    {
        if ($db->getTransaction() === null) throw new \LogicException('transaction_required');
        return self::instance();
    }

    private static function insert(#[\SensitiveParameter] Connection $db, string $kind, string $instance, int $merchantId, int $entityId, int $actorId): bool
    {
        $now = time();
        try {
            $db->createCommand('INSERT INTO {{%' . self::TABLE . '}} (kind, instance, merchant_id, entity_id, generation, requested_by, created_at, updated_at)'
                . ' VALUES (:kind, :instance, :merchant, :entity, 1, :actor, :now, :now)',
                [':kind' => $kind, ':instance' => $instance, ':merchant' => $merchantId, ':entity' => $entityId, ':actor' => $actorId, ':now' => $now])->execute();
            return true;
        } catch (IntegrityException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) throw $e;
        }
        // Replay: il lavoro esistente deve avere lo stesso scope, mai adottarne uno di un altro merchant.
        $existing = $db->createCommand('SELECT merchant_id FROM {{%' . self::TABLE . '}} WHERE kind=:kind AND instance=:instance AND entity_id=:entity AND generation=1',
            [':kind' => $kind, ':instance' => $instance, ':entity' => $entityId])->queryScalar();
        if ($existing === false || (int) $existing !== $merchantId) throw new \UnexpectedValueException('job_scope_conflict');
        return true;
    }

    private static function ids(int ...$ids): void
    {
        foreach ($ids as $id) if ($id < 1) throw new \InvalidArgumentException('invalid_id');
    }
}
