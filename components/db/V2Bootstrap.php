<?php

namespace app\components\db;

use PDO;
use RuntimeException;

/** One-shot v2 restore. No Yii/AR callbacks, external API calls or secret output. */
final class V2Bootstrap
{
    public const STATE_TABLE = '_napay_bootstrap';
    public const TABLES = ['logs', 'merchants', 'migration', 'np_comuni_italiani', 'privileges', 'settings', 'stores', 'storesettings', 'user_consensus', 'users', 'webhooks'];

    public static function connect(string $dsn, string $user, string $password, int $seconds = 60): PDO
    {
        $deadline = microtime(true) + $seconds;
        $delay = 100000;
        do {
            try {
                return new PDO($dsn, $user, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2,
                    PDO::ATTR_STRINGIFY_FETCHES => true, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
                ]);
            } catch (\PDOException $e) {
                if (!in_array((int) ($e->errorInfo[1] ?? 0), [2002, 2003, 2006, 2013], true)) {
                    throw new RuntimeException('Database authentication/configuration rejected.');
                }
                usleep($delay);
                $delay = min(2000000, $delay * 2);
            }
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Database readiness timeout.');
    }

    public static function rows(PDO $db, string $sql, array $parameters = []): array
    {
        $query = $db->prepare($sql);
        $query->execute($parameters);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $query->closeCursor();
        return $rows;
    }

    public static function tables(PDO $db): array
    {
        return array_column(self::rows($db, 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME<>? ORDER BY TABLE_NAME', [self::STATE_TABLE]), 'TABLE_NAME');
    }

    public static function schemaHash(PDO $db): string
    {
        $columns = self::rows($db, 'SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME<>? ORDER BY TABLE_NAME,ORDINAL_POSITION', [self::STATE_TABLE]);
        $indexes = self::rows($db, 'SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME<>? ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX', [self::STATE_TABLE]);
        $keys = self::rows($db, 'SELECT TABLE_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_NAME,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME<>? ORDER BY TABLE_NAME,CONSTRAINT_NAME', [self::STATE_TABLE]);
        return hash('sha256', json_encode([$columns, $indexes, $keys], JSON_THROW_ON_ERROR));
    }

    private static function identifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) throw new RuntimeException('Invalid dataset identifier.');
        return '`' . $name . '`';
    }

