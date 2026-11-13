<?php
namespace app\components\db;

/** Privileged setup runs only in bootstrap; no passwords are changed on existing accounts. */
final class DatabaseRoleBootstrap
{
    private bool $released = false;
    private function __construct(private readonly \PDO $admin, private readonly string $database,
        private readonly string $web, private readonly string $migrator, private readonly string $worker,
        private readonly string $lock) {}

    public static function prepare(#[\SensitiveParameter] array $config, string $root): self
    {
        if (is_file($root . '/.env')) throw new \RuntimeException('database_roles_require_external_environment');
        $database = env_required('DB_DATABASE');
        $web = env_required('NAPAY_WEB_DB_USERNAME');
        $migrator = $config['user'];
        $worker = env_required('NAPAY_DB_WORKER_USERNAME');
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $database) || count(array_unique([$web, $migrator, $worker])) !== 3) throw new \RuntimeException('invalid_database_roles');
        foreach ([$web, $migrator, $worker] as $user) if (!preg_match('/^napay_[a-z0-9_]{1,26}$/D', $user)) throw new \RuntimeException('invalid_database_roles');
        $passwords = [$web => env_required('NAPAY_WEB_DB_PASSWORD'), $migrator => $config['password'],
            $worker => DatabaseSecret::read('/run/secrets/napay_db_worker_password')];
        foreach ($passwords as $password) if (strlen($password) < 32 || strlen($password) > 256 || strpbrk($password, "\r\n\0") !== false) throw new \RuntimeException('invalid_database_secret');
        $rootPassword = DatabaseSecret::read('/run/secrets/mysql_root_password');
        if (count(array_unique(array_merge(array_values($passwords), [$rootPassword]))) !== 4) throw new \RuntimeException('database_roles_require_distinct_secrets');
        $admin = V2Bootstrap::connect('mysql:unix_socket=/run/napay-mysql/mysqld.sock;dbname=' . $database . ';charset=utf8mb4',
            'root', $rootPassword, $config['timeout']);
        unset($rootPassword);
        $lock = 'napay-role-setup-' . substr(hash('sha256', $database), 0, 40);
        $query = $admin->prepare('SELECT GET_LOCK(?,0)'); $query->execute([$lock]);
        $acquired = (int) $query->fetchColumn(); $query->closeCursor();
        if ($acquired !== 1) throw new \RuntimeException('database_roles_busy');
        $setup = new self($admin, $database, $web, $migrator, $worker, $lock);
        try {
            self::ensureAccounts($admin, $passwords, $config['timeout']);
            DatabaseRoles::prepare($admin, $database, $web, $migrator, $worker);
            return $setup;
        } catch (\Throwable $error) {
            $setup->release();
            throw new \RuntimeException('database_roles_bootstrap_failed');
        }
    }

    private static function ensureAccounts(\PDO $admin, #[\SensitiveParameter] array $passwords, int $timeout): void
    {
        // Verify all account identities before creating any of them.
        foreach ($passwords as $user => $password) {
            $query = $admin->prepare('SELECT Host, account_locked FROM mysql.user WHERE User=?'); $query->execute([$user]);
            $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
            if ($rows !== [] && (count($rows) !== 1 || $rows[0]['Host'] !== '%' || $rows[0]['account_locked'] !== 'N')) throw new \RuntimeException('invalid_database_account');
        }
        foreach ($passwords as $user => $password) {
            $query = $admin->prepare('SELECT COUNT(*) FROM mysql.user WHERE User=?'); $query->execute([$user]);
            $exists = (int) $query->fetchColumn(); $query->closeCursor();
            if ($exists === 0) $admin->exec("CREATE USER '$user'@'%' IDENTIFIED BY " . $admin->quote($password));
            // No selected schema: newly created accounts deliberately have no privileges yet.
            $probe = V2Bootstrap::connect('mysql:host=mysql;port=3306;charset=utf8mb4', $user, $password, $timeout);
            unset($probe);
        }
    }

    public function finish(): void
    {
        DatabaseRoles::apply($this->admin, $this->database, $this->web, $this->migrator, $this->worker);
        $this->release();
    }

    private function release(): void
    {
        if ($this->released) return;
        try { $query = $this->admin->prepare('SELECT RELEASE_LOCK(?)'); $query->execute([$this->lock]); } catch (\Throwable $ignored) { }
        $this->released = true;
    }
    public function __destruct() { $this->release(); }
}
