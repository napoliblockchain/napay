<?php
// SIP-040 RV3: prima bozza dello stesso tipo creata da due processi/connessioni sovrapposti. Atteso: una sola bozza,
// nessuna eccezione, entrambi ricevono la stessa bozza; nuova chiamata idempotente. DB usa e getta.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
$app = new yii\web\Application(require $root . '/config/web.php');
$db = $app->db;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);
$actor = $argv[1] ?? '';
$barrier = sys_get_temp_dir() . '/napay-legal-race';
if ($actor === 'prepare') {
    $db->createCommand()->delete('{{%legal_documents}}', ['type' => 'cookies'])->execute();
    @mkdir($barrier, 0700); array_map('unlink', glob("$barrier/*") ?: []);
    exit(0);
}
if ($actor === 'verify') {
    $rows = $db->createCommand("SELECT id, status FROM legal_documents WHERE type='cookies'")->queryAll();
    $outputs = array_map(static fn($l) => json_decode($l, true), array_filter(array_map('trim', file('php://stdin'))));
    $ids = array_unique(array_column($outputs, 'id'));
    $ok = count($rows) === 1 && $rows[0]['status'] === 'draft' && count($outputs) === 2
        && array_column($outputs, 'result') === ['draft', 'draft'] && $ids === [(int) $rows[0]['id']];
    echo ($ok ? 'PASS' : 'FAIL') . ": Two overlapping connections create the first draft once, both get it, no exception\n";
    $again = app\models\LegalDocuments::newDraft('cookies', 'Cookie', 'x', 1);
    $idempotent = (int) $again->id === (int) $rows[0]['id'] && (int) $db->createCommand("SELECT COUNT(*) FROM legal_documents WHERE type='cookies'")->queryScalar() === 1;
    echo ($idempotent ? 'PASS' : 'FAIL') . ": A later request returns the same draft (idempotent recovery)\n";
    exit($ok && $idempotent ? 0 : 1);
}
if (!in_array($actor, ['a', 'b'], true)) exit(1);
$GLOBALS['actor'] = $actor;
class LegalDraftRace extends app\models\LegalDocuments {
    public static function tableName() { return '{{%legal_documents}}'; }
    // chi entra per primo tiene il lock più a lungo: l'altra richiesta è già in attesa
    protected static function nextVersion(string $type): int { usleep(1500000); return parent::nextVersion($type); }
}
file_put_contents("$barrier/$actor", 'ready');
$until = microtime(true) + 10;
while (!is_file("$barrier/a") || !is_file("$barrier/b")) {
    if (microtime(true) > $until) { echo json_encode(['actor' => $actor, 'result' => 'barrier_timeout']) . "\n"; exit(1); }
    usleep(5000);
}
try {
    $draft = LegalDraftRace::newDraft('cookies', 'Race fixture', 'Synthetic text.', 1);
    echo json_encode(['actor' => $actor, 'result' => 'draft', 'id' => (int) $draft->id]) . "\n";
} catch (Throwable $error) {
    echo json_encode(['actor' => $actor, 'result' => 'exception', 'class' => get_class($error),
        'driver_code' => $error instanceof yii\db\Exception ? ($error->errorInfo[1] ?? null) : null]) . "\n";
}