    public static function digest(PDO $db, string $table, array $columns): array
    {
        $quoted = array_map([self::class, 'identifier'], $columns);
        $key = $table === 'migration' ? 'version' : 'id';
        $query = $db->query('SELECT ' . implode(',', $quoted) . ' FROM ' . self::identifier($table) . ' ORDER BY ' . self::identifier($key));
        $hash = hash_init('sha256');
        $count = 0;
        while (($row = $query->fetch(PDO::FETCH_NUM)) !== false) {
            hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR) . "\n");
            ++$count;
        }
        $query->closeCursor();
        return ['rows' => $count, 'sha256' => hash_final($hash), 'columns' => $columns];
    }

    public static function manifest(PDO $source, string $sqlFile): array
    {
        if (self::rows($source, 'SELECT DATABASE() AS name')[0]['name'] !== 'napay_v2'
            || self::tables($source) !== self::TABLES) throw new RuntimeException('Only the known napay_v2 dataset is supported.');
        $objects = self::rows($source, 'SELECT (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE())+(SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE())+(SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()) AS total')[0]['total'];
        if ((int) $objects !== 0) throw new RuntimeException('Source programmable objects require separate review.');
        $manifest = ['format' => 1, 'source_schema' => 'napay_v2', 'sql_sha256' => hash_file('sha256', $sqlFile), 'schema_sha256' => self::schemaHash($source), 'tables' => []];
        foreach (self::TABLES as $table) {
            $columns = array_column(self::rows($source, 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table]), 'COLUMN_NAME');
            $manifest['tables'][$table] = self::digest($source, $table, $columns);
        }
        return $manifest;
    }

    public static function version(string $root): string
    {
        $files = glob($root . '/migrations/m*.php');
        sort($files);
        $files[] = $root . '/components/db/V2UsersSchema.php';
        return hash('sha256', implode('', array_map(fn($file) => hash_file('sha256', $file), $files)));
    }

    public static function references(PDO $db): void
    {
        $relations = [
            ['users', 'privilege_id', 'privileges'], ['merchants', 'user_id', 'users'],
            ['stores', 'merchant_id', 'merchants'], ['storesettings', 'store_id', 'stores'],
            ['user_consensus', 'user_id', 'users'], ['webhooks', 'store_id', 'stores'],
        ];
        if (in_array('auth', self::tables($db), true)) $relations[] = ['auth', 'user_id', 'users'];
        foreach ($relations as [$table, $column, $target]) {
            $sql = 'SELECT COUNT(*) AS total FROM ' . self::identifier($table) . ' s LEFT JOIN '
                . self::identifier($target) . ' t ON s.' . self::identifier($column)
                . '=t.id WHERE t.id IS NULL AND s.' . self::identifier($column) . ' IS NOT NULL';
            if (self::rows($db, $sql)[0]['total'] !== '0') throw new RuntimeException('Restored v2 data has orphan references.');
        }
    }

    public static function state(PDO $db): ?array
    {
        $exists = self::rows($db, 'SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [self::STATE_TABLE]);
        if ((int) $exists[0]['total'] === 0) return null;
        return self::rows($db, 'SELECT * FROM _napay_bootstrap WHERE id=1')[0] ?? null;
    }

    public static function ready(PDO $db, string $root): void
    {
        $state = self::state($db);
        if (!$state || $state['phase'] !== 'complete' || !hash_equals(self::version($root), $state['code_sha256'])
            || !hash_equals(self::schemaHash($db), $state['schema_sha256'])) {
            throw new RuntimeException('Bootstrap incomplete or schema/version mismatch.');
        }
        $required = ['m231113_093348_create_users_table', 'm261023_120000_add_activation_code_to_users', 'm261023_120100_create_auth_table'];
        $found = self::rows($db, 'SELECT version FROM migration WHERE version IN (?,?,?)', $required);
        if (count($found) !== 3) throw new RuntimeException('Bootstrap migration history mismatch.');
    }

    private static function process(array $command, ?string $input, int $seconds): void
    {
        $process = proc_open($command, [0 => ['file', $input ?? '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start bootstrap subprocess.');
        $deadline = microtime(true) + $seconds;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                proc_close($process);
                if ($status['exitcode'] !== 0) throw new RuntimeException('Bootstrap subprocess failed; writers remain blocked.');
                return;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        proc_terminate($process, 9);
        proc_close($process);
        throw new RuntimeException('Bootstrap subprocess timeout; partial target requires recovery.');
    }

    public static function run(PDO $db, string $root, string $directory, array $client, int $seconds = 1800): string
    {
        $deadline = microtime(true) + $seconds;
        $remaining = static function () use ($deadline): int {
            $seconds = (int) ceil($deadline - microtime(true));
            if ($seconds < 1) throw new RuntimeException('Bootstrap total budget exhausted.');
            return $seconds;
        };
        $manifestFile = $directory . '/manifest.json';
        $sqlFile = $directory . '/napay-v2.sql';
        if (!is_file($manifestFile) || is_link($manifestFile) || filesize($manifestFile) > 65536
            || !is_file($sqlFile) || is_link($sqlFile) || filesize($sqlFile) === 0) throw new RuntimeException('A reviewed v2 dataset is required.');
        $raw = file_get_contents($manifestFile);
        $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (($manifest['format'] ?? null) !== 1 || ($manifest['source_schema'] ?? '') !== 'napay_v2'
            || array_keys($manifest['tables'] ?? []) !== self::TABLES
            || !hash_equals($manifest['sql_sha256'] ?? '', hash_file('sha256', $sqlFile))) throw new RuntimeException('V2 dataset identity/checksum mismatch.');
        foreach ($manifest['tables'] as $spec) {
            if (!is_int($spec['rows'] ?? null) || $spec['rows'] < 0 || !preg_match('/^[a-f0-9]{64}$/D', $spec['sha256'] ?? '')
                || !is_array($spec['columns'] ?? null) || !$spec['columns']) throw new RuntimeException('Invalid dataset table specification.');
            foreach ($spec['columns'] as $column) self::identifier($column);
        }
        if (!is_file($root . '/vendor/yiisoft/yii2/Yii.php')) throw new RuntimeException('Install Napay runtime dependencies before bootstrap.');
        $name = self::rows($db, 'SELECT DATABASE() AS name')[0]['name'];
        $lock = 'napay-bootstrap-' . substr(hash('sha256', $name), 0, 40);
        if (self::rows($db, 'SELECT GET_LOCK(?,0) AS acquired', [$lock])[0]['acquired'] !== '1') throw new RuntimeException('Another bootstrap owns the database lock.');
        $started = false;
        $options = null;
        try {
            $state = self::state($db);
            if ($state) {
                if (!hash_equals($state['dataset_sha256'], $manifest['sql_sha256']) || !hash_equals($state['manifest_sha256'], hash('sha256', $raw))) throw new RuntimeException('Existing database belongs to another dataset.');
                $upgrade = ($state['phase'] === 'complete' && !hash_equals(self::version($root), $state['code_sha256'])) || in_array($state['phase'], ['migrating', 'upgrade-failed'], true);
                if ($upgrade) {
                    // New migrations on an already restored database: apply them, never reimport.
                    // A schema changed by hand is refused; a failed or interrupted upgrade is retried (migrate skips applied steps).
                    if ($state['phase'] === 'complete' && !hash_equals(self::schemaHash($db), (string) $state['schema_sha256'])) throw new RuntimeException('Schema changed outside migrations.');
                    $db->exec("UPDATE _napay_bootstrap SET phase='migrating' WHERE id=1");
                    try {
                        self::process([PHP_BINARY, $root . '/yii', 'migrate', '--interactive=0'], null, $remaining());
                        self::references($db);
                    } catch (\Throwable $error) {
                        try { $db->exec("UPDATE _napay_bootstrap SET phase='upgrade-failed' WHERE id=1"); } catch (\Throwable $ignored) { }
                        throw $error;
                    }
                    $db->prepare("UPDATE _napay_bootstrap SET phase='complete',code_sha256=?,schema_sha256=? WHERE id=1")->execute([self::version($root), self::schemaHash($db)]);
                }
                self::ready($db, $root);
                return $upgrade ? 'upgraded' : 'already-complete';
            }
            if (self::tables($db) !== [] || self::rows($db, 'SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')[0]['total'] !== '0') throw new RuntimeException('Initial import requires an empty target database.');
            $db->exec('CREATE TABLE _napay_bootstrap (id TINYINT PRIMARY KEY, phase VARCHAR(16) NOT NULL, dataset_sha256 CHAR(64) NOT NULL, manifest_sha256 CHAR(64) NOT NULL, code_sha256 CHAR(64) NOT NULL, schema_sha256 CHAR(64) NULL) ENGINE=InnoDB');
            $db->prepare('INSERT INTO _napay_bootstrap VALUES (1,?,?,?,?,NULL)')->execute(['importing', $manifest['sql_sha256'], hash('sha256', $raw), self::version($root)]);
            $started = true;
            $options = tempnam(sys_get_temp_dir(), 'napay-client-');
            chmod($options, 0600);
            if (($client['socket'] ?? '') !== '/run/napay-mysql/mysqld.sock') throw new RuntimeException('The private MySQL socket is required for restore.');
            $contents = "[client]\nprotocol=socket\ndefault-character-set=utf8mb4\n";
            foreach ($client as $key => $value) {
                if (!in_array($key, ['socket', 'user', 'password'], true) || strpbrk((string) $value, "\r\n\0") !== false) throw new RuntimeException('Invalid MySQL client configuration.');
                $contents .= $key . '="' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value) . "\"\n";
            }
            file_put_contents($options, $contents);
            self::process(['/usr/bin/mysql', '--defaults-extra-file=' . $options, '--binary-mode=1', '--local-infile=0', '--database=' . $name], $sqlFile, $remaining());
            if (self::tables($db) !== self::TABLES || !hash_equals($manifest['schema_sha256'], self::schemaHash($db))) throw new RuntimeException('Imported v2 schema differs from source.');
            foreach ($manifest['tables'] as $table => $spec) {
                if (self::digest($db, $table, $spec['columns']) !== $spec) throw new RuntimeException('Imported v2 content differs from source.');
            }
            self::references($db);
            $db->exec("UPDATE _napay_bootstrap SET phase='upgrading' WHERE id=1");
            self::process([PHP_BINARY, $root . '/yii', 'migrate', '--interactive=0'], null, $remaining());
            foreach ($manifest['tables'] as $table => $spec) {
                if ($table !== 'migration' && self::digest($db, $table, $spec['columns']) !== $spec) throw new RuntimeException('Upgrade changed original application data.');
            }
            self::references($db);
            $db->prepare("UPDATE _napay_bootstrap SET phase='complete',schema_sha256=? WHERE id=1")->execute([self::schemaHash($db)]);
            self::ready($db, $root);
            return 'imported-and-upgraded';
        } catch (\Throwable $error) {
            if ($started) {
                try { $db->exec("UPDATE _napay_bootstrap SET phase='failed' WHERE id=1"); } catch (\Throwable $ignored) { }
            }
            throw $error;
        } finally {
            if ($options !== null) @unlink($options);
            self::rows($db, 'SELECT RELEASE_LOCK(?) AS released', [$lock]);
        }
    }
}
