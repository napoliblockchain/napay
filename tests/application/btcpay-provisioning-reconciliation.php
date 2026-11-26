<?php
// Fictitious GET-only queue jobs on a disposable real MySQL database and restricted worker account.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@app', $root);
use app\components\btcpay\provisioning\{ProvisioningJobs, ProvisioningStore};
$password = trim((string) fgets(STDIN));
if (!preg_match('/^[0-9a-f]{48}$/D', $password)) exit(1);
$uuid = static function (): string { $b=random_bytes(16); $b[6]=chr(ord($b[6])&15|64); $b[8]=chr(ord($b[8])&63|128); return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($b),4)); };
$connect = static function () use ($password): yii\db\Connection {
    $db = new yii\db\Connection(['dsn'=>env('DB_DSN'),'username'=>'napay_b1b_worker','password'=>$password,'charset'=>'utf8mb4']); $db->open(); return $db;
};
if (($argv[1] ?? '') === '--claim') {
    try {
        $queue = new ProvisioningJobs($connect(), $uuid());
        echo "ready\n"; fread(STDIN,1);
        $job = $queue->claimReconciliation($argv[2], ['store_link']);
        echo $job === null ? "none\n" : $job['id'] . "\n";
        exit(0);
    } catch (Throwable $error) { exit(2); }
}
$app = new yii\console\Application(require $root . '/config/console.php');
$admin = $app->db; $worker = $connect();
$time = time(); $clock = static function () use (&$time): int { return $time; };
$a = new ProvisioningJobs($worker, $uuid(),60,$clock);
$b = new ProvisioningJobs($connect(), $uuid(),60,$clock);
$merchant = (int) $admin->createCommand("SELECT id FROM merchants WHERE description='Runtime company merchant'")->queryScalar();
$checks = 0; $entity = 1000;
function check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); ++$checks; echo "PASS: $label\n"; }
$seed = static function (array $changes=[]) use ($admin,$merchant,$uuid,&$time,&$entity): array {
    $data = array_merge(['kind'=>'store_link','instance'=>bin2hex(random_bytes(32)),'merchant_id'=>$merchant,'entity_id'=>++$entity,'generation'=>1,
        'state'=>'reconcile_required','created_at'=>$time-100,'updated_at'=>$time-100,'in_flight_op'=>'create_store','in_flight_started_at'=>$time-90,'in_flight_owner'=>$uuid()],$changes);
    if ($data['in_flight_op'] === null) $data['in_flight_started_at']=$data['in_flight_owner']=null;
    $admin->createCommand()->insert('btcpay_provisioning_jobs',$data)->execute();
    return [(int)$admin->lastInsertID,$data['instance']];
};
$row = static fn(int $id): array => $admin->createCommand('SELECT * FROM btcpay_provisioning_jobs WHERE id=:id',[':id'=>$id])->queryOne();
$marker = static fn(array $row): array => array_intersect_key($row,array_flip(['in_flight_op','in_flight_started_at','in_flight_owner']));
[$id,$instance]=$seed(); $before=$marker($row($id));
$job=$a->claimReconciliation($instance,['store_link']); $version=$job['version'];
check($job['id']===$id && $job['reconciling']===true && $row($id)['state']==='reconcile_required' && $marker($row($id))===$before,'Claim assigns lease but keeps GET-only state and original marker');
check($b->claimReconciliation($instance,['store_link'])===null && $a->claim($instance)===null,'Other reconciliation worker and normal POST queue cannot steal the job');
$called=0; $touch=static function () use (&$called): void { ++$called; };
check($a->checkpoint($id,$version,'create_store',$touch)===null && $a->prepare($id,$version,$touch)===null && $a->advance($id,$version,$touch)===null && $called===0,'Normal preparation/checkpoint/advance cannot turn reconciliation into POST');
check($a->retryLater($id,$version,'no', $time+30)===null && $a->fail($id,$version,'no')===null && $a->succeed($id,$version)===null,'Normal retry/failure or success without adoption callback cannot erase uncertainty');
check($a->prepareReconciliation($id,$version+1,$touch)===null && $b->prepareReconciliation($id,$version,$touch)===null && $called===0,'Stale version and wrong owner do not execute callbacks');
$next=$a->prepareReconciliation($id,$version,$touch);
check($next===$version+1 && $called===1 && $marker($row($id))===$before,'Fenced reconciliation preparation preserves marker'); $version=$next;
$next=$a->retryReconciliation($id,$version,'not_found',$time+120);
check($next===$version+1 && $row($id)['state']==='reconcile_required' && $row($id)['lease_owner']===null && $marker($row($id))===$before,'GET retry releases lease and preserves uncertainty');
check($a->claimReconciliation($instance,['store_link'])===null,'No reconciliation before next_attempt_at');
$time+=121; $job=$b->claimReconciliation($instance,['store_link']); $version=$job['version'];
check($job!==null && $job['id']===$id && $marker($row($id))===$before,'Another worker reclaims due GET without changing marker');
check($a->prepareReconciliation($id,$version,$touch)===null,'Old owner cannot mutate reclaimed reconciliation');
$next=$b->retryReconciliation($id,$version,'admin_required',$time+90000,true);
$time+=90001;
check($row($id)['state']==='deferred' && $b->claimReconciliation($instance,['store_link'])===null && $b->claim($instance)===null && $marker($row($id))===$before,'Deferred reconciliation never auto-resumes, even after deadline');
$time=time();
[$id,$instance]=$seed(['kind'=>'pos_link','in_flight_op'=>'create_pos_app']);
check($a->claimReconciliation($instance,['store_link'])===null && $a->claimReconciliation($instance,['pos_link'])['id']===$id,'Kind filters isolate store and POS reconciliation');
[$id,$instance]=$seed(['in_flight_op'=>'create_pos_app']);
check($a->claimReconciliation($instance,['store_link'])===null && (int)$row($id)['attempts']===0,'Marker operation inconsistent with kind remains untouched');
[$id,$instance]=$seed(['in_flight_op'=>null]);
check($a->claimReconciliation($instance,['store_link'])['id']===$id && $row($id)['state']==='reconcile_required','Read-only reconciliation also supports an absent POST marker');
[$id,$instance]=$seed(['attempts'=>1000]); $before=$marker($row($id));
check($a->claimReconciliation($instance,['store_link'])===null && $row($id)['state']==='failed' && $marker($row($id))===$before,'Exhausted attempts fail without deleting in-flight evidence');
foreach ([[],['merchant_account'],['store_link',null],[['store_link']]] as $bad) {
    $refused=false; try { $a->claimReconciliation($instance,$bad); } catch (InvalidArgumentException $error) { $refused=true; }
    check($refused,'Invalid or non-link reconciliation kind refused');
}

