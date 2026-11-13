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
    $r=['status'=>curl_getinfo($c,CURLINFO_RESPONSE_CODE),'body'=>substr($raw,$size)];curl_close($c);return$r;
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
$pf=user('pf',0);$pg=user('pg',1);$manager=user('manager',1,30);$plain=user('plain');$operator=user('pos');$mixed=user('mixed',0);$admin=user('admin',null,50);$disabled=user('disabled',0);
$merchant=$db->createCommand('SELECT * FROM merchants ORDER BY id LIMIT 1')->queryOne();unset($merchant['id']);$merchant['user_id']=$manager;$merchant['description']='R3c PG merchant';
$db->createCommand()->insert('merchants',$merchant)->execute();$merchantId=(int)$db->lastInsertID;
foreach([$operator,$mixed]as$id)$db->createCommand()->insert('pos_operators',['user_id'=>$id,'merchant_id'=>$merchantId,'is_enabled'=>1,'created_at'=>time(),'updated_at'=>time()])->execute();
$db->createCommand()->insert('membership_fees',['description'=>'R3c annual fee','duration'=>1,'amount'=>'20.00'])->execute();
check(Policy::isOperatorAccount($operator)&&!Policy::isMemberAccount($operator),'POS operator is operational identity, not a member');
check(!Policy::isMemberAccount($mixed),'Mixed member/operator identity denied');
check(!Policy::isMemberAccount(2147483647),'Missing member identity denied');
$jar=tempnam('/tmp','napay-r3c-');$jars[]=$jar;check(request('/quota')['status']===302,'Guest quota requires login');
$before=(int)scalar('SELECT COUNT(*) FROM membership_payments');$profiles=(int)scalar('SELECT COUNT(*) FROM member_profiles');
foreach(['pos'=>$operator,'plain'=>$plain,'mixed'=>$mixed,'admin'=>$admin]as$name=>$id){
    login($name);$home=request('/');check($home['status']===200&&!feeMenu($home),'No fee menu for '.$name);
    foreach(['/quota','/pay-fee/index','/pay-fee/paypal-return?token=foreign','/pay-fee/paypal-cancel','/pay-fee/btcpay-return']as$path)check(request($path)['status']===403,'Member gate denies '.$name.' GET '.$path);
    foreach(['/pay-fee/start','/pay-fee/paypal-capture','/pay-fee/btcpay-refresh']as$path)check(request($path,['_csrf'=>csrf($home),'method'=>'paypal','token'=>'foreign','user_id'=>$pf])['status']===403,'Member gate denies '.$name.' POST '.$path);
    $q=MembershipFee::quote(Users::findOne($id));check(!$q['payable']&&$q['reason']==='not_member'&&$q['paid_until']===null,'Quote fails closed for '.$name);
}
check((int)scalar('SELECT COUNT(*) FROM membership_payments')===$before&&(int)scalar('SELECT COUNT(*) FROM member_profiles')===$profiles,'Denied requests create neither payments nor member profiles');
foreach(['pf'=>$pf,'pg'=>$pg,'manager'=>$manager]as$name=>$id){
    login($name);check(Policy::isMemberAccount($id),'Positive membership evidence for '.$name);
    check(request('/quota')['status']===200&&request('/pay-fee/index')['status']===200,'Member fee page available to '.$name);
    check(feeMenu(request('/')),'Fee menu available to '.$name);
    check(MembershipFee::quote(Users::findOne($id))['payable'],'Positive member quote for '.$name);
}
login('disabled');$snapshot=Users::findOne($disabled);$db->createCommand()->update('users',['is_active'=>0],['id'=>$disabled])->execute();
check(!Policy::isMemberAccount($disabled)&&MembershipFee::quote($snapshot)['reason']==='not_member','Previously loaded identity cannot bypass disable');
check(in_array(request('/pay-fee/index')['status'],[302,403],true),'Disabled session cannot access fee page');
$db->createCommand()->update('users',['is_active'=>1],['id'=>$disabled])->execute();
login('pg');$db->createCommand()->update('member_profiles',['denomination'=>'   '],['user_id'=>$pg])->execute();
check(request('/pay-fee/index')['status']===403&&!Policy::isMemberAccount($pg),'PG with incomplete profile loses fee access');
$db->createCommand()->update('member_profiles',['denomination'=>'PG pg'],['user_id'=>$pg])->execute();
check(Policy::merchantForManager($manager)===$merchantId,'PG merchant keeps its independent commercial identity');
echo "PASS $checks membership portal checks\n";
