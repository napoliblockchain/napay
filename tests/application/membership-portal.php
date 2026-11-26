<?php
// R3c-1: fee portal identity gates, disposable DB and local HTTP only.
if (PHP_SAPI !== 'cli') exit(1);
$root=dirname(__DIR__,2);require $root.'/config/env.php';
if(env('NAPAY_TEST_DB_CONFIRM')!=='isolated-fixture'||env('DB_DATABASE')!=='napay_test_runtime')exit(1);
define('YII_ENABLE_ERROR_HANDLER',false);
require $root.'/vendor/autoload.php';require $root.'/vendor/yiisoft/yii2/Yii.php';
use app\components\identity\AccountEligibility as Policy;
use app\components\association\MembershipFee;
use app\models\Users;
$app=new yii\web\Application(require $root.'/config/web.php');$app->user->enableSession=false;
$db=$app->db;if($db->createCommand('SELECT DATABASE()')->queryScalar()!=='napay_test_runtime')exit(1);
$checks=0;$jar=null;$jars=[];
register_shutdown_function(static function()use(&$jars){foreach($jars as$f)@unlink($f);});
function check($ok,string $label):void{global$checks;if(!$ok)throw new RuntimeException($label);++$checks;echo "PASS: $label\n";}
function scalar(string $sql,array $params=[]){return Yii::$app->db->createCommand($sql,$params)->queryScalar();}
function user(string $name,?int $type=null,int $level=0):int{
    $db=Yii::$app->db;$r=$db->createCommand("SELECT * FROM users WHERE username='runtime-user'")->queryOne();unset($r['id'],$r['email_normalized']);
    $r['username']=$r['email']='r3c-'.$name.'@example.invalid';$r['oauth_uid']='r3c-'.$name;
    $r['privilege_id']=(int)scalar('SELECT id FROM privileges WHERE level=:l ORDER BY id LIMIT 1',[':l'=>$level]);
    $db->createCommand()->insert('users',$r)->execute();$id=(int)$db->lastInsertID;
    if($type!==null)$db->createCommand()->insert('member_profiles',['user_id'=>$id,'is_corporate'=>$type,'denomination'=>$type===1?'PG '.$name:null])->execute();
    return$id;
}
function request(string $path,?array $post=null):array{
    global$jar;$c=curl_init('http://127.0.0.1'.$path);
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_PROXY=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEJAR=>$jar,CURLOPT_COOKIEFILE=>$jar]);
    if($post!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);
    $raw=curl_exec($c);if($raw===false)throw new RuntimeException('HTTP transport failed.');$size=curl_getinfo($c,CURLINFO_HEADER_SIZE);
    $r=['status'=>curl_getinfo($c,CURLINFO_RESPONSE_CODE),'headers'=>substr($raw,0,$size),'body'=>substr($raw,$size)];curl_close($c);return$r;
}
function csrf(array $page):string{$d=new DOMDocument();@$d->loadHTML($page['body'],LIBXML_NONET);$n=(new DOMXPath($d))->query('//meta[@name="csrf-token"]/@content');if($n->length!==1)throw new RuntimeException('CSRF missing');return$n->item(0)->nodeValue;}
function feeMenu(array $page):bool{
    $d=new DOMDocument();@$d->loadHTML($page['body'],LIBXML_NONET);
    foreach((new DOMXPath($d))->query('//aside//a/@href') as $href) {
        if(in_array(parse_url($href->nodeValue,PHP_URL_PATH),['/quota','/pay-fee/index'],true))return true;
    }
    return false;
}
function login(string $name):void{global$jar,$jars;$jar=tempnam('/tmp','napay-r3c-');$jars[]=$jar;$p=request('/site/login');check(request('/site/login',['_csrf'=>csrf($p),'LoginForm'=>['email'=>'r3c-'.$name.'@example.invalid','password'=>'fixture-password-only']])['status']===302,'Fixture login: '.$name);}
function links(array $page,string $path):array{
    $d=new DOMDocument();@$d->loadHTML($page['body'],LIBXML_NONET);$out=[];
    foreach((new DOMXPath($d))->query('//a/@href')as$node)if(parse_url($node->nodeValue,PHP_URL_PATH)===$path)$out[]=$node->nodeValue;
    return$out;
}
$pf=user('pf',0);$pg=user('pg',1);$manager=user('manager',1,30);$plain=user('plain');$operator=user('pos');$mixed=user('mixed',0);$admin=user('admin',null,50);$disabled=user('disabled',0);
$merchant=$db->createCommand('SELECT * FROM merchants ORDER BY id LIMIT 1')->queryOne();unset($merchant['id']);$merchant['user_id']=$manager;$merchant['description']='R3c PG merchant';
$db->createCommand()->insert('merchants',$merchant)->execute();$merchantId=(int)$db->lastInsertID;
foreach([$operator,$mixed]as$id)$db->createCommand()->insert('pos_operators',['user_id'=>$id,'merchant_id'=>$merchantId,'is_enabled'=>1,'created_at'=>time(),'updated_at'=>time()])->execute();
$db->createCommand()->insert('membership_fees',['description'=>'R3c annual fee','duration'=>1,'amount'=>'20.00'])->execute();
$feeId=(int)$db->lastInsertID;
$db->createCommand()->insert('payment_methods',['description'=>'R4 cash','min_level'=>0])->execute();$methodId=(int)$db->lastInsertID;
$pdf='%PDF-R4-member-fixture';
$db->createCommand()->insert('assembly_minutes',['meeting_date'=>'2026-01-01','description'=>'R4 <script>minute</script>','file_sha256'=>hash('sha256',$pdf),'file_content'=>$pdf])->execute();
$db->createCommand()->insert('assembly_minutes',['meeting_date'=>'2026-01-02','description'=>'R4 missing PDF'])->execute();
check(Policy::isOperatorAccount($operator)&&!Policy::isMemberAccount($operator),'POS operator is operational identity, not a member');
check(!Policy::isMemberAccount($mixed),'Mixed member/operator identity denied');
check(!Policy::isMemberAccount(2147483647),'Missing member identity denied');
$jar=tempnam('/tmp','napay-r3c-');$jars[]=$jar;check(request('/quota')['status']===302,'Guest quota requires login');
foreach(['/association/minutes','/association/minute-file?id=bad','/association/receipt?id=bad']as$path)check(request($path)['status']===302,'Guest association documents require login');
$before=(int)scalar('SELECT COUNT(*) FROM membership_payments');$profiles=(int)scalar('SELECT COUNT(*) FROM member_profiles');
foreach(['pos'=>$operator,'plain'=>$plain,'mixed'=>$mixed,'admin'=>$admin]as$name=>$id){
    login($name);$home=request('/');check($home['status']===200&&!feeMenu($home),'No fee menu for '.$name);
    foreach(['/quota','/pay-fee/index','/pay-fee/paypal-return?token=foreign','/pay-fee/paypal-cancel','/pay-fee/btcpay-return']as$path)check(request($path)['status']===403,'Member gate denies '.$name.' GET '.$path);
    foreach(['/pay-fee/start','/pay-fee/paypal-capture','/pay-fee/btcpay-refresh']as$path)check(request($path,['_csrf'=>csrf($home),'method'=>'paypal','token'=>'foreign','user_id'=>$pf])['status']===403,'Member gate denies '.$name.' POST '.$path);
    if($name!=='admin')foreach(['/association/minutes','/association/minute-file?id=bad','/association/receipt?id=bad']as$path)check(request($path)['status']===403,'Association documents deny non-member '.$name);
    $q=MembershipFee::quote(Users::findOne($id));check(!$q['payable']&&$q['reason']==='not_member'&&$q['paid_until']===null,'Quote fails closed for '.$name);
}
check((int)scalar('SELECT COUNT(*) FROM membership_payments')===$before&&(int)scalar('SELECT COUNT(*) FROM member_profiles')===$profiles,'Denied requests create neither payments nor member profiles');
foreach(['pf'=>$pf,'pg'=>$pg,'manager'=>$manager]as$name=>$id){
    login($name);check(Policy::isMemberAccount($id),'Positive membership evidence for '.$name);
    check(request('/quota')['status']===200&&request('/pay-fee/index')['status']===200,'Member fee page available to '.$name);
    check(feeMenu(request('/')),'Fee menu available to '.$name);
    check(MembershipFee::quote(Users::findOne($id))['payable'],'Positive member quote for '.$name);
    $minutes=request('/association/minutes');
    check($minutes['status']===200&&count(links($minutes,'/association/minutes'))===1,'Member minutes menu and page available: '.$name);
    check(str_contains($minutes['body'],'&lt;script&gt;minute&lt;/script&gt;')&&!str_contains($minutes['body'],'<script>minute</script>'),'Minutes descriptions escaped');
    check(links($minutes,'/association/minute-create')===[]&&links($minutes,'/association/minute-update')===[],'Member minutes have no write actions');
    $files=links($minutes,'/association/minute-file');$download=request($files[0]);
    check($download['status']===200&&$download['body']===$pdf&&str_contains(strtolower($download['headers']),'no-store'),'Member downloads PDF with private no-store cache');
    check(request('/association/minute-file?id=tampered')['status']===404&&request('/association/minute-file?id%5B%5D=x')['status']<500,'Invalid document IDs never yield 500');
    foreach(['/association/members','/association/payments','/association/tables','/association/minute-create']as$path)check(request($path)['status']===403,'Member cannot enter backoffice '.$path);
    check(request('/association/minute-update?id=bad',['_csrf'=>csrf($minutes)])['status']===403,'Member cannot mutate minutes even with valid CSRF');
    check(in_array(request('/association/minutes',['_csrf'=>csrf($minutes)])['status'],[400,405],true),'Minutes remain GET-only');
    $payment=['user_id'=>$id,'fee_id'=>$feeId,'payment_method_id'=>$methodId,'amount'=>'20.00','registered_on'=>date('Y-m-d'),'starts_on'=>date('Y').'-01-01','expires_on'=>date('Y').'-12-31','status'=>'paid'];
    $db->createCommand()->insert('membership_payments',$payment)->execute();$paymentId=(int)$db->lastInsertID;
    $page=request('/quota');$receipts=links($page,'/association/receipt');
    check(count($receipts)===1,'Exactly one receipt link for own paid payment');
    $receipt=request($receipts[0]);
    check($receipt['status']===200&&str_contains($receipt['body'],'data-napay-print')&&str_contains(strtolower($receipt['headers']),'no-store'),'Own receipt printable and private');
    check(links($receipt,'/association/payments')===[]&&links($receipt,'/association/member')===[]&&links($receipt,'/quota')!==[],'Receipt returns to own area without admin links');
    $db->createCommand()->update('membership_payments',['user_id'=>$plain],['id'=>$paymentId])->execute();
    check(request($receipts[0].'&user_id='.$id)['status']===404,'Valid session-encrypted ID cannot read another account receipt');
    $db->createCommand()->update('membership_payments',['user_id'=>$id,'status'=>'pending'],['id'=>$paymentId])->execute();
    check(request($receipts[0])['status']===404&&links(request('/quota'),'/association/receipt')===[],'Pending payment has neither receipt endpoint nor link');
    $db->createCommand()->update('membership_payments',['status'=>'paid'],['id'=>$paymentId])->execute();
    check(request('/association/receipt?id=tampered')['status']===404&&request('/association/receipt?id%5B%5D=x')['status']<500,'Malformed receipt IDs rejected safely');
}
login('disabled');$snapshot=Users::findOne($disabled);$db->createCommand()->update('users',['is_active'=>0],['id'=>$disabled])->execute();
check(!Policy::isMemberAccount($disabled)&&MembershipFee::quote($snapshot)['reason']==='not_member','Previously loaded identity cannot bypass disable');
check(in_array(request('/pay-fee/index')['status'],[302,403],true),'Disabled session cannot access fee page');
check(in_array(request('/association/minutes')['status'],[302,403],true),'Disabled session cannot consult minutes');
$db->createCommand()->update('users',['is_active'=>1],['id'=>$disabled])->execute();
login('pg');$db->createCommand()->update('member_profiles',['denomination'=>'   '],['user_id'=>$pg])->execute();
check(request('/pay-fee/index')['status']===403&&!Policy::isMemberAccount($pg),'PG with incomplete profile loses fee access');
check(request('/association/minutes')['status']===403,'PG with incomplete profile loses document access');
$db->createCommand()->update('member_profiles',['denomination'=>'PG pg'],['user_id'=>$pg])->execute();
check(Policy::merchantForManager($manager)===$merchantId,'PG merchant keeps its independent commercial identity');
// richiesta tornata in attesa con una sessione aperta: verbali e ricevute negati (difesa in profondita, il login la rifiuta gia)
$db->createCommand()->update('member_profiles',['approval_state'=>'pending'],['user_id'=>$pg])->execute();
foreach(['/association/minutes','/association/minute-file?id=bad','/association/receipt?id=bad']as$path)check(request($path)['status']===403,'Pending membership request denies association documents: '.$path);
$db->createCommand()->update('member_profiles',['approval_state'=>'approved'],['user_id'=>$pg])->execute();
check(request('/association/minutes')['status']===200,'Approved again: documents available');
echo "PASS $checks membership portal checks\n";
