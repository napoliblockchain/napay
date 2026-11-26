<?php
// SIP-023 R2: destructive probes are restricted to this disposable runtime database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php';
require $root . '/vendor/yiisoft/yii2/Yii.php';

if (($argv[1] ?? '') === '--r2-email-race') {
    try {
        $raceDb = Yii::createObject(require $root . '/config/db.php');
        $raceDb->enableLogging = false; $raceDb->enableProfiling = false;
        $raceDb->createCommand("SET SESSION innodb_lock_wait_timeout=10")->execute();
        if ($raceDb->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(2);
        $slot = $argv[2] ?? '';
        if (!in_array($slot, ['a','b'], true)) exit(2);
        $row = $raceDb->createCommand("SELECT * FROM users WHERE username='runtime-user'")->queryOne();
        if (!$row) exit(2);
        unset($row['id'], $row['email_normalized']);
        $row['username'] = 'r2-email-race-' . $slot;
        $row['email'] = $slot === 'a' ? ' R2-Race@Example.Invalid ' : 'r2-race@example.invalid';
        fwrite(STDOUT, "ready\n");
        stream_set_timeout(STDIN, 15);
        if (fread(STDIN, 1) !== 'x') exit(2);
        $raceDb->createCommand()->insert('users', $row)->execute();
        exit(0);
    } catch (yii\db\IntegrityException $error) {
        exit((int)($error->errorInfo[1] ?? 0) === 1062 ? 10 : 2);
    } catch (Throwable $error) { exit(2); }
}

$app = new yii\console\Application(require $root . '/config/console.php');
$db = $app->db;
$db->enableLogging = false; $db->enableProfiling = false;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);
require $root . '/migrations/m261112_135000_unique_normalized_user_email.php';
$r2EmailChecks = 0;
function r2EmailCheck($condition, string $label): void {
    global $r2EmailChecks;
    if (!$condition) throw new RuntimeException($label);
    ++$r2EmailChecks; echo 'PASS: ' . $label . "\n";
}
// Instrument real DDL only: execution and all metadata/data still use real MySQL.
class R2EmailSchemaCommand extends yii\db\Command {
    public static $alters = 0;
    public static $beforeAlter;
    public function execute() {
        if (preg_match('/^ALTER TABLE /i', $this->getSql())) {
            ++self::$alters;
            if (self::$beforeAlter !== null) {
                $callback = self::$beforeAlter; self::$beforeAlter = null; $callback();
            }
        }
        return parent::execute();
    }
}
function r2EmailRun(yii\db\Connection $connection): void {
    (new m261112_135000_unique_normalized_user_email(['db'=>$connection,'compact'=>true]))->up();
}
function r2EmailRefused(yii\db\Connection $connection, string $label): void {
    $table = $connection->quoteTableName($connection->tablePrefix . 'users');
    $before = $connection->createCommand('SHOW CREATE TABLE ' . $table)->queryOne();
    $alters = R2EmailSchemaCommand::$alters; $refused = false;
    ob_start();
    try { r2EmailRun($connection); }
    catch (RuntimeException $error) {
        $refused = $error->getMessage() === 'Normalized email migration refused; verify schema and legacy counts before retry.' && $error->getPrevious() === null;
    } finally { $output = ob_get_clean(); }
    r2EmailCheck($refused && $output === '', $label . ': fixed error, no SQL/PII or chained exception');
    r2EmailCheck($alters === R2EmailSchemaCommand::$alters && $before === $connection->createCommand('SHOW CREATE TABLE ' . $table)->queryOne(), $label . ': refused before any DDL, schema unchanged');
}
function r2EmailRejects(callable $operation, int $mysqlCode, string $label): void {
    $rejected = false;
    try { $operation(); }
    catch (yii\db\Exception $error) { $rejected = (int)($error->errorInfo[1] ?? 0) === $mysqlCode; }
    r2EmailCheck($rejected, $label);
}

