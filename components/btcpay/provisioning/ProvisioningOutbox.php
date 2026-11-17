<?php
namespace app\components\btcpay\provisioning;

use Yii;
use app\components\identity\AccountEligibility;
use yii\base\InvalidConfigException;
use yii\db\Connection;
use yii\db\IntegrityException;

/**
 * SIP-022 B1 W4a/W4b: accodamento web dei lavori `merchant_account` / `store_link` / `pos_link` (generazione 1) nella
 * STESSA transazione che attiva il merchant o crea negozio o terminale: rollback del chiamante = nessun lavoro. Solo le colonne WEB_ENQUEUE concesse al
 * web (INSERT esplicito: nessun UPDATE, nessun INSERT IGNORE); il duplicato 1062 dello stesso scope è un replay
 * idempotente, qualunque altro errore risale. Nessuna chiave, credenziale o chiamata HTTP nel web.
 * BTCPAY_PROVISION_INSTANCE (digest pubblico SHA-256 dell'origine del worker): vuota = funzione non attiva,
 * nessun lavoro; non vuota e malformata = eccezione (la transazione del chiamante va annullata).
 * La Connection (contiene la password del DB) è sempre un parametro sensibile: mai negli argomenti delle trace.
 */
final class ProvisioningOutbox
{
    private const TABLE = 'btcpay_provisioning_jobs';

