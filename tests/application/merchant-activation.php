<?php
// SIP-023 R3b: disposable database only; HTTP, rollback and two independent workers.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root.'/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root.'/vendor/autoload.php'; require $root.'/vendor/yiisoft/yii2/Yii.php';
use app\components\identity\MerchantActivation as Activation;
use app\components\identity\AccountEligibility as Policy;
use app\models\Merchants;
use app\models\Users;
$app = new yii\web\Application(require $root.'/config/web.php');
$app->user->enableSession = false; $app->user->enableAutoLogin = false;
$db = $app->db;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);
function data(): array { return ['description'=>'R3b PG', 'email'=>'company@example.invalid', 'vatNumber'=>'12345678901',
    'phone'=>'123', 'mobile'=>'456', 'addressStreet'=>'Via Test', 'addressNumberHouse'=>'1', 'addressCity'=>'Napoli', 'addressZip'=>'80100', 'addressProvince'=>'NA', 'addressCountry'=>'IT']; }
function activate(int $owner, ?int $actor = null, array $fields = []): ?int {
    Yii::$app->user->setIdentity(Users::findOne($actor ?? $owner));
    return Activation::activate($actor ?? $owner, $owner, new Merchants($fields + data()));
}
if (($argv[1] ?? '') === '--race') {
    echo "ready\n"; fgets(STDIN);
    try { echo activate((int)$argv[2])."\n"; exit(0); } catch (Throwable $e) { exit(11); }
}
$checks=0;
function check($ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); ++$checks; echo "PASS: $label\n"; }
function denied(callable $f, string $label): void { try { $f(); } catch (DomainException $e) { check(true,$label); return; } throw new RuntimeException($label); }
function scalar(string $sql, array $params=[]){ return Yii::$app->db->createCommand($sql,$params)->queryScalar(); }
function user(string $name, ?int $type=1, int $level=0, int $active=1): int {
    $db=Yii::$app->db;
    $row=$db->createCommand("SELECT * FROM users WHERE username='runtime-user'")->queryOne();
    unset($row['id'],$row['email_normalized']);
    $row['username']=$row['email']=$name.'@example.invalid'; $row['oauth_uid']=$name;
    $row['privilege_id']=(int)scalar('SELECT id FROM privileges WHERE level=:l ORDER BY id LIMIT 1',[':l'=>$level]);
    $row['is_active']=$active;
    $db->createCommand()->insert('users',$row)->execute(); $id=(int)$db->lastInsertID;
    if ($type !== null) $db->createCommand()->insert('member_profiles',['user_id'=>$id,'is_corporate'=>$type,'denomination'=>$type===1?'PG '.$name:null])->execute();
    return $id;
}
$jar=tempnam('/tmp','napay-r3b-');
register_shutdown_function(static function()use($jar){@unlink($jar);});
function http(string $path, ?array $form=null, string $method='GET'): array {
    global $jar;
    $c=curl_init('http://127.0.0.1'.$path);
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_PROXY=>'',CURLOPT_COOKIEJAR=>$jar,CURLOPT_COOKIEFILE=>$jar,CURLOPT_FOLLOWLOCATION=>false]);
    if($form!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($form)]);
    elseif($method!=='GET')curl_setopt($c,CURLOPT_CUSTOMREQUEST,$method);
    $raw=curl_exec($c);if($raw===false)throw new RuntimeException('HTTP transport failed.');
    $size=curl_getinfo($c,CURLINFO_HEADER_SIZE);$r=['status'=>curl_getinfo($c,CURLINFO_RESPONSE_CODE),'body'=>substr($raw,$size),'headers'=>substr($raw,0,$size)];curl_close($c);return $r;
}
function csrf(array $page): string { $d=new DOMDocument();@$d->loadHTML($page['body'],LIBXML_NONET);$n=(new DOMXPath($d))->query('//meta[@name="csrf-token"]/@content'); if($n->length!==1)throw new RuntimeException('CSRF missing.');return $n->item(0)->nodeValue; }
function login(string $email):void { global $jar;file_put_contents($jar,'');$p=http('/site/login');check(http('/site/login',['_csrf'=>csrf($p),'LoginForm'=>['email'=>$email,'password'=>'fixture-password-only']])['status']===302,'Fixture login succeeds'); }
$admin=(int)scalar("SELECT id FROM users WHERE username='runtime-admin'");
$pg=user('r3b-pg');$pf=user('r3b-pf',0);$inactive=user('r3b-inactive',1,0,0);$plain=user('r3b-plain',null);
$operator=user('r3b-operator',null);
$existingMerchant=(int)scalar('SELECT id FROM merchants ORDER BY id LIMIT 1');
$db->createCommand()->insert('pos_operators',['user_id'=>$operator,'merchant_id'=>$existingMerchant,'is_enabled'=>0,'created_at'=>time(),'updated_at'=>time()])->execute();
check(http('/merchants/activate')['status']===302,'Guest activation requires login');
foreach ([$pf,$inactive,$plain,$operator] as $id) denied(fn()=>activate($id),'Ineligible identity rejected by service');
denied(fn()=>activate($pg,$pf),'Another basic account cannot activate this PG');
login('r3b-pf@example.invalid');check(http('/merchants/activate')['status']===403,'PF denied by HTTP gate');
login('r3b-pg@example.invalid');$p=http('/merchants/activate');
check($p['status']===200,'Eligible PG sees activation form');
check(str_contains(http('/')['body'],'data-napay-activate-merchant-link'),'PG gets account menu activation link');
check(http('/merchants/activate',data())['status']===400,'Activation requires CSRF');
check(http('/merchants/activate',null,'PUT')['status']===405,'Unsupported activation method denied');
foreach (['description','vatNumber','email'] as $field) {
    $r=http('/merchants/activate',['_csrf'=>csrf($p),'Merchants'=>[$field=>['bad']]+data()]);
    check($r['status']===200&&!Policy::merchantForManager($pg),'Malformed text rerenders safely without activation');
}
$r=http('/merchants/activate',['_csrf'=>csrf($p),'Merchants'=>data()+['user_id'=>$pf,'privilege_id'=>1,'is_active'=>2,'id'=>$existingMerchant]]);
check($r['status']===302&&str_contains($r['headers'],'/merchants/company'),'PG activation redirects to its company');
$id=Policy::merchantForManager($pg);check($id!==null,'PG now qualifies as its unique merchant manager');
check((int)scalar('SELECT user_id FROM merchants WHERE id=:id',[':id'=>$id])===$pg,'Posted foreign owner ignored');
check((int)scalar('SELECT is_merchant FROM users WHERE id=:id',[':id'=>$pg])===1,'Legacy merchant flag aligned atomically');
check((int)scalar('SELECT COUNT(*) FROM merchants WHERE user_id=:id',[':id'=>$pf])===0,'Target PF has no merchant');
check(http('/merchants/activate', ['_csrf'=>csrf(http('/merchants/company')),'Merchants'=>data()])['status']===302,'Repeated HTTP activation is idempotent');
check(activate($pg)===$id&&(int)scalar('SELECT COUNT(*) FROM merchants WHERE user_id=:id',[':id'=>$pg])===1,'Repeated service activation returns the same merchant');
check(http('/merchants/create')['status']===403,'Manager cannot use legacy create to make a second company');
$vat=scalar('SELECT vatNumber FROM merchants WHERE id=:id',[':id'=>$id]);
$p=http('/merchants/company');http('/merchants/company',['_csrf'=>csrf($p),'Merchants'=>['vatNumber'=>'99999999999']]);
check(scalar('SELECT vatNumber FROM merchants WHERE id=:id',[':id'=>$id])===$vat,'VAT remains immutable after activation');
check(!str_contains(http('/')['body'],'data-napay-activate-merchant-link'),'Activated manager no longer sees activation link');
$new=user('r3b-admin-pg');login('admin@example.invalid');$p=http('/merchants/create');
check(!str_contains($p['body'],'r3b-pf@example.invalid')&&str_contains($p['body'],'r3b-admin-pg@example.invalid'),'Admin selector only offers eligible PGs');
check(http('/merchants/create',['_csrf'=>csrf($p),'Merchants'=>data()+['user_id'=>$pf]])['status']===403,'Crafted admin create cannot promote PF');
check(http('/merchants/create',['_csrf'=>csrf($p),'Merchants'=>data()+['user_id'=>$new]])['status']===302&&Policy::merchantForManager($new)!==null,'Admin create uses the same qualified activation service');
$bad=user('r3b-bad');check(activate($bad,null,['email'=>'invalid'])===null&&Policy::canActivateMerchant($bad),'Invalid company data leaves PG eligible and unchanged');
$role=(int)scalar('SELECT id FROM privileges WHERE level=30 ORDER BY id LIMIT 1');
$db->createCommand()->insert('privileges',['level'=>30,'description'=>'R3b duplicate','codice_ruolo'=>'R3B_DUP'])->execute();$dup=(int)$db->lastInsertID;
try{denied(fn()=>activate($bad),'Ambiguous merchant privilege catalog denied');}finally{$db->createCommand()->delete('privileges',['id'=>$dup])->execute();}
$db->createCommand()->update('privileges',['level'=>31],['id'=>$role])->execute();
try{denied(fn()=>activate($bad),'Missing merchant privilege catalog denied');}finally{$db->createCommand()->update('privileges',['level'=>30],['id'=>$role])->execute();}
$rollback=user('r3b-rollback');
$db->createCommand("ALTER TABLE users ADD CONSTRAINT r3b_fail_role CHECK (email <> 'r3b-rollback@example.invalid' OR is_merchant = 0)")->execute();
try { $failed=false;try{activate($rollback);}catch(yii\db\Exception $e){$failed=true;}check($failed,'Injected user update failure reached'); }
finally{$db->createCommand('ALTER TABLE users DROP CHECK r3b_fail_role')->execute();}
check(Policy::canActivateMerchant($rollback)&&(int)scalar('SELECT COUNT(*) FROM merchants WHERE user_id=:id',[':id'=>$rollback])===0,'Failed promotion rolls back merchant and role together');
$race=user('r3b-race');$workers=[];
foreach([1,2]as$_){$p=proc_open([PHP_BINARY,__FILE__,'--race',(string)$race],[0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','a']],$pipes);if(!is_resource($p))throw new RuntimeException('Worker unavailable');stream_set_timeout($pipes[1],30);if(fgets($pipes[1])!=="ready\n")throw new RuntimeException('Worker not ready');$workers[]=[$p,$pipes];}
foreach($workers as[$p,$pipes]){fwrite($pipes[0],"go\n");fclose($pipes[0]);}
$results=[];foreach($workers as[$p,$pipes]){$results[]=trim(stream_get_contents($pipes[1]));fclose($pipes[1]);check(proc_close($p)===0,'Concurrent activation worker completed');}
check($results[0]!==''&&$results[0]===$results[1]&&(int)scalar('SELECT COUNT(*) FROM merchants WHERE user_id=:id',[':id'=>$race])===1,'Concurrent activation returns one identical merchant');
check((int)scalar('SELECT COUNT(*) FROM stores WHERE merchant_id=:id',[':id'=>$id])===0&&(int)scalar('SELECT COUNT(*) FROM pos_operators WHERE user_id=:id',[':id'=>$pg])===0,'Activation creates no store or operator');
echo "PASS $checks merchant activation checks\n";