r2EmailCheck($db->driverName === 'mysql' && preg_match('/^8\.4\./', $db->getServerVersion()), 'Real MySQL 8.4 runtime database');
$realSchema = $db->createCommand('SHOW CREATE TABLE users')->queryOne();
$realCount = (int)$db->createCommand('SELECT COUNT(*) FROM users')->queryScalar();
r2EmailRun($db);
r2EmailCheck($realSchema === $db->createCommand('SHOW CREATE TABLE users')->queryOne(), 'Installed R2 migration replays without changing users schema');
$column = $db->createCommand("SELECT COLUMN_TYPE,COLLATION_NAME,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='email_normalized'")->queryOne();
r2EmailCheck($column && $column['COLUMN_TYPE'] === 'varchar(255)' && $column['COLLATION_NAME'] === 'utf8mb4_0900_bin' && $column['EXTRA'] === 'STORED GENERATED', 'Real users generated column has required storage, type and binary collation');

$probe = Yii::createObject(require $root . '/config/db.php');
$probe->enableLogging = false; $probe->enableProfiling = false;
$probe->tablePrefix = 'r2_email_probe_'; $probe->commandClass = R2EmailSchemaCommand::class;
$probeTable = $probe->tablePrefix . 'users';
r2EmailCheck($probe->createCommand('SELECT DATABASE()')->queryScalar() === 'napay_test_runtime'
    && $probe->schema->getTableSchema($probeTable, true) === null, 'Probe connection isolated; reserved probe table absent');
