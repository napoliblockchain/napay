<?php
// SIP-025: actual sidebar routes by identity, plus isolated widget rendering.
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__,2);require $root.'/config/env.php';
if(env('NAPAY_TEST_DB_CONFIRM')!=='isolated-fixture'||env('DB_DATABASE')!=='napay_test_runtime')exit(1);
define('YII_ENABLE_ERROR_HANDLER',false);
require $root.'/vendor/autoload.php';require $root.'/vendor/yiisoft/yii2/Yii.php';
$app=new yii\web\Application(require $root.'/config/web.php');$app->user->enableSession=false;$db=$app->db;
if($db->createCommand('SELECT DATABASE()')->queryScalar()!=='napay_test_runtime')exit(1);
$checks=0;$jar=null;$jars=[];
register_shutdown_function(static function()use(&$jars){foreach($jars as$f)@unlink($f);});
function check($ok,string $label):void{global$checks;if(!$ok)throw new RuntimeException($label);++$checks;echo "PASS: $label\n";}
function http(string $path,?array $post=null):array{
    global$jar;$c=curl_init('http://127.0.0.1'.$path);
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_PROXY=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEJAR=>$jar,CURLOPT_COOKIEFILE=>$jar]);
    if($post!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);
    $raw=curl_exec($c);if($raw===false)throw new RuntimeException('HTTP transport failed');$size=curl_getinfo($c,CURLINFO_HEADER_SIZE);
    $r=['status'=>curl_getinfo($c,CURLINFO_RESPONSE_CODE),'body'=>substr($raw,$size),'headers'=>substr($raw,0,$size)];curl_close($c);return$r;
}
function xp(string $html):DOMXPath{$d=new DOMDocument();@$d->loadHTML($html,LIBXML_NONET);return new DOMXPath($d);}
function login(string $role):void{
    global$jar,$jars;$jar=tempnam('/tmp','napay-nav-');$jars[]=$jar;$p=http('/site/login');$token=xp($p['body'])->query('//meta[@name="csrf-token"]/@content')->item(0)->nodeValue;
    check(http('/site/login',['_csrf'=>$token,'LoginForm'=>['email'=>$role.'@example.invalid','password'=>'fixture-password-only']])['status']===302,'Login '.$role);
}
function links(string $html):array{$result=[];foreach(xp($html)->query('//aside//ul[contains(@class,"navbar-nav")]//a/@href')as$a)$result[]= $a->nodeValue;return$result;}
function hasLink(string $html,string $path):bool{foreach(links($html)as$link)if(parse_url($link,PHP_URL_PATH)===$path)return true;return false;}
function allLinksWork(string $html,string $label):void{
    foreach(links($html)as$link){check(str_starts_with($link,'/')&&!str_starts_with($link,'//'),'Local navigation URL for '.$label);check(http($link)['status']===200,'Visible sidebar link works for '.$label.': '.$link);}
}
$ids=array_column($db->createCommand("SELECT username,id FROM users WHERE username LIKE 'runtime-%'")->queryAll(),'id','username');
$db->createCommand()->insert('member_profiles',['user_id'=>$ids['runtime-user'],'is_corporate'=>0])->execute();
$db->createCommand()->insert('member_profiles',['user_id'=>$ids['runtime-merchant'],'is_corporate'=>1,'denomination'=>'Navigation PG'])->execute();
$merchant=(int)$db->createCommand('SELECT id FROM merchants WHERE user_id=:u',[':u'=>$ids['runtime-merchant']])->queryScalar();
$db->createCommand()->update('users',['is_active'=>1],['id'=>$ids['runtime-blocked']])->execute();
$db->createCommand()->insert('pos_operators',['user_id'=>$ids['runtime-blocked'],'merchant_id'=>$merchant,'is_enabled'=>1,'created_at'=>time(),'updated_at'=>time()])->execute();
$jar=tempnam('/tmp','napay-nav-');$jars[]=$jar;check(http('/admin/index')['status']===302,'Guest admin route requires login');
foreach(['admin','merchant','user','blocked','operator']as$role){
    login($role);$home=http('/');check($home['status']===200,'Dashboard renders for '.$role);
    check(!str_contains($home['body'],'/invoices/index')&&!str_contains($home['body'],'/pos/index'),'No unimplemented dashboard or menu links for '.$role);
    check(!hasLink($home['body'],'/admin/index'),'No aggregate admin panel link for '.$role);
    allLinksWork($home['body'],$role);
    check(xp($home['body'])->query('//aside//a[@data-napay-profile-link]')->length===0,'Profile not duplicated in sidebar for '.$role);
    check(xp($home['body'])->query('//*[@data-bs-target="#napay-sidebar-menu" and @aria-controls="napay-sidebar-menu"]')->length===1,'Mobile sidebar binding preserved for '.$role);
    if($role==='admin'){
        foreach(['merchants','users','privileges','settings','logs']as$controller)check(hasLink($home['body'],'/'.$controller.'/index'),'Admin direct controller link: '.$controller);
        check(xp($home['body'])->query('//aside//button[@data-bs-toggle="dropdown"]')->length===3,'Admin sees Association, Merchants and Administration groups');
        $page=http('/association/payments');$x=xp($page['body']);
        check($x->query('//aside//button[@aria-expanded="true"]/span[contains(.,"Associazione")]')->length===1,'Current association group expanded');
        check($x->query('//aside//a[@aria-current="page" and contains(@href,"association/payments")]')->length===1,'Current association link marked');
        $old=http('/admin/index');check($old['status']===302&&str_contains($old['headers'],'/users'),'Old admin URL redirects to dedicated users controller');
    }elseif($role==='merchant'){
        check(hasLink($home['body'],'/stores/index')&&hasLink($home['body'],'/pos-management/index'),'Qualified manager sees stores and POS');
        check(!hasLink($home['body'],'/merchants/index')&&!hasLink($home['body'],'/users/index'),'Manager sees no administrator links');
        check(http('/admin/index')['status']===403,'Manager admin deep link still denied');
    }elseif($role==='user'){
        check(hasLink($home['body'],'/quota')&&!hasLink($home['body'],'/stores/index'),'PF sees own quota without commerce links');
    }elseif($role==='blocked'){
        check(count(links($home['body']))===1&&str_contains($home['body'],'Account operatore POS'),'Dedicated POS operator sees only dashboard and operator guidance');
        check(http('/pos-management/index')['status']===403&&http('/quota')['status']===403,'POS operator cannot use management or fee deep links');
    }else{
        check(hasLink($home['body'],'/users/index')&&!hasLink($home['body'],'/settings/index'),'Legacy level40 retains only its authorized user list');
        check(http('/admin/index')['status']===302,'Legacy level40 redirect preserves existing access');
    }
}
login('merchant');$db->createCommand()->delete('member_profiles',['user_id'=>$ids['runtime-merchant']])->execute();
$home=http('/');check(!hasLink($home['body'],'/stores/index')&&!hasLink($home['body'],'/pos-management/index'),'Unqualified legacy manager gets no commerce shortcuts');allLinksWork($home['body'],'unqualified manager');
// Widget labels are encoded by Menu normalization before rendering nested navigation.
$html=app\widgets\TablerSidebarMenu::widget(['route'=>'test/index','items'=>[
    ['label'=>'<img src=x onerror=bad()>','items'=>[
        ['label'=>'<script>bad()</script>','url'=>'/safe','active'=>true],
        ['label'=>'Other','url'=>'/other','target'=>'_blank'],
        ['label'=>'Hidden child','url'=>'/hidden','visible'=>false],
    ]],
    ['label'=>'Empty group','items'=>[['label'=>'Hidden','visible'=>false]]],
    ['label'=>'Single group','items'=>[['label'=>'Single item','url'=>'/single']]],
]]);
$x=xp($html);check(!str_contains($html,'<script>bad')&&!str_contains($html,'<img src=x'),'Group and child labels encoded');
check(!str_contains($html,'Hidden child')&&!str_contains($html,'Empty group')&&!str_contains($html,'Single group'),'Hidden, empty and single-child groups normalized');
check($x->query('//button[@type="button" and @data-bs-toggle="dropdown" and @aria-expanded="true"]')->length===1,'Group uses keyboard-capable Bootstrap button and active expansion');
$controls=$x->query('//button/@aria-controls')->item(0)->nodeValue;
check($x->query('//*[@id="'.$controls.'" and contains(@class,"show")]')->length===1,'Group controls existing expanded submenu');
check($x->query('//a[@href="/safe" and @aria-current="page"]')->length===1,'Nested active link has page state');
check($x->query('//a[@href="/other" and @target="_blank" and @rel="noopener"]')->length===1,'Nested external target keeps noopener');
check($x->query('//li/a[@href="/single"]')->length===1,'Single visible child flattened into a normal link');
echo "PASS $checks navigation HTTP/widget checks\n";
