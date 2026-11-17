<?php

namespace app\components\db;

/** Enforces B1 database privileges on dedicated existing accounts; never handles passwords. */
final class DatabaseRoles
{
    private const APP_TABLES = [
        'logs', 'merchants', 'np_comuni_italiani', 'privileges', 'settings', 'stores',
        'storesettings', 'user_consensus', 'users', 'webhooks', 'auth', 'association_roles',
        'membership_fees', 'payment_methods', 'member_profiles', 'membership_payments',
        'assembly_minutes', 'pos_operator_sessions', 'paypal_webhook_events',
        'btcpay_webhook_events', 'pos_operators', 'pos_store_operators', 'pos_terminals',
        'pos_operator_invitations', 'membership_reminders',
    ];
    private const B1_TABLES = [
        'btcpay_merchant_accounts', 'btcpay_merchant_credentials', 'btcpay_store_links',
        'btcpay_pos_links', 'btcpay_provisioning_jobs',
    ];
    // SIP-031: le segnalazioni di wallet perso non si cancellano (solo ritrovamento)
    private const WEB_HISTORY = ['pos_terminal_openings', 'pos_tts_payments', 'tts_lost_wallets'];
    /** wallet TTS del negozio: il gestore può anche rimuoverlo (le richieste create tengono l'indirizzo congelato) */
    private const WEB_DELETABLE = ['store_tts_wallets'];
    private const WEB_READ = [
        'istat_comuni' => ['codice', 'denominazione', 'provincia', 'sigla'],
        'btcpay_merchant_accounts' => ['id', 'instance', 'merchant_id', 'state', 'last_error_code', 'updated_at'],
        // remote_store_id (non segreto): il web riconosce lo store dei webhook in arrivo (SIP-022 passo 4)
        'btcpay_store_links' => ['id', 'instance', 'merchant_id', 'store_id', 'remote_store_id', 'state', 'last_error_code', 'updated_at'],
        'btcpay_pos_links' => ['id', 'instance', 'merchant_id', 'store_id', 'terminal_id', 'state', 'last_error_code', 'updated_at'],
        'btcpay_provisioning_jobs' => ['id', 'kind', 'instance', 'merchant_id', 'entity_id', 'generation', 'state', 'attempts', 'next_attempt_at', 'last_error_code', 'created_at', 'updated_at'],
    ];
    private const WEB_ENQUEUE = ['kind', 'instance', 'merchant_id', 'entity_id', 'generation', 'requested_by', 'created_at', 'updated_at'];
    private const WORKER_READ = [
        'users' => ['id', 'email', 'is_active', 'privilege_id'], 'privileges' => ['id', 'level'],
        'member_profiles' => ['user_id', 'is_corporate', 'denomination'],
        'membership_payments' => ['user_id'], 'pos_operators' => ['user_id'],
        'merchants' => ['id', 'user_id'], 'stores' => ['id', 'merchant_id', 'description', 'archived_at'],
        // SIP-022 passo 3: impostazioni dello store BTCPay (stesse colonne di components/btcpay/StoreSettingsPayload::COLUMNS)
        'storesettings' => ['store_id', 'website', 'defaultCurrency', 'invoiceExpiration', 'displayExpirationTimer', 'monitoringExpiration', 'speedPolicy',
            'lightningDescriptionTemplate', 'paymentTolerance', 'anyoneCanCreateInvoice', 'requiresRefundEmail', 'checkoutType', 'receipt',
            'lightningAmountInSatoshi', 'lightningPrivateRouteHints', 'onChainWithLnInvoiceFallback', 'redirectAutomatically', 'showRecommendedFee',
            'recommendedFeeBlockTarget', 'defaultLang', 'customLogo', 'customCSS', 'htmlTitle', 'networkFeeMode', 'payJoinEnabled', 'lazyPaymentMethods',
            'defaultPaymentMethod',
            // SIP-022 xpub: chiave pubblica del wallet BTC del merchant (mai chiavi private)
            'derivationScheme'],
        'pos_terminals' => ['id', 'store_id', 'merchant_id'],
        'migration' => ['version'],
        '_napay_bootstrap' => ['id', 'phase', 'code_sha256', 'schema_sha256'],
    ];

