<?php
// R3a: real HTTP, file mail, and isolated SQL probes; never mutate copied legacy identities.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__,2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime' || env('MAIL_TRANSPORT') !== 'file') exit(1);
define('YII_ENABLE_ERROR_HANDLER',false);
require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
use app\models\Users;
use app\models\MemberProfiles;
use app\models\forms\SignupForm;
$app = new yii\web\Application(require $root . '/config/web.php');
$app->request->scriptFile = $root . '/web/index.php'; $app->request->scriptUrl = '/index.php'; $app->request->hostInfo = 'http://127.0.0.1';
$app->user->enableSession = false; $app->session->open();
$app->controller = new app\controllers\SiteController('site',$app);
$app->controller->action = new yii\base\Action('signup',$app->controller);
$db = $app->db; $db->enableLogging = false; $db->enableProfiling = false;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);
$msChecks = 0; $msJar = tempnam('/tmp','napay-member-signup-');
$msPassword = 'r3a-fixture-password';
function msCheck($ok,string $label): void { global $msChecks; if (!$ok) throw new RuntimeException($label); ++$msChecks; echo 'PASS: ' . $label . "\n"; }
function msHttp(string $path,?array $form = null): array {
    global $msJar;
    $curl = curl_init('http://127.0.0.1' . $path);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20,
        CURLOPT_PROXY=>'',CURLOPT_COOKIEJAR=>$msJar,CURLOPT_COOKIEFILE=>$msJar,CURLOPT_HTTPHEADER=>['Host: attacker.example.invalid']]);
    if ($form !== null) curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($form)]);
    $raw = curl_exec($curl);
    if ($raw === false) { curl_close($curl); throw new RuntimeException('Member signup HTTP transport failed.'); }
    $size = curl_getinfo($curl,CURLINFO_HEADER_SIZE);
    $r = ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'headers'=>substr($raw,0,$size),'body'=>substr($raw,$size)];
    curl_close($curl); return $r;
}
function msDom(array $page): DOMXPath { $doc = new DOMDocument(); @$doc->loadHTML($page['body'],LIBXML_NONET); return new DOMXPath($doc); }
function msCsrf(array $page): string {
    $nodes = msDom($page)->query('//meta[@name="csrf-token"]/@content');
    if ($nodes->length !== 1) throw new RuntimeException('Member signup CSRF unavailable.'); return $nodes->item(0)->nodeValue;
}
function msFields(string $email,string $type = 'pf',string $denomination = ''): array {
    global $msPassword;
    return ['email'=>$email,'first_name'=>'R3a <svg onload="window.r3a=true">','last_name'=>'Fixture','password'=>$msPassword,
        'repeat_password'=>$msPassword,'member_type'=>$type,'denomination'=>$denomination];
}
function msConsent(): array { return ['consenso_statuto'=>'1','consenso_privacy'=>'1','consenso_condizioni'=>'1','consenso_condizioni_pos'=>'1','consenso_marketing'=>'0']; }
function msSubmit(array $fields,?array $consent = null,array $extras = []): array {
    $page = msHttp('/site/signup');
    if ($page['status'] !== 200) throw new RuntimeException('Member signup form unavailable.');
    return msHttp('/site/signup',['_csrf'=>msCsrf($page),'SignupForm'=>$fields,'UserConsensus'=>$consent ?? msConsent()]+$extras);
}
function msCounts(): array {
    global $db; $counts = [];
    foreach (['users','member_profiles','user_consensus','merchants','stores','pos_operators','pos_store_operators','pos_terminals','membership_payments'] as $table) $counts[$table] = (int)$db->createCommand('SELECT COUNT(*) FROM ' . $table)->queryScalar();
    return $counts;
}
function msMail(): array { global $root; $files = glob($root . '/runtime/mail/*.eml') ?: []; sort($files); return $files; }
function msSafe(string $text): bool { return !preg_match('/SQLSTATE|IntegrityException|yii\\\\db|INSERT INTO|Unknown column|r3a fixture (?:profile|consent) failure|uq-users-email-normalized/i',$text); }
function msRejected(array $fields,string $label,?array $consent = null): void {
    $before = msCounts(); $mail = msMail(); $response = msSubmit($fields,$consent);
    msCheck($response['status'] === 200 && msSafe($response['body']),$label . ': validation response contains no SQL details');
    msCheck(msCounts() === $before && msMail() === $mail,$label . ': no account/profile/consent/operational rows or mail');
}
function msMember(string $email,string $type,?string $denomination): Users {
    global $db,$msPassword;
    $user = Users::findByEmail($email);
    msCheck($user !== null && (int)$user->is_active === 0 && (int)$user->is_merchant === 0
        && (int)$user->privilege->level === 0 && $user->oauth_provider === 'SELF' && $user->validatePassword($msPassword),'Public ' . $type . ' account is base, pending, nonmerchant with valid password');
    $profiles = MemberProfiles::find()->where(['user_id'=>$user->id])->asArray()->all();
    msCheck(count($profiles) === 1 && (int)$profiles[0]['is_corporate'] === ($type === 'pg' ? 1 : 0) && $profiles[0]['denomination'] === $denomination,'Exactly one server-owned profile has requested PF/PG type and canonical denomination');
    $profile = $profiles[0];
    msCheck(($profile['legacy_user_id'] ?? null) === null && ($profile['legacy_user_type'] ?? null) === null
        && (int)($profile['user_imported'] ?? 0) === 0 && ($profile['association_role_id'] ?? null) === null
        && ($profile['vat'] ?? null) === null,'Posted legacy, role and extra profile fields never become membership evidence');
    $consent = $db->createCommand('SELECT * FROM user_consensus WHERE user_id=:u',[':u'=>$user->id])->queryAll();
    msCheck(count($consent) === 1 && (int)$consent[0]['consenso_condizioni_pos'] === 0
        && (int)$consent[0]['consenso_statuto'] === 1 && (int)$consent[0]['consenso_privacy'] === 1 && (int)$consent[0]['consenso_condizioni'] === 1,'Consent belongs to new member and POS consent stays zero despite crafted POST');
    return $user;
}
function msActivation(array $beforeMail,Users $user): string {
    $files = array_values(array_diff(msMail(),$beforeMail)); $message = '';
    msCheck(count($files) === 2,'Successful committed signup sends member/admin file mail only');
    foreach ($files as $file) {
        $raw = file_get_contents($file);
        msCheck(!str_contains($raw,'attacker.example.invalid'),'Signup file mail ignores hostile Host header');
        if (preg_match('/^To:.*' . preg_quote($user->email,'/') . '/mi',$raw)) $message = quoted_printable_decode($raw);
    }
    if (!preg_match('~https://napay\.example\.invalid/site/activate\?[^"\s<>]+~',$message,$match)) throw new RuntimeException('Member activation link missing from file mail.');
    $url = html_entity_decode($match[0],ENT_QUOTES | ENT_HTML5,'UTF-8');
    parse_str(parse_url($url,PHP_URL_QUERY),$query);
    msCheck(array_keys($query) === ['id','sign'],'Activation link contains no member type, denomination or privilege choices');
    return parse_url($url,PHP_URL_PATH) . '?' . parse_url($url,PHP_URL_QUERY);
}
// SQL routing applies only to dedicated empty clones used by the first-account/catalog probe.
// It changes no query result: real SignupForm and ActiveRecord execute against real private tables.
class MsSignupProbeCommand extends yii\db\Command {
    public function setSql($sql) {
        parent::setSql($sql);
        return parent::setSql(strtr($this->getSql(),['`users`'=>'`r3a_signup_users`','`member_profiles`'=>'`r3a_signup_member_profiles`',
            '`privileges`'=>'`r3a_signup_privileges`','`logs`'=>'`r3a_signup_logs`']));
    }
}
try {
    $page = msHttp('/site/signup'); $dom = msDom($page);
    msCheck($page['status'] === 200 && $dom->query('//select[@name="SignupForm[member_type]"]/option[@value="pf"]')->length === 1
        && $dom->query('//select[@name="SignupForm[member_type]"]/option[@value="pg"]')->length === 1,'Public signup exposes explicit PF and PG options');
    msCheck($dom->query('//*[@name="SignupForm[denomination]"]')->length === 1
        && $dom->query('//*[@name="SignupForm[is_merchant]"]')->length === 0
        && $dom->query('//input[@name="UserConsensus[consenso_condizioni_pos]"][@type!="hidden"]')->length === 0,'Form provides PG denomination without merchant choice or POS consent UI');
    $before = msCounts();
    msCheck(msHttp('/site/signup',['SignupForm'=>msFields('r3a-csrf@example.invalid'),'UserConsensus'=>msConsent()])['status'] === 400 && msCounts() === $before,'Public signup requires CSRF before all writes');
    $operational = array_intersect_key($before,array_flip(['merchants','stores','pos_operators','pos_store_operators','pos_terminals','membership_payments']));
    foreach (['pf','pg'] as $type) {
        $email = 'r3a-member-' . $type . '@example.invalid'; $mail = msMail();
        $denomination = $type === 'pg' ? 'R3a <societa & fixture>' : null;
        $fields = msFields(strtoupper($email),$type,$type === 'pg' ? '  ' . $denomination . '  ' : 'Ignored PF denomination');
        $fields += ['is_merchant'=>'1','privilege_id'=>'1','is_active'=>'1','user_id'=>'1','id'=>'1','is_corporate'=>$type === 'pf' ? 1 : 0,
            'legacy_user_id'=>999999,'legacy_user_type'=>1,'user_imported'=>1,'association_role_id'=>1,'vat'=>'ATTACKER'];
        $consent = msConsent()+['user_id'=>'1'];
        $response = msSubmit($fields,$consent,['MemberProfiles'=>['user_id'=>1,'is_corporate'=>$type === 'pf' ? 1 : 0,'denomination'=>'Attacker','legacy_user_id'=>999999,'association_role_id'=>1,'vat'=>'ATTACKER'],
            'Users'=>['id'=>1,'privilege_id'=>1,'is_active'=>1,'is_merchant'=>1]]);
        msCheck($response['status'] === 302 && msSafe($response['body']),'Valid crafted ' . $type . ' signup succeeds without executing JavaScript');
        $user = msMember($email,$type,$denomination); $path = msActivation($mail,$user);
        $profileBefore = MemberProfiles::find()->where(['user_id'=>$user->id])->asArray()->one();
        $identity = [$user->password,$user->oauth_uid,$user->privilege_id,$user->is_merchant];
        $pending = $user->getAttributes();
        $confirm = msHttp($path);
        msCheck($confirm['status'] === 200 && Users::findOne($user->id)->getAttributes() === $pending,'Activation scanner GET does not change pending member');
        $activated = msHttp($path,['_csrf'=>msCsrf($confirm),'SignupForm'=>['member_type'=>$type === 'pf' ? 'pg' : 'pf','denomination'=>'Forged'],
            'MemberProfiles'=>['is_corporate'=>$type === 'pf' ? 1 : 0],'privilege_id'=>1,'is_merchant'=>1]);
        $active = Users::findOne($user->id);
        msCheck($activated['status'] === 302 && (int)$active->is_active === 1
            && [$active->password,$active->oauth_uid,$active->privilege_id,$active->is_merchant] === $identity,'Activation changes status only, never grants merchant/admin or rewrites identity');
        msCheck($profileBefore === MemberProfiles::find()->where(['user_id'=>$user->id])->asArray()->one(),'Crafted activation POST cannot convert PF/PG profile');
        $html = $app->mailer->compose('newAccountUser',['user'=>$active])->getSymfonyEmail()->getHtmlBody();
        msCheck(!str_contains($html,'<svg') && str_contains($html,'&lt;svg'),'Member mail HTML encodes user-controlled contact name');
    }
    $cases = [['member_type'=>null],['member_type'=>''],['member_type'=>'PF'],['member_type'=>'operator'],['member_type'=>['pf']],['member_type'=>['x'=>'pg']],
        ['member_type'=>'pg','denomination'=>''],['member_type'=>'pg','denomination'=>'   '],['member_type'=>'pg','denomination'=>"\u{00a0}\u{2003}"],
        ['member_type'=>'pg','denomination'=>['bad']],['member_type'=>'pg','denomination'=>str_repeat('x',251)],
        ['first_name'=>[]],['last_name'=>''],['password'=>'','repeat_password'=>''],['repeat_password'=>'different'],['email'=>['bad']]];
    foreach ($cases as $i=>$bad) msRejected(array_replace(msFields('r3a-invalid-' . $i . '@example.invalid'),$bad),'Invalid signup case ' . $i);
    msRejected(array_replace(msFields('r3a-missing-type@example.invalid'),['member_type'=>null,'is_merchant'=>1]),'Legacy merchant choice cannot replace explicit PF/PG type');
    msRejected(msFields('R3A-MEMBER-PF@EXAMPLE.INVALID'),'Normalized existing email cannot register another member');
    foreach (['consenso_statuto','consenso_privacy','consenso_condizioni'] as $i=>$field) {
        $consent = msConsent(); unset($consent[$field]);
        msRejected(msFields('r3a-consent-' . $i . '@example.invalid'), 'Missing required consent ' . $field,$consent);
    }
    foreach (['consenso_statuto','consenso_privacy','consenso_condizioni','consenso_marketing'] as $i=>$field) {
        foreach ([['invalid'], '2'] as $j=>$value) {
            $consent = array_replace(msConsent(),[$field=>$value]);
            msRejected(msFields('r3a-bad-consent-' . $i . '-' . $j . '@example.invalid'),'Malformed consent ' . $field . ' case ' . $j,$consent);
        }
    }
    $long = str_repeat("\u{754c}",250); $mail = msMail();
    msCheck(msSubmit(msFields('r3a-max-pg@example.invalid','pg',$long))['status'] === 302,'PG accepts exactly 250 multibyte characters server-side');
    msMember('r3a-max-pg@example.invalid','pg',$long);
    msCheck(count(array_diff(msMail(),$mail)) === 2,'Boundary-length PG also sends only post-commit member/admin mail');
    // A temporary stricter CHECK injects real write failures without SUPER or trigger privileges.
    $lastConsent = (int)$db->createCommand('SELECT COALESCE(MAX(timestamp_statuto),0) FROM user_consensus')->queryScalar();
    $deadline = microtime(true) + 3;
    while (time() <= $lastConsent && microtime(true) < $deadline) usleep(20000);
    if (time() <= $lastConsent) throw new RuntimeException('Consent failure fixture requires past timestamps.');
    foreach ([['member_profiles','profile',"denomination IS NULL OR denomination <> 'Rollback PG'",msConsent()],
        ['user_consensus','consent','consenso_marketing <> 1 OR timestamp_statuto <= ' . $lastConsent,array_replace(msConsent(),['consenso_marketing'=>'1'])]] as [$table,$label,$expression,$consent]) {
        $constraint = 'r3a_signup_fail_' . $label;
        msCheck((int)$db->createCommand('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME=:c',[':c'=>$constraint])->queryScalar() === 0,'Failure-injection constraint name is unused: ' . $label);
        $db->createCommand('ALTER TABLE `' . $table . '` ADD CONSTRAINT `' . $constraint . '` CHECK (' . $expression . ')')->execute();
        try { msRejected(msFields('r3a-db-fail-' . $label . '@example.invalid','pg','Rollback PG'),'Injected ' . $label . ' save failure',$consent); }
        finally { $db->createCommand('ALTER TABLE `' . $table . '` DROP CHECK `' . $constraint . '`')->execute(); }
    }
    $before = msCounts(); $mail = msMail(); $outer = $db->beginTransaction();
    try {
        $form = new SignupForm(); $form->setAttributes(msFields('r3a-outer@example.invalid','pg','Outer PG'));
        $created = $form->signup();
        msCheck($created instanceof Users && MemberProfiles::find()->where(['user_id'=>$created->id])->exists(),'Real SignupForm creates user/profile together inside caller transaction');
        msCheck($outer->isActive && $db->getTransaction()->getLevel() === 1,'SignupForm savepoint preserves ownership of caller transaction');
    } finally { if ($outer->isActive) $outer->rollBack(); }
    msCheck(msCounts() === $before && msMail() === $mail,'Caller rollback removes user/profile/audit and sends no premature mail');
    $before = msCounts();
    $race = new class extends SignupForm {
        public $winner;
        public function validate($attributeNames = null,$clearErrors = true) {
            $valid = parent::validate($attributeNames,$clearErrors);
            if ($valid && $this->winner !== null) { $callback = $this->winner; $this->winner = null; $callback(); }
            return $valid;
        }
    };
    $race->setAttributes(msFields('r3a-race@example.invalid','pg','Losing PG'));
    $race->winner = static function (): void {
        $winner = new SignupForm(); $winner->setAttributes(msFields('R3A-RACE@EXAMPLE.INVALID','pf'));
        if (!$winner->signup()) throw new RuntimeException('R3a race winner unavailable.');
    };
    msCheck($race->signup() === false && $race->hasErrors('email') && msSafe(json_encode($race->getErrors())),'R2 race after validation rejects loser with safe field error');
    $after = msCounts();
    msCheck($after['users'] === $before['users']+1 && $after['member_profiles'] === $before['member_profiles']+1
        && $after['user_consensus'] === $before['user_consensus'],'R2 race persists one winner profile, never orphan loser or phantom consent');
    $winner = Users::findByEmail('r3a-race@example.invalid');
    msCheck((int)MemberProfiles::find()->where(['user_id'=>$winner->id])->one()->is_corporate === 0,'R2 loser cannot overwrite winner PF with requested PG');

    // No ID1 deletion/renumber/FK bypass: private empty schema clones, synthetic catalog only.
    $map = ['users'=>'r3a_signup_users','member_profiles'=>'r3a_signup_member_profiles','privileges'=>'r3a_signup_privileges','logs'=>'r3a_signup_logs'];
    $tablesCreated = []; $realBefore = msCounts();
    $legacyOne = hash('sha256',serialize($db->createCommand('SELECT * FROM users WHERE id=1')->queryOne()));
    $probe = Yii::createObject(require $root . '/config/db.php'); $probe->enableLogging = false; $probe->enableProfiling = false;
    try {
        foreach ($map as $source=>$target) {
            if ($db->schema->getTableSchema($target,true) !== null) throw new RuntimeException('Reserved member signup probe table already exists.');
            $db->createCommand('CREATE TABLE `' . $target . '` LIKE `' . $source . '`')->execute(); $tablesCreated[] = $target;
        }
        foreach ([101=>0,102=>50,103=>30] as $id=>$level) $db->createCommand()->insert('r3a_signup_privileges',['id'=>$id,'description'=>'R3a synthetic role','level'=>$level,'codice_ruolo'=>'R3A_' . $level])->execute();
        $probe->commandClass = MsSignupProbeCommand::class; $app->set('db',$probe);
        msCheck(Users::findOne(1) === null && Users::find()->count() == 0,'Real AR queries see empty private users probe with no ID1');
        $form = new SignupForm(); $form->setAttributes(msFields('r3a-no-first-admin@example.invalid'));
        $created = $form->signup();
        msCheck($created instanceof Users && (int)$created->privilege_id === 101 && (int)$created->privilege->level === 0
            && (int)$created->is_active === 0 && (int)$created->is_merchant === 0,'No ID1 still creates pending base account using catalog ID101, never first administrator');
        msCheck(MemberProfiles::find()->where(['user_id'=>$created->id])->count() == 1,'No-ID1 signup also creates exactly one private member profile');
        $db->createCommand()->delete('r3a_signup_privileges',['id'=>101])->execute();
        foreach (['missing','ambiguous'] as $catalog) {
            if ($catalog === 'ambiguous') foreach ([101,104] as $id) $db->createCommand()->insert('r3a_signup_privileges',['id'=>$id,'description'=>'R3a duplicate base','level'=>0,'codice_ruolo'=>'R3A_BASE_' . $id])->execute();
            $beforeUsers = Users::find()->count(); $beforeProfiles = MemberProfiles::find()->count();
            $form = new SignupForm(); $form->setAttributes(msFields('r3a-catalog-' . $catalog . '@example.invalid'));
            msCheck($form->signup() === false && msSafe(json_encode($form->getErrors())),'Base catalog ' . $catalog . ' produces safe form failure');
            msCheck(Users::find()->count() === $beforeUsers && MemberProfiles::find()->count() === $beforeProfiles,'Base catalog ' . $catalog . ' never creates users or profiles');
        }
    } finally {
        $app->set('db',$db); $probe->close();
        foreach (array_reverse($tablesCreated) as $table) $db->createCommand('DROP TABLE `' . $table . '`')->execute();
    }
    msCheck(msCounts() === $realBefore && hash('sha256',serialize($db->createCommand('SELECT * FROM users WHERE id=1')->queryOne())) === $legacyOne,'First-account/catalog probes leave real copied rows and ID1 untouched');
    msCheck(array_intersect_key(msCounts(),$operational) === $operational,'All public and direct signups create no merchant/store/operator/grant/terminal/payment');
    msCheck($db->getTransaction() === null,'Member signup suite leaves no transaction open');
    $logs = json_encode($db->createCommand('SELECT description FROM logs')->queryColumn());
    foreach (glob($root . '/runtime/logs/*.log') ?: [] as $file) $logs .= file_get_contents($file);
    msCheck(!str_contains($logs,$msPassword),'Signup failures never log fixture password');
    echo "PASS {$msChecks} R3a member signup HTTP/DB/mail checks\n";
} finally {
    if (is_file($msJar)) unlink($msJar);
    if ($app->session->isActive) $app->session->close();
}