    /**
     * Merchant appena attivato (gestore PG promosso): lavoro merchant_account con entity_id = merchant_id.
     * Scope: righe merchant e titolare bloccate in lettura, merchantForManager(titolare) deve restituire proprio
     * questo merchant (attivo, ruolo merchant, PG, merchant unico), letto sulla stessa connessione della transazione.
     */
    public static function enqueueMerchant(#[\SensitiveParameter] Connection $db, int $merchantId, int $actorId): bool
    {
        self::ids($merchantId, $actorId);
        $instance = self::open($db);
        if ($instance === null) return false;
        if (Yii::$app->db !== $db) throw new \LogicException('connection_mismatch');
        $owner = $db->createCommand('SELECT user_id FROM {{%merchants}} WHERE id=:id FOR SHARE', [':id' => $merchantId])->queryScalar();
        if ($owner === false || $owner === null
            || $db->createCommand('SELECT id FROM {{%users}} WHERE id=:id FOR SHARE', [':id' => (int) $owner])->queryScalar() === false
            || AccountEligibility::merchantForManager((int) $owner) !== $merchantId) throw new \InvalidArgumentException('scope_mismatch');
        return self::insert($db, 'merchant_account', $instance, $merchantId, $merchantId, $actorId);
    }

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

    /**
     * SIP-022 passo 3: impostazioni dello store BTCPay da allineare (creazione del negozio, nome o valuta cambiati).
     * Ogni richiesta è una nuova generazione di `store_update`; il worker legge i valori correnti da Napay all'esecuzione.
     */
    public static function enqueueStoreSettings(#[\SensitiveParameter] Connection $db, int $storeId, int $merchantId, int $actorId): bool
    {
        return self::enqueueNext($db, 'store_update', $storeId, $merchantId, $actorId);
    }

    /**
     * SIP-022 passo 3b: negozio eliminato in Napay (storicizzato nella stessa transazione): il worker rimuove le app POS
     * dello store BTCPay e lo rinomina come archiviato. Nessun effetto se lo store non era mai stato collegato.
     */
    public static function enqueueStoreArchive(#[\SensitiveParameter] Connection $db, int $storeId, int $merchantId, int $actorId): bool
    {
        return self::enqueueNext($db, 'store_archive', $storeId, $merchantId, $actorId);
    }

    /**
     * SIP-022 xpub: wallet BTC del negozio impostato, cambiato o rimosso (storesettings.derivationScheme, stessa transazione).
     * Il worker imposta o toglie il metodo di pagamento on-chain dello store BTCPay con il valore letto all'esecuzione.
     */
    public static function enqueueStoreWallet(#[\SensitiveParameter] Connection $db, int $storeId, int $merchantId, int $actorId): bool
    {
        return self::enqueueNext($db, 'store_wallet', $storeId, $merchantId, $actorId);
    }

    /** Lavoro di negozio con la generazione successiva (le richieste si accumulano, il worker legge i dati all'esecuzione). */
    private static function enqueueNext(#[\SensitiveParameter] Connection $db, string $kind, int $storeId, int $merchantId, int $actorId): bool
    {
        self::ids($storeId, $merchantId, $actorId);
        $instance = self::open($db);
        if ($instance === null) return false;
        $owner = $db->createCommand('SELECT merchant_id FROM {{%stores}} WHERE id=:id FOR SHARE', [':id' => $storeId])->queryScalar();
        if ($owner === false || (int) $owner !== $merchantId) throw new \InvalidArgumentException('scope_mismatch');
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $generation = 1 + (int) $db->createCommand('SELECT COALESCE(MAX(generation), 0) FROM {{%' . self::TABLE . '}} WHERE kind=:k AND instance=:i AND entity_id=:e',
                [':k' => $kind, ':i' => $instance, ':e' => $storeId])->queryScalar();
            try {
                $db->createCommand('INSERT INTO {{%' . self::TABLE . '}} (kind, instance, merchant_id, entity_id, generation, requested_by, created_at, updated_at)'
                    . ' VALUES (:kind, :instance, :merchant, :entity, :generation, :actor, :now, :now)',
                    [':kind' => $kind, ':instance' => $instance, ':merchant' => $merchantId, ':entity' => $storeId, ':generation' => $generation, ':actor' => $actorId, ':now' => time()])->execute();
                return true;
            } catch (IntegrityException $e) {
                // richiesta concorrente con la stessa generazione: si ricalcola
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062) throw $e;
            }
        }
        throw new \UnexpectedValueException($kind . '_not_queued');
    }

    /** Stati dell'ultimo lavoro di store/terminale che l'admin può riprovare (SIP-022 B5). */
    public const RETRYABLE = ['failed', 'deferred', 'reconcile_required'];

    /** Ultimo lavoro (generazione più alta) dell'entità (merchant, negozio o terminale), null se nessuno o funzione spenta. */
    public static function lastJob(#[\SensitiveParameter] Connection $db, string $kind, int $entityId): ?array
    {
        if (!in_array($kind, ['merchant_account', 'store_link', 'pos_link', 'store_wallet'], true) || $entityId < 1) return null;
        $instance = self::instance();
        if ($instance === null) return null;
        $row = $db->createCommand('SELECT merchant_id, generation, state, last_error_code FROM {{%' . self::TABLE . '}} WHERE kind=:k AND instance=:i AND entity_id=:e ORDER BY generation DESC LIMIT 1',
            [':k' => $kind, ':i' => $instance, ':e' => $entityId])->queryOne();
        return $row === false ? null : ['merchant_id' => (int) $row['merchant_id'], 'generation' => (int) $row['generation'], 'state' => (string) $row['state'],
            'last_error_code' => $row['last_error_code'] === null ? null : (string) $row['last_error_code']];
    }

    /**
     * Riprova dell'admin (SIP-022 B5): nuovo lavoro con la generazione successiva, mai una modifica dei lavori esistenti.
     * Account merchant, negozio o terminale con ultimo lavoro in RETRYABLE e scope ancora coerente (transazione del chiamante).
     * Doppio clic o richiesta concorrente: il vincolo unico fa vincere una sola riga (true per entrambe).
     * @return bool false se non riprovabile o funzione spenta
     */
    public static function retry(#[\SensitiveParameter] Connection $db, string $kind, int $entityId, int $adminId): bool
    {
        self::ids($entityId, $adminId);
        $instance = self::open($db);
        if ($instance === null) return false;
        $last = self::lastJob($db, $kind, $entityId);
        if ($last === null || !in_array($last['state'], self::RETRYABLE, true)) return false;
        $merchant = $last['merchant_id'];
        if ($kind === 'merchant_account') {
            // come all'attivazione: gestore PG qualificato con questo solo merchant, righe bloccate in lettura
            $owner = $db->createCommand('SELECT user_id FROM {{%merchants}} WHERE id=:id FOR SHARE', [':id' => $entityId])->queryScalar();
            if ($entityId !== $merchant || $owner === false || $owner === null || AccountEligibility::merchantForManager((int) $owner) !== $merchant) return false;
        } elseif ($kind === 'store_link') {
            $owner = $db->createCommand('SELECT merchant_id FROM {{%stores}} WHERE id=:id FOR SHARE', [':id' => $entityId])->queryScalar();
            if ($owner === false || (int) $owner !== $merchant) return false;
        } else {
            $row = $db->createCommand('SELECT t.merchant_id, s.merchant_id AS store_merchant FROM {{%pos_terminals}} t JOIN {{%stores}} s ON s.id = t.store_id WHERE t.id=:id FOR SHARE',
                [':id' => $entityId])->queryOne();
            if ($row === false || (int) $row['merchant_id'] !== $merchant || (int) $row['store_merchant'] !== $merchant) return false;
        }
        $now = time();
        try {
            $db->createCommand('INSERT INTO {{%' . self::TABLE . '}} (kind, instance, merchant_id, entity_id, generation, requested_by, created_at, updated_at)'
                . ' VALUES (:kind, :instance, :merchant, :entity, :generation, :actor, :now, :now)',
                [':kind' => $kind, ':instance' => $instance, ':merchant' => $merchant, ':entity' => $entityId, ':generation' => $last['generation'] + 1,
                    ':actor' => $adminId, ':now' => $now])->execute();
        } catch (IntegrityException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) throw $e;
        }
        return true;
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
        // Lettura corrente (FOR SHARE): uno snapshot REPEATABLE READ precedente non vedrebbe il lavoro appena committato altrove.
        $existing = $db->createCommand('SELECT merchant_id FROM {{%' . self::TABLE . '}} WHERE kind=:kind AND instance=:instance AND entity_id=:entity AND generation=1 FOR SHARE',
            [':kind' => $kind, ':instance' => $instance, ':entity' => $entityId])->queryScalar();
        if ($existing === false || (int) $existing !== $merchantId) throw new \UnexpectedValueException('job_scope_conflict');
        return true;
    }

    private static function ids(int ...$ids): void
    {
        foreach ($ids as $id) if ($id < 1) throw new \InvalidArgumentException('invalid_id');
    }
}