    /**
     * Grant statements auto-commit. Complete preflight before the first REVOKE; failure requires rerun,
     * never a broad fallback. Accounts are dedicated to this database, use host '%' and must already exist.
     */
    public static function apply(\PDO $admin, string $database, string $web, string $migrator, string $worker): void
    {
        self::validate($admin, $database, $web, $migrator, $worker);
        $lock = 'napay-db-roles-' . substr(hash('sha256', $database), 0, 40);
        $statement = $admin->prepare('SELECT GET_LOCK(?, 0)');
        $statement->execute([$lock]);
        $acquired = (int) $statement->fetchColumn(); $statement->closeCursor();
        if ($acquired !== 1) throw new \RuntimeException('database_roles_busy');
        try {
            $columns = [];
            $statement = $admin->prepare('SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?');
            $statement->execute([$database]);
            foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) $columns[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
            foreach (array_merge(self::APP_TABLES, self::B1_TABLES, self::WEB_HISTORY, self::WEB_DELETABLE, ['migration', '_napay_bootstrap']) as $table) {
                if (!isset($columns[$table])) throw new \RuntimeException('database_roles_preflight_failed');
            }
            foreach ([self::WEB_READ, self::WORKER_READ, ['btcpay_provisioning_jobs' => self::WEB_ENQUEUE]] as $matrix) {
                foreach ($matrix as $table => $required) {
                    if (array_diff($required, $columns[$table])) throw new \RuntimeException('database_roles_preflight_failed');
                }
            }
            self::accounts($admin, $database, [$web, $migrator, $worker]);
            foreach ([$web, $migrator, $worker] as $user) $admin->exec('REVOKE ALL PRIVILEGES, GRANT OPTION FROM ' . self::account($user));
            self::grantMigrator($admin, $database, $migrator);
            $grant = static function (string $privileges, string $table, string $user) use ($admin, $database): void {
                $admin->exec('GRANT ' . $privileges . ' ON ' . self::identifier($database) . '.' . self::identifier($table) . ' TO ' . self::account($user));
            };
            foreach (self::APP_TABLES as $table) $grant('SELECT, INSERT, UPDATE, DELETE', $table, $web);
            foreach (self::WEB_HISTORY as $table) $grant('SELECT, INSERT, UPDATE', $table, $web);
            foreach (self::WEB_DELETABLE as $table) $grant('SELECT, INSERT, UPDATE, DELETE', $table, $web);
            foreach (['migration', '_napay_bootstrap'] as $table) $grant('SELECT', $table, $web);
            foreach (self::WEB_READ as $table => $allowed) $grant(self::columnGrant('SELECT', $allowed), $table, $web);
            $grant(self::columnGrant('INSERT', self::WEB_ENQUEUE), 'btcpay_provisioning_jobs', $web);
            foreach (self::B1_TABLES as $table) $grant('SELECT, INSERT, UPDATE', $table, $worker);
            foreach (self::WORKER_READ as $table => $allowed) $grant(self::columnGrant('SELECT', $allowed), $table, $worker);
            $grant('INSERT', 'logs', $worker);
        } catch (\Throwable $error) {
            // Do not propagate SQL, account details or infrastructure exception chains.
            throw new \RuntimeException('database_roles_failed');
        } finally {
            try { $statement = $admin->prepare('SELECT RELEASE_LOCK(?)'); $statement->execute([$lock]); } catch (\Throwable $ignored) { }
        }
    }

    /** Before DDL, remove legacy schema-wide application access; only the migrator can see new tables. */
    public static function prepare(\PDO $admin, string $database, string $web, string $migrator, string $worker): void
    {
        self::validate($admin, $database, $web, $migrator, $worker);
        $lock = 'napay-db-roles-' . substr(hash('sha256', $database), 0, 40);
        $query = $admin->prepare('SELECT GET_LOCK(?,0)'); $query->execute([$lock]);
        $acquired = (int) $query->fetchColumn(); $query->closeCursor();
        if ($acquired !== 1) throw new \RuntimeException('database_roles_busy');
        try {
            self::accounts($admin, $database, [$web, $migrator, $worker]);
            foreach ([$web, $migrator, $worker] as $user) $admin->exec('REVOKE ALL PRIVILEGES, GRANT OPTION FROM ' . self::account($user));
            self::grantMigrator($admin, $database, $migrator);
        } finally {
            $query = $admin->prepare('SELECT RELEASE_LOCK(?)'); $query->execute([$lock]);
        }
    }

    private static function validate(\PDO $admin, string $database, string $web, string $migrator, string $worker): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $database) || count(array_unique([$web, $migrator, $worker])) !== 3) throw new \InvalidArgumentException('invalid_database_roles');
        foreach ([$web, $migrator, $worker] as $user) {
            if (!preg_match('/^napay_[a-z0-9_]{1,26}$/D', $user)) throw new \InvalidArgumentException('invalid_database_roles');
        }
        if ($admin->inTransaction()) throw new \LogicException('caller_transaction_open');
        if ($admin->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql' || !preg_match('/^8\.4\./', (string) $admin->getAttribute(\PDO::ATTR_SERVER_VERSION))
            || $admin->query('SELECT DATABASE()')->fetchColumn() !== $database) throw new \RuntimeException('database_roles_preflight_failed');
    }

    private static function accounts(\PDO $admin, string $database, array $users): void
    {
        foreach ($users as $user) {
            $statement = $admin->prepare("SELECT COUNT(*) FROM mysql.user WHERE User = ? AND Host = '%' AND account_locked = 'N'");
            $statement->execute([$user]);
            $exists = (int) $statement->fetchColumn(); $statement->closeCursor();
            if ($exists !== 1) throw new \RuntimeException('database_roles_preflight_failed');
            foreach ($admin->query('SHOW GRANTS FOR ' . self::account($user))->fetchAll(\PDO::FETCH_COLUMN) as $grant) {
                if (preg_match('/^GRANT USAGE ON \*\.\* TO /', $grant)) continue;
                if (!preg_match('/^GRANT .+ ON `([^`]+)`\.(?:\*|`[^`]+`) TO /', $grant, $match)
                    || str_replace(['\\_', '\\%'], ['_', '%'], $match[1]) !== $database) throw new \RuntimeException('database_roles_foreign_privileges');
            }
        }
    }

    private static function grantMigrator(\PDO $admin, string $database, string $migrator): void
    {
        $schema = (int) $admin->query('SELECT @@partial_revokes')->fetchColumn() === 1 ? $database : str_replace('_', '\\_', $database);
        $admin->exec('GRANT ALL PRIVILEGES ON ' . self::identifier($schema) . '.* TO ' . self::account($migrator));
    }

    private static function identifier(string $value): string { return '`' . str_replace('`', '``', $value) . '`'; }
    private static function account(string $user): string { return "'" . $user . "'@'%'"; }
    private static function columnGrant(string $privilege, array $columns): string
    {
        return $privilege . ' (' . implode(', ', array_map([self::class, 'identifier'], $columns)) . ')';
    }
}
