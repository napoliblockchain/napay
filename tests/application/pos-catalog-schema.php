<?php
// C1 only: all destructive/failure probes are confined to this disposable database.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
if (($argv[1] ?? '') === '--race-profile') {
    try {
        $connection = Yii::createObject(require $root . '/config/db.php');
        if ($connection->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(2);
        fwrite(STDOUT, "ready\n"); fread(STDIN, 1);
        $connection->createCommand()->insert('pos_operators', ['user_id'=>(int)$argv[2], 'merchant_id'=>(int)$argv[3], 'created_at'=>time(), 'updated_at'=>time()])->execute();
        exit(0);
    } catch (yii\db\IntegrityException $e) { exit(10); }
    catch (Throwable $e) { exit(2); }
}
$app = new yii\console\Application(require $root . '/config/console.php');
$db = $app->db;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);
require $root . '/migrations/m261112_130000_create_pos_catalog.php';
$checks = 0;
function check($value, string $label): void {
    global $checks;
    if (!$value) throw new RuntimeException($label);
    ++$checks; echo 'PASS: ' . $label . "\n";
}
function rejects(callable $operation, string $label): void {
    $rejected = false;
    try { $operation(); } catch (yii\db\Exception $e) {
        $rejected = in_array((int)($e->errorInfo[1] ?? 0), [1062,1451,1452,3819], true);
        if (!$rejected) throw $e;
    }
    check($rejected, $label);
}
$run = static function () use ($db): void {
    $migration = new m261112_130000_create_pos_catalog(['db'=>$db, 'compact'=>true]);
    $migration->up();
};
$tables = ['pos_operators','pos_terminals','pos_store_operators'];
foreach ($tables as $table) check((int)$db->createCommand('SELECT COUNT(*) FROM '.$table)->queryScalar() === 0, 'No automatic provisioning: ' . $table);
$before = $db->createCommand('SHOW CREATE TABLE stores')->queryOne();
$run();
check($before === $db->createCommand('SHOW CREATE TABLE stores')->queryOne(), 'Exact migration replay preserves parent schema');
// Simulate only metadata from a foreign schema; no second DB or broader grants.
class C1ForeignSchemaCommand extends yii\db\Command {
    public function queryAll($fetchMode = null) {
        $rows = parent::queryAll($fetchMode);
        foreach ($rows as &$row) if (array_key_exists('REFERENCED_TABLE_SCHEMA', $row)) $row['REFERENCED_TABLE_SCHEMA'] = 'foreign-fixture';
        return $rows;
    }
}
$commandClass = $db->commandClass; $rejected = false;
try {
    $db->commandClass = C1ForeignSchemaCommand::class;
    $run();
} catch (RuntimeException $e) { $rejected = true; }
finally { $db->commandClass = $commandClass; }
check($rejected, 'Foreign-schema FK metadata rejected (simulated metadata, real local schema)');
// Simulate interruption after two complete CREATE TABLE statements.
$db->createCommand('DROP TABLE pos_store_operators')->execute();
$run();
check($db->schema->getTableSchema('pos_store_operators', true) !== null, 'Partial DDL resumed without replacing existing tables');
$db->createCommand('ALTER TABLE pos_terminals ADD COLUMN incompatible INT NULL')->execute();
$rejected = false;
try { $run(); } catch (RuntimeException $e) { $rejected = true; }
check($rejected && isset($db->schema->getTableSchema('pos_terminals', true)->columns['incompatible']), 'Incompatible existing table refused, never repaired automatically');
$db->createCommand('ALTER TABLE pos_terminals DROP COLUMN incompatible')->execute();
$run();
$db->createCommand('ALTER TABLE pos_terminals ALTER CHECK `ck-pos-terminals-enabled` NOT ENFORCED')->execute();
$rejected = false;
try { $run(); } catch (RuntimeException $e) { $rejected = true; }
check($rejected, 'Existing unenforced CHECK refused');
$db->createCommand('ALTER TABLE pos_terminals ALTER CHECK `ck-pos-terminals-enabled` ENFORCED')->execute();
$run();
$db->createCommand('ALTER TABLE pos_terminals DROP CHECK `ck-pos-terminals-name`, ADD CONSTRAINT `ck-pos-terminals-name` CHECK (CHAR_LENGTH(TRIM(name)>0)>0)')->execute();
$rejected = false;
try { $run(); } catch (RuntimeException $e) { $rejected = true; }
check($rejected, 'Changed CHECK grouping cannot masquerade as expected definition');
$db->createCommand('ALTER TABLE pos_terminals DROP CHECK `ck-pos-terminals-name`, ADD CONSTRAINT `ck-pos-terminals-name` CHECK (CHAR_LENGTH(TRIM(name))>0)')->execute();
$run();
// Equivalent parent index may have another name; reserved-name collisions must fail.
$db->createCommand('ALTER TABLE stores RENAME INDEX `uq-pos-stores-id-merchant` TO c1_equivalent')->execute();
$run();
check(count($db->createCommand("SHOW INDEX FROM stores WHERE Key_name='c1_equivalent'")->queryAll()) === 2, 'Equivalent existing parent index reused');
$db->createCommand('CREATE INDEX `uq-pos-stores-id-merchant` ON stores(description)')->execute();
$rejected = false;
try { $run(); } catch (RuntimeException $e) { $rejected = true; }
check($rejected, 'Reserved index name with wrong definition refused');
$db->createCommand('DROP INDEX `uq-pos-stores-id-merchant` ON stores')->execute();
$db->createCommand('ALTER TABLE stores RENAME INDEX c1_equivalent TO `uq-pos-stores-id-merchant`')->execute();
$run();
$users = $db->createCommand("SELECT username,id FROM users WHERE username IN ('runtime-user','runtime-other','runtime-disabled')")->queryAll();
$ids = array_column($users, 'id', 'username');
$merchants = $db->createCommand("SELECT description,id FROM merchants WHERE description IN ('Runtime company merchant','Runtime company other')")->queryAll();
$companies = array_column($merchants, 'id', 'description');
$a = (int)$companies['Runtime company merchant']; $b = (int)$companies['Runtime company other']; $u = (int)$ids['runtime-user'];
$store = ['merchant_id'=>$a,'description'=>'C1 fixture','email'=>'fixture@example.invalid','phone'=>'','mobile'=>'','addressStreet'=>'','addressNumberHouse'=>'','addressCity'=>'','addressZip'=>'','addressProvince'=>'','addressCountry'=>''];
$stores = [];
foreach ([$a,$a,$b,$a] as $merchant) { $db->createCommand()->insert('stores', array_replace($store, ['merchant_id'=>$merchant]))->execute(); $stores[]=(int)$db->lastInsertID; }
$profile = new app\models\PosOperators(['user_id'=>$u,'merchant_id'=>$a]);
check($profile->save() && (int)$profile->is_enabled === 0, 'Operator defaults disabled');
check((int)$profile->created_at > 0 && (int)$profile->updated_at >= (int)$profile->created_at, 'Server timestamps populated');
$profile->is_enabled=1; check($profile->save(), 'Operator explicitly enabled');
$grant = [];
foreach ([$stores[0],$stores[1]] as $storeId) {
    $g = new app\models\PosStoreOperators(['user_id'=>$u,'merchant_id'=>$a,'store_id'=>$storeId]);
    check($g->save() && (int)$g->is_enabled === 0, 'Same-merchant multi-store grant defaults disabled'); $grant[]=$g;
}
$now=time(); $base=['is_enabled'=>0,'created_at'=>$now,'updated_at'=>$now];
// Independent connections released together: the primary key serializes the race.
$children = [];
foreach ([$a,$b] as $company) {
    $process = proc_open([PHP_BINARY,__FILE__,'--race-profile',(string)$ids['runtime-other'],(string)$company], [0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','a']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Concurrency fixture unavailable.');
    stream_set_timeout($pipes[1], 10);
    if (fgets($pipes[1]) !== "ready\n") throw new RuntimeException('Concurrency fixture did not initialize.');
    $children[] = [$process,$pipes];
}
foreach ($children as [$process,$pipes]) { fwrite($pipes[0], 'x'); fclose($pipes[0]); }
$codes = [];
foreach ($children as [$process,$pipes]) { fclose($pipes[1]); $codes[]=proc_close($process); }
sort($codes);
check($codes === [0,10] && (int)$db->createCommand('SELECT COUNT(*) FROM pos_operators WHERE user_id=:u',[':u'=>$ids['runtime-other']])->queryScalar() === 1, 'Concurrent different-merchant assignment creates only one operator binding');
rejects(fn()=>$db->createCommand()->insert('pos_operators', ['user_id'=>$u,'merchant_id'=>$b]+$base)->execute(), 'Second merchant for same operator rejected by DB');
rejects(fn()=>$db->createCommand()->insert('pos_store_operators', ['user_id'=>$u,'merchant_id'=>$a,'store_id'=>$stores[2]]+$base)->execute(), 'Cross-merchant store rejected by DB');
rejects(fn()=>$db->createCommand()->insert('pos_store_operators', ['user_id'=>$u,'merchant_id'=>$b,'store_id'=>$stores[2]]+$base)->execute(), 'Spoofed operator merchant rejected by DB');
rejects(fn()=>$db->createCommand()->insert('pos_store_operators', ['user_id'=>$u,'merchant_id'=>$a,'store_id'=>$stores[0]]+$base)->execute(), 'Duplicate grant rejected by DB');
rejects(fn()=>$db->createCommand()->insert('pos_operators', ['user_id'=>2147483647,'merchant_id'=>$a]+$base)->execute(), 'Missing user FK rejected');
rejects(fn()=>$db->createCommand()->insert('pos_operators', ['user_id'=>$ids['runtime-disabled'],'merchant_id'=>2147483647]+$base)->execute(), 'Missing merchant FK rejected');
rejects(fn()=>$db->createCommand()->update('pos_operators',['is_enabled'=>2],['user_id'=>$u])->execute(), 'Operator enabled CHECK enforced');
rejects(fn()=>$db->createCommand()->update('pos_store_operators',['is_enabled'=>-1],['user_id'=>$u,'store_id'=>$stores[0]])->execute(), 'Grant enabled CHECK enforced');
$terminal = new app\models\PosTerminals(['merchant_id'=>$a,'store_id'=>$stores[0],'name'=>'  Cassa principale  ']);
check($terminal->save() && (int)$terminal->is_enabled === 0, 'Terminal defaults disabled');
check($terminal->name === 'Cassa principale' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$terminal->public_id), 'Name normalized; cryptographic canonical UUID v4 generated');
$copy = $terminal->getAttributes(); unset($copy['id']);
rejects(fn()=>$db->createCommand()->insert('pos_terminals',$copy)->execute(), 'Duplicate public UUID rejected');
foreach ([['public_id'=>'short'],['public_id'=>'00000000-0000-4000-8000-000000000001','name'=>'   '],['public_id'=>'00000000-0000-4000-8000-000000000002','is_enabled'=>2],['public_id'=>'00000000-0000-4000-8000-000000000003','created_at'=>-1],['public_id'=>'00000000-0000-4000-8000-000000000004','updated_at'=>0]] as $bad) {
    rejects(fn()=>$db->createCommand()->insert('pos_terminals',array_replace($copy,$bad))->execute(), 'Terminal CHECK constraints enforced');
}
rejects(fn()=>$db->createCommand()->insert('pos_terminals',array_replace($copy,['public_id'=>'00000000-0000-4000-8000-000000000005','merchant_id'=>$b]))->execute(), 'Terminal store/merchant mismatch rejected');
rejects(fn()=>$db->createCommand()->update('pos_operators',['merchant_id'=>$b],['user_id'=>$u])->execute(), 'Operator transfer blocked even with disabled grants');
rejects(fn()=>$db->createCommand()->update('stores',['merchant_id'=>$b],['id'=>$stores[0]])->execute(), 'Store transfer blocked by POS references');
foreach ([['users',['id'=>$u]],['merchants',['id'=>$a]],['stores',['id'=>$stores[0]]],['pos_operators',['user_id'=>$u]]] as [$table,$where]) rejects(fn()=>$db->createCommand()->delete($table,$where)->execute(), 'Referenced parent deletion blocked: '.$table);
$terminal->store_id=$stores[1]; check(!$terminal->save(), 'Terminal reassignment refused by model'); $terminal->refresh();
$profile->merchant_id=$b; check(!$profile->save(), 'Operator reassignment refused by model'); $profile->refresh();
$grant[0]->store_id=$stores[3];
check(!$grant[0]->save() && $grant[0]->getFirstError('store_id') === 'Grant reassignment requires an approved administrative operation.', 'Grant identity cannot be changed by model, independently of duplicate validation');
foreach ([['name'=>[]],['name'=>str_repeat('x',257)],['name'=>'   '],['public_id'=>'invalid-uuid']] as $bad) {
    $model = new app\models\PosTerminals(array_replace(['merchant_id'=>$a,'store_id'=>$stores[0],'name'=>'Valid'], $bad));
    check(!$model->validate(), 'Malformed terminal model rejected');
}
$model = new app\models\PosTerminals(); $model->setAttributes(['public_id'=>'attacker','id'=>999,'created_at'=>1,'updated_at'=>1]);
check($model->public_id === null && $model->id === null && $model->created_at === null, 'Generated identity and timestamps not mass assignable');
$db->createCommand()->delete('pos_store_operators',['user_id'=>$u])->execute();
check(app\models\PosOperators::findOne($u)->merchant_id == $a, 'Removing last grant does not remove merchant binding');
$run(); check(app\models\PosTerminals::findOne($terminal->id)->public_id === $terminal->public_id, 'Replay preserves provisioned POS records');
$rollback = new m261112_130000_create_pos_catalog(['db'=>$db,'compact'=>true]);
ob_start(); $denied = $rollback->down(); ob_end_clean(); check($denied === false, 'Destructive rollback disabled');
echo "PASS $checks POS C1 schema/model checks\n";