$quotedProbe = $probe->quoteTableName($probeTable);
$reset = static function (string $source = 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL') use ($probe,$quotedProbe): void {
    $probe->createCommand('DROP TABLE IF EXISTS ' . $quotedProbe)->execute();
    $probe->createCommand('CREATE TABLE ' . $quotedProbe . ' (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,email ' . $source . ',payload VARCHAR(32) NOT NULL DEFAULT \'unchanged\') ENGINE=InnoDB')->execute();
    $probe->schema->refresh();
};
$addColumn = 'ADD COLUMN email_normalized VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin GENERATED ALWAYS AS (LOWER(TRIM(email) COLLATE utf8mb4_0900_bin)) STORED';
$addIndex = 'ADD UNIQUE INDEX `uq-users-email-normalized` (email_normalized)';
$addCheck = 'ADD CONSTRAINT `ck-users-email-normalized-present` CHECK (CHAR_LENGTH(email_normalized)>0) ENFORCED';
$insertProbe = static fn(string $email)=>$probe->createCommand()->insert($probeTable, ['email'=>$email])->execute();
// MySQL CHECK names are schema-wide. Release only this CHECK while probing a private
// table, leaving the real users UNIQUE active, and always restore the original CHECK.
$db->createCommand('ALTER TABLE users DROP CHECK `ck-users-email-normalized-present`')->execute();
try {
    foreach ([[],[$addColumn],[$addColumn,$addIndex],[$addColumn,$addCheck],[$addColumn,$addIndex,$addCheck]] as $i=>$partial) {
        $reset(); $insertProbe(' Mixed.Case@Example.Invalid ');
        $before = $probe->createCommand('SELECT id,email,payload FROM ' . $quotedProbe)->queryAll();
        if ($partial !== []) $probe->createCommand('ALTER TABLE ' . $quotedProbe . ' ' . implode(', ', $partial))->execute();
        $alters = R2EmailSchemaCommand::$alters;
        r2EmailRun($probe);
        r2EmailCheck(R2EmailSchemaCommand::$alters - $alters === ($i === 4 ? 0 : 1), 'Missing R2 objects installed in exactly one atomic ALTER, resume case ' . $i);
        r2EmailCheck($before === $probe->createCommand('SELECT id,email,payload FROM ' . $quotedProbe)->queryAll(), 'Resume preserves source bytes and unrelated fields, case ' . $i);
        r2EmailCheck($probe->createCommand('SELECT email_normalized FROM ' . $quotedProbe)->queryScalar() === 'mixed.case@example.invalid', 'Generated email lowercases and trims without rewriting source, case ' . $i);
        $schema = $probe->createCommand('SHOW CREATE TABLE ' . $quotedProbe)->queryOne();
        $alters = R2EmailSchemaCommand::$alters; r2EmailRun($probe);
        r2EmailCheck($alters === R2EmailSchemaCommand::$alters && $schema === $probe->createCommand('SHOW CREATE TABLE ' . $quotedProbe)->queryOne(), 'Exact second replay emits no DDL, case ' . $i);
    }
    foreach ([[''],['   '],['R2-Duplicate@Example.Invalid',' r2-duplicate@example.invalid ']] as $i=>$emails) {
        $reset(); foreach ($emails as $email) $insertProbe($email);
        $before = $probe->createCommand('SELECT * FROM ' . $quotedProbe . ' ORDER BY id')->queryAll();
        r2EmailRefused($probe, 'Legacy empty or normalized collision case ' . $i);
        r2EmailCheck($before === $probe->createCommand('SELECT * FROM ' . $quotedProbe . ' ORDER BY id')->queryAll(), 'Legacy anomalies never auto-repaired, case ' . $i);
    }
    foreach (['VARCHAR(255) CHARACTER SET utf8mb4 NULL','VARCHAR(254) CHARACTER SET utf8mb4 NOT NULL','VARCHAR(255) CHARACTER SET latin1 NOT NULL'] as $i=>$source) {
        $reset($source); r2EmailRefused($probe, 'Incompatible source column case ' . $i);
    }
    $columnCases = [
        'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin',
        'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin AS (LOWER(TRIM(email) COLLATE utf8mb4_0900_bin)) VIRTUAL',
        'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin AS (LOWER(email)) STORED',
        'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci AS (LOWER(TRIM(email) COLLATE utf8mb4_0900_bin)) STORED',
        'VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin AS (LOWER(TRIM(email) COLLATE utf8mb4_0900_bin)) STORED',
        'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin AS (LOWER(TRIM(`e mail`) COLLATE utf8mb4_0900_bin)) STORED',
        'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin AS (LOWER(TRIM(email))) STORED',
        'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin AS (LOWER(TRIM(email)) COLLATE utf8mb4_0900_bin) STORED',
    ];
    foreach ($columnCases as $i=>$definition) {
        $reset();
        if ($i === 5) $probe->createCommand('ALTER TABLE ' . $quotedProbe . ' ADD COLUMN `e mail` VARCHAR(255)')->execute();
        $probe->createCommand('ALTER TABLE ' . $quotedProbe . ' ADD COLUMN email_normalized ' . $definition)->execute();
        r2EmailRefused($probe, 'Incompatible normalized column case ' . $i);
    }
    foreach (['INDEX `uq-users-email-normalized` (email_normalized)',
        'UNIQUE INDEX `uq-users-email-normalized` (email_normalized(10))',
        'UNIQUE INDEX `uq-users-email-normalized` (email_normalized) INVISIBLE',
        'UNIQUE INDEX `uq-users-email-normalized` (email)',
        'UNIQUE INDEX `uq-users-email-normalized` (email_normalized,id)',
        'UNIQUE INDEX `uq-users-email-normalized` (email_normalized DESC)'] as $i=>$definition) {
        $reset(); $probe->createCommand('ALTER TABLE ' . $quotedProbe . ' ' . $addColumn . ', ADD ' . $definition)->execute();
        r2EmailRefused($probe, 'Incompatible reserved index case ' . $i);
    }
    foreach (['CHECK (CHAR_LENGTH(email_normalized)>0) NOT ENFORCED',
        'CHECK (CHAR_LENGTH(email_normalized)>1)',
        'CHECK (CHAR_LENGTH(email_normalized>0)>0)',
        'UNIQUE (email)'] as $i=>$definition) {
        $reset(); $probe->createCommand('ALTER TABLE ' . $quotedProbe . ' ' . $addColumn . ', ADD CONSTRAINT `ck-users-email-normalized-present` ' . $definition)->execute();
        r2EmailRefused($probe, 'Incompatible reserved CHECK case ' . $i);
    }
    // Legacy and canonical case mappings can differ even with binary output comparison.
    $mapping = $probe->createCommand('SELECT HEX(LOWER(CONVERT(0xE1BA9E USING utf8mb4) COLLATE utf8mb4_unicode_ci)) AS legacy,HEX(LOWER(CONVERT(0xE1BA9E USING utf8mb4) COLLATE utf8mb4_0900_bin)) AS canonical')->queryOne();
    r2EmailCheck($mapping === ['legacy'=>'E1BA9E','canonical'=>'C39F'], 'Constant U+1E9E probe demonstrates collation-before-LOWER requirement');
    $unicodeSource = 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL';
    $unicodeUpper = " \u{1e9e}@EXAMPLE.INVALID "; $unicodeLower = "\u{00df}@example.invalid";
    $reset($unicodeSource); $insertProbe($unicodeUpper); $insertProbe($unicodeLower);
    $unicodeRows = $probe->createCommand('SELECT * FROM ' . $quotedProbe . ' ORDER BY id')->queryAll();
    r2EmailRefused($probe, 'Legacy Unicode U+1E9E/U+00DF collision under canonical case mapping');
    r2EmailCheck($unicodeRows === $probe->createCommand('SELECT * FROM ' . $quotedProbe . ' ORDER BY id')->queryAll(), 'Unicode collision preflight preserves source bytes and both legacy rows');
    $reset($unicodeSource); $insertProbe($unicodeUpper); r2EmailRun($probe);
    r2EmailCheck($probe->createCommand('SELECT email_normalized FROM ' . $quotedProbe)->queryScalar() === $unicodeLower, 'Generated column lowercases U+1E9E using canonical bin mapping, not source unicode_ci');
    $sourceCollation = $probe->createCommand('SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=\'email\'', [':t'=>$probeTable])->queryScalar();
    r2EmailCheck($sourceCollation === 'utf8mb4_unicode_ci' && $probe->createCommand('SELECT email FROM ' . $quotedProbe)->queryScalar() === $unicodeUpper, 'Unicode fix preserves legacy source collation and email bytes');
    r2EmailRejects(fn()=>$insertProbe($unicodeLower), 1062, 'Canonical Unicode equivalent rejected by UNIQUE on SQL INSERT');
    $insertProbe('r2-unicode-other@example.invalid'); $unicodeOtherId = (int)$probe->lastInsertID;
    r2EmailRejects(fn()=>$probe->createCommand()->update($probeTable, ['email'=>$unicodeLower], ['id'=>$unicodeOtherId])->execute(), 1062, 'Canonical Unicode equivalent rejected by UNIQUE on SQL UPDATE');
    $schema = $probe->createCommand('SHOW CREATE TABLE ' . $quotedProbe)->queryOne(); $alters = R2EmailSchemaCommand::$alters;
    r2EmailRun($probe);
    r2EmailCheck($alters === R2EmailSchemaCommand::$alters && $schema === $probe->createCommand('SHOW CREATE TABLE ' . $quotedProbe)->queryOne(), 'Canonical Unicode generated expression accepted on exact replay without DDL');
    $reset($unicodeSource);
    $probe->createCommand('ALTER TABLE ' . $quotedProbe . ' ' . str_replace('LOWER(', 'LCASE(', $addColumn))->execute();
    $insertProbe($unicodeUpper); $alters = R2EmailSchemaCommand::$alters; r2EmailRun($probe);
    r2EmailCheck(R2EmailSchemaCommand::$alters === $alters+1 && $probe->createCommand('SELECT email_normalized FROM ' . $quotedProbe)->queryScalar() === $unicodeLower, 'Equivalent LCASE with canonical input collation resumes safely');
    // Source ai_ci would conflate these; both preflight and UNIQUE must use binary normalization.
    $reset(); $insertProbe('cafe@example.invalid'); $insertProbe("caf\u{00e9}@example.invalid");
    r2EmailRun($probe);
    r2EmailCheck((int)$probe->createCommand('SELECT COUNT(*) FROM ' . $quotedProbe)->queryScalar() === 2, 'Accent-distinct legacy identities are not false collisions under binary collation');
    r2EmailRejects(fn()=>$insertProbe(' CAFE@EXAMPLE.INVALID '), 1062, 'SQL INSERT case/space collision rejected by UNIQUE');
    r2EmailRejects(fn()=>$probe->createCommand()->update($probeTable, ['email'=>' CAFE@EXAMPLE.INVALID '], ['id'=>2])->execute(), 1062, 'SQL UPDATE case/space collision rejected by UNIQUE');
    r2EmailRejects(fn()=>$insertProbe(''), 3819, 'Enforced CHECK rejects empty normalized email');
    r2EmailRejects(fn()=>$insertProbe('   '), 3819, 'Enforced CHECK rejects ASCII-space-only normalized email');
    r2EmailRejects(fn()=>$probe->createCommand()->insert($probeTable, ['email'=>null])->execute(), 1048, 'Nonnullable source rejects NULL email');
    r2EmailRejects(fn()=>$probe->createCommand()->insert($probeTable, ['email'=>'r2-generated@example.invalid','email_normalized'=>'forged@example.invalid'])->execute(), 3105, 'Generated identity cannot be supplied as independent input');
    $insertProbe(' NEW@Example.Invalid ');
    $probe->createCommand()->update($probeTable, ['email'=>' Changed@Example.Invalid '], ['id'=>(int)$probe->lastInsertID])->execute();
    r2EmailCheck($probe->createCommand("SELECT email_normalized FROM $quotedProbe WHERE email=:e", [':e'=>' Changed@Example.Invalid '])->queryScalar() === 'changed@example.invalid', 'Generated identity recomputed on SQL UPDATE');
    // Inject a genuine second-connection write after count preflight and before the atomic ALTER.
    $reset(); $insertProbe('r2-ddl-race@example.invalid');
    $writer = Yii::createObject(require $root . '/config/db.php');
    $writer->enableLogging = false; $writer->enableProfiling = false;
    r2EmailCheck($writer->createCommand('SELECT DATABASE()')->queryScalar() === 'napay_test_runtime', 'DDL interleaving writer restricted to disposable DB');
    R2EmailSchemaCommand::$beforeAlter = static function () use ($writer,$probeTable): void {
        $writer->createCommand()->insert($probeTable, ['email'=>' R2-DDL-RACE@Example.Invalid '])->execute();
    };
    $before = $probe->createCommand('SHOW CREATE TABLE ' . $quotedProbe)->queryOne();
    $refused = false; ob_start();
    try { r2EmailRun($probe); }
    catch (RuntimeException $error) { $refused = $error->getMessage() === 'Normalized email migration refused; verify schema and legacy counts before retry.' && $error->getPrevious() === null; }
    finally { $output = ob_get_clean(); R2EmailSchemaCommand::$beforeAlter = null; $writer->close(); }
    r2EmailCheck($refused && $output === '', 'Write racing DDL yields fixed sanitized error');
    $after = $probe->createCommand('SHOW CREATE TABLE ' . $quotedProbe)->queryOne();
    $shape = static fn($row)=>preg_replace('/ AUTO_INCREMENT=\d+/', '', $row['Create Table']);
    r2EmailCheck($shape($before) === $shape($after), 'Failed atomic ALTER leaves no generated column/index/CHECK behind');
    r2EmailCheck((int)$probe->createCommand('SELECT COUNT(*) FROM ' . $quotedProbe)->queryScalar() === 2, 'Failed DDL never removes concurrent legacy rows');
    $probe->createCommand()->delete($probeTable, ['id'=>2])->execute(); r2EmailRun($probe);
    r2EmailCheck($probe->createCommand('SELECT email_normalized FROM ' . $quotedProbe)->queryScalar() === 'r2-ddl-race@example.invalid', 'Explicit fixture reconciliation permits safe retry');
} finally {
    R2EmailSchemaCommand::$beforeAlter = null;
    $probe->createCommand('DROP TABLE IF EXISTS ' . $quotedProbe)->execute();
    $db->createCommand('ALTER TABLE users ' . $addCheck)->execute();
    $probe->close();
}
r2EmailCheck($realSchema === $db->createCommand('SHOW CREATE TABLE users')->queryOne(), 'Real users schema restored exactly after probe suite');
r2EmailCheck((int)$db->createCommand('SELECT COUNT(*) FROM users')->queryScalar() === $realCount, 'Schema probes preserve real user count');

