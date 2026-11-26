<?php
// Tabler list/detail presentation: isolated widget and real HTTP security checks.
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
$app->request->setUrl('/presentation');
$app->request->setScriptUrl('/index.php');
$app->request->setBaseUrl('');
Yii::setAlias('@webroot', $root.'/web');
Yii::setAlias('@web', '');
$app->assetManager->basePath = $root.'/web/assets';
$app->assetManager->baseUrl = '/assets';
// Pure widget regression: identifiers hidden, callbacks, ordering and encoding retained.
$provider = new yii\data\ArrayDataProvider(['allModels' => [['id' => 887766, 'name' => '<script>bad()</script>', 'amount' => 12]], 'pagination' => false, 'sort' => false]);
$html = app\widgets\TablerGridView::widget(['dataProvider' => $provider, 'columns' => [
    'id', ['class' => yii\grid\ActionColumn::class, 'template' => '{view}', 'urlCreator' => static fn() => '/safe-view',
        'contentOptions' => static fn() => ['data-preserved' => 'yes'],
        'buttons' => ['view' => static fn() => yii\helpers\Html::a('Open', '/safe-view', ['data-callback' => 'yes'])]],
    'name', ['attribute' => 'amount', 'format' => 'decimal'],
]]);
$x=xp($html);
check($x->query('//thead/tr/th')->length===3,'Technical ID column removed');
check($x->query('//thead/tr/th[last()][contains(@class,"text-end") and contains(.,"Azioni")]')->length===1,'Actions last and right aligned');
check($x->query('//tbody/tr/td[last()][@data-preserved="yes" and contains(@class,"text-end")]/a[@data-callback="yes" and @href="/safe-view"]')->length===1,'Caller cell closure and action callback preserved');
check($x->query('//tbody/tr/td[1]/script')->length===0 && str_contains($html,'&lt;script&gt;'),'Data remains encoded');
check($x->query('//table[contains(@class,"card-table") and contains(@class,"table-vcenter")]')->length===1,'Shared Tabler table classes');
check($x->query('//div[contains(@class,"table-responsive")]/table')->length===1,'Only table scrolls horizontally');
$empty=app\widgets\TablerGridView::widget(['dataProvider'=>new yii\data\ArrayDataProvider(['allModels'=>[], 'pagination'=>false]),'columns'=>['id','name']]);
check(xp($empty)->query('//td[@colspan="1"]')->length===1,'Empty state colspan matches visible columns');
$jar=tempnam('/tmp','napay-ui-');$jars[]=$jar;
check(http('/users/index')['status']===302,'Anonymous list requires login');
check(http('/users/view?id=invalid')['status']===302,'Anonymous detail requires login');
login('admin');
foreach(['users','merchants','stores','logs','privileges','settings'] as $area){
    $r=http('/'.$area.'/index');$x=xp($r['body']);
    check($r['status']===200,'List opens: '.$area);
    check($x->query('//main//table[contains(@class,"card-table")]')->length===1,'Tabler table: '.$area);
    check($x->query('//main//thead/tr[1]/th[normalize-space(.)="ID" or normalize-space(.)="Id"]')->length===0,'No visible ID header: '.$area);
    check($x->query('//main//thead/tr[1]/th[last()][contains(@class,"text-end") and contains(.,"Azioni")]')->length===1,'Final right actions: '.$area);
    check($x->query('//main//div[contains(@class,"card-header")]//h3[contains(@class,"card-title")]')->length===1,'Standard heading: '.$area);
    check($x->query('//main//div[contains(@class,"card-header")]//a/button')->length===0,'No nested interactive create controls: '.$area);
}
$list=http('/users/index');$x=xp($list['body']);
$userLink=$x->query('//tr[td[contains(.,"runtime-user")]]//a[contains(@href,"/users/view")]/@href')->item(0)->nodeValue;
$r=http($userLink);$x=xp($r['body']);
check($r['status']===200&&$x->query('//*[@data-napay-user-profile]')->length===1,'Admin sees new profile');
check($x->query('//*[@data-napay-account-details]')->length===1,'Account overview uses responsive description list');
check(!str_contains($r['body'],'<svg onload=')&&str_contains($r['body'],'&lt;svg'),'Stored profile XSS encoded');
check($x->query('//*[@id="user-profile-tabs"]')->length===1&&!str_contains($r['body'],'tabs-krajee'),'Native Bootstrap tabs replace legacy tabs');
check($x->query('//form[@id="users-form"]//*[@name="Users[is_active]"]')->length===1,'Admin status editor present');
check($x->query('//form[@id="users-form"]//*[@name="Users[privilege_id]" or @name="Users[merchant_id]"]')->length===0,'No unsupported privilege or tenant inputs');
check($x->query('//form[@id="users-form"]//input[@name="_csrf"]')->length===1,'Status form keeps CSRF');
check($x->query('//form[@id="users-form"]//button[@type="submit" and @data-confirm]')->length===1,'Status update keeps confirmation');
$home=http('/');$own=xp($home['body'])->query('//a[@data-napay-profile-link]/@href')->item(0)->nodeValue;
$ownPage=http($own);$x=xp($ownPage['body']);
check($ownPage['status']===200,'Own profile renders');
check($x->query('//*[@id="tab-settings"]')->length===1&&$x->query('//form[@id="users-form"]')->length===0,'Own preferences without hidden admin form');
check($x->query('//img[contains(@class,"profile-user-img")]')->length===0,'No legacy remote profile image');
check(http('/users/view?id=bad!')['status']===404,'Forged profile ID still rejected');
check(http(str_replace('/users/view','/users/update',$userLink))['status']===405,'State mutation still POST only');
check(http(str_replace('/users/view','/users/update',$userLink),['Users'=>['is_active'=>'0']])['status']===400,'State mutation without CSRF rejected');
login('user');check(http('/users/index')['status']===403,'Basic user cannot open user list');
$home=http('/');$own=xp($home['body'])->query('//a[@data-napay-profile-link]/@href')->item(0)->nodeValue;
$r=http($own);$x=xp($r['body']);
check($r['status']===200&&$x->query('//*[@id="tab-settings"]')->length===1,'Basic user has own preferences');
check($x->query('//form[@id="users-form"]')->length===0,'Basic user has no administration form');
check($x->query('//main//a[normalize-space(.)="Torna agli utenti"]')->length===0,'Basic user has no privileged back link');
echo "PASS $checks presentation HTTP/widget checks\n";