// Atomic callback outcome and lease expiry rollback, on real restricted-worker mapping writes.
[$id,$instance]=$seed(); $job=$a->claimReconciliation($instance,['store_link']); $version=$job['version']; $before=$marker($row($id));
$store=new ProvisioningStore($worker,$instance,['k1'=>random_bytes(32)],'k1'); $account=$store->ensureAccount($merchant);
$thrown=false;
try { $a->prepareReconciliation($id,$version,static function () use ($store,$account): void { $store->updateAccount($account,['state'=>'user_requested']); throw new RuntimeException('fixture'); }); }
catch (RuntimeException $error) { $thrown=$error->getMessage()==='fixture'; }
check($thrown && $store->account($merchant)['state']==='pending' && (int)$row($id)['version']===$version,'Callback exception rolls back mapping and job version');
$savedTime=$time;
$result=$a->prepareReconciliation($id,$version,static function () use ($store,$account,&$time): void { $store->updateAccount($account,['state'=>'user_requested']); $time+=61; });
check($result===null && $store->account($merchant)['state']==='pending' && $marker($row($id))===$before,'Lease expiry inside callback rolls back mapping and preserves evidence');
$time=$savedTime;
$result=$a->succeed($id,$version,static function () use ($store,$account): void { if ($store->updateAccount($account,['state'=>'user_created','remote_user_id'=>'verified-by-get'])===null) throw new RuntimeException('stale'); });
check($result===$version+1 && $store->account($merchant)['state']==='user_created' && $row($id)['state']==='done' && $row($id)['in_flight_op']===null,'Successful GET adoption callback and job completion commit together');
[$id,$instance]=$seed(); $job=$a->claimReconciliation($instance,['store_link']); $time+=61; $called=0;
check($a->prepareReconciliation($id,$job['version'],$touch)===null && $called===0,'Expired lease refuses callback before any mapping write');
$time=time();
$tx=$worker->beginTransaction(); $refused=false;
try { $a->claimReconciliation($instance,['store_link']); } catch (LogicException $error) { $refused=$error->getMessage()==='caller_transaction_open'; }
$tx->rollBack(); check($refused,'Caller-owned transactions remain forbidden');

// Two workers start together; exactly one takes the only due reconciliation.
[$id,$instance]=$seed(); $children=[];
for ($i=0;$i<2;++$i) {
    $process=proc_open([PHP_BINARY,__FILE__,'--claim',$instance],[0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','a']],$pipes);
    fwrite($pipes[0],$password."\n"); fflush($pipes[0]); stream_set_timeout($pipes[1],15);
    if (fgets($pipes[1])!=="ready\n") throw new RuntimeException('child_start_failed');
    $children[]=[$process,$pipes];
}
foreach ($children as [,$pipes]) { fwrite($pipes[0],'x'); fclose($pipes[0]); }
$results=[];
foreach ($children as [$process,$pipes]) { $results[]=trim((string)fgets($pipes[1])); fclose($pipes[1]); check(proc_close($process)===0,'Concurrent reconciliation worker exits cleanly'); }
sort($results); $expected=[(string)$id,'none']; sort($expected);
check($results===$expected && (int)$row($id)['attempts']===1 && $row($id)['state']==='reconcile_required','Concurrent claims yield one owner and never a POST-capable state');
echo "PASS $checks GET-only reconciliation queue checks\n";