// Independent PHP processes and SQL connections compete for one normalized identity.
r2EmailCheck((int)$db->createCommand("SELECT COUNT(*) FROM users WHERE username IN ('r2-email-race-a','r2-email-race-b') OR email_normalized='r2-race@example.invalid'")->queryScalar() === 0, 'Concurrent INSERT fixture identities absent');
$children = [];
try {
    foreach (['a','b'] as $slot) {
        $process = proc_open([PHP_BINARY,__FILE__,'--r2-email-race',$slot], [0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','a']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('R2 concurrent fixture unavailable.');
        $children[] = ['process'=>$process,'pipes'=>$pipes];
        stream_set_timeout($pipes[1], 15);
        if (fgets($pipes[1]) !== "ready\n") throw new RuntimeException('R2 concurrent fixture did not initialize.');
    }
    foreach ($children as &$child) { fwrite($child['pipes'][0], 'x'); fclose($child['pipes'][0]); $child['pipes'][0] = null; }
    unset($child);
    $codes = [];
    foreach ($children as &$child) {
        // Child output is discarded; never echo SQL errors, rows, or credentials.
        stream_get_contents($child['pipes'][1]); fclose($child['pipes'][1]); $child['pipes'][1] = null;
        $codes[] = proc_close($child['process']); $child['process'] = null;
    }
    unset($child); sort($codes);
    r2EmailCheck($codes === [0,10], 'Two concurrent SQL INSERTs: one commits, one specifically receives duplicate-key 1062');
    r2EmailCheck((int)$db->createCommand("SELECT COUNT(*) FROM users WHERE email_normalized='r2-race@example.invalid'")->queryScalar() === 1, 'Concurrent writers produce exactly one normalized account');
    r2EmailCheck((int)$db->createCommand("SELECT COUNT(*) FROM users WHERE username IN ('r2-email-race-a','r2-email-race-b')")->queryScalar() === 1, 'Losing concurrent INSERT leaves no second account');
} finally {
    foreach ($children as $child) {
        foreach ($child['pipes'] as $pipe) if (is_resource($pipe)) fclose($pipe);
        if (is_resource($child['process'])) { proc_terminate($child['process']); proc_close($child['process']); }
    }
    $db->createCommand()->delete('users', ['username'=>['r2-email-race-a','r2-email-race-b']])->execute();
}
r2EmailCheck((int)$db->createCommand('SELECT COUNT(*) FROM users')->queryScalar() === $realCount, 'Race fixtures cleaned without changing original user count');
$beforeRollback = $db->createCommand('SHOW CREATE TABLE users')->queryOne();
$rollback = new m261112_135000_unique_normalized_user_email(['db'=>$db,'compact'=>true]);
ob_start(); $denied = $rollback->down(); ob_end_clean();
r2EmailCheck($denied === false && $beforeRollback === $db->createCommand('SHOW CREATE TABLE users')->queryOne(), 'Destructive rollback refused, unique email schema preserved');
echo "PASS {$r2EmailChecks} R2 email schema/SQL checks\n";
