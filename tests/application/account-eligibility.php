<?php
if (PHP_SAPI !== 'cli') exit(1);
$root=dirname(__DIR__,2); require $root.'/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER',false);
require $root.'/vendor/autoload.php'; require $root.'/vendor/yiisoft/yii2/Yii.php';
$app=new yii\console\Application(require $root.'/config/console.php'); $db=$app->db;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);
use app\components\identity\AccountEligibility as Policy;
$checks=0;
function policyCheck($ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException($label);++$checks;echo 'PASS: '.$label."\n";}
$ids=array_column($db->createCommand("SELECT id,username FROM users WHERE username LIKE 'runtime-%'")->queryAll(),'id','username');
$user=(int)$ids['runtime-user'];$owner=(int)$ids['runtime-merchant'];$operator=(int)$ids['runtime-blocked'];$pg=(int)$ids['runtime-disabled'];
$merchant=(int)$db->createCommand("SELECT id FROM merchants WHERE description='Runtime company merchant'")->queryScalar();
policyCheck(Policy::kind($user)===Policy::UNCLASSIFIED,'Level base alone is not member or operator');
policyCheck(!Policy::isOperatorAccount($user)&&!Policy::canActivateMerchant($user),'Unclassified account has no operational capability');
policyCheck(Policy::kind(2147483647)===Policy::UNCLASSIFIED&&!Policy::qualifiedMerchant(2147483647),'Missing identities fail closed');
$db->createCommand()->insert('member_profiles',['user_id'=>$user,'is_corporate'=>0])->execute();
policyCheck(Policy::kind($user)===Policy::MEMBER_PF&&!Policy::canActivateMerchant($user),'PF member cannot activate merchant');
$db->createCommand()->insert('member_profiles',['user_id'=>(int)$ids['runtime-other'],'is_corporate'=>0])->execute();
policyCheck(Policy::kind((int)$ids['runtime-other'])===Policy::CONFLICT,'PF owner of legacy merchant requires reconciliation');
policyCheck(Policy::kind($owner)===Policy::CONFLICT,'Merchant row without PG evidence is not qualified');
$db->createCommand()->insert('member_profiles',['user_id'=>$owner,'is_corporate'=>1,'denomination'=>'Fixture PG'])->execute();
policyCheck(Policy::kind($owner)===Policy::MEMBER_PG&&Policy::qualifiedMerchant($merchant),'PG merchant positively classified');
policyCheck(Policy::merchantForManager($owner)===$merchant,'Only dedicated manager gets its one merchant');
policyCheck(!Policy::isOperatorAccount($owner)&&!Policy::canActivateMerchant($owner),'Manager is not operator and cannot create second merchant');
$db->createCommand()->update('users',['is_active'=>0],['id'=>$owner])->execute();
policyCheck(Policy::merchantForManager($owner)===null,'Inactive manager cannot operate');
$db->createCommand()->update('users',['is_active'=>1],['id'=>$owner])->execute();
$db->createCommand()->insert('member_profiles',['user_id'=>$pg,'is_corporate'=>1,'denomination'=>'New PG'])->execute();
policyCheck(!Policy::canActivateMerchant($pg),'Inactive PG cannot self-activate');
$db->createCommand()->update('users',['is_active'=>1],['id'=>$pg])->execute();
policyCheck(Policy::canActivateMerchant($pg),'Active PG without merchant eligible for self-activation');
foreach ([null,'   '] as $denomination) {
    $db->createCommand()->update('member_profiles',['denomination'=>$denomination],['user_id'=>$pg])->execute();
    policyCheck(Policy::kind($pg)===Policy::CONFLICT&&!Policy::canActivateMerchant($pg),'Incomplete PG never eligible for merchant activation');
}
$db->createCommand()->update('member_profiles',['denomination'=>'New PG'],['user_id'=>$pg])->execute();
$db->createCommand()->update('member_profiles',['is_corporate'=>2],['user_id'=>$pg])->execute();
policyCheck(Policy::kind($pg)===Policy::CONFLICT&&!Policy::canActivateMerchant($pg),'Invalid association type never becomes PG');
$db->createCommand()->update('member_profiles',['is_corporate'=>1],['user_id'=>$pg])->execute();
$now=time();$profile=['user_id'=>$operator,'merchant_id'=>$merchant,'created_at'=>$now,'updated_at'=>$now];
$db->createCommand()->insert('pos_operators',$profile)->execute();
policyCheck(Policy::kind($operator)===Policy::OPERATOR&&!Policy::isOperatorAccount($operator),'Operator identity and account activity are separate');
$db->createCommand()->update('users',['is_active'=>1],['id'=>$operator])->execute();
policyCheck(Policy::isOperatorAccount($operator),'Active base operator recognized without granting store access');
$db->createCommand()->insert('member_profiles',['user_id'=>$operator,'is_corporate'=>0])->execute();
policyCheck(Policy::kind($operator)===Policy::CONFLICT&&!Policy::isOperatorAccount($operator),'Same account cannot be both member and operator');
$copy=$db->createCommand('SELECT * FROM merchants WHERE id=:id',[':id'=>$merchant])->queryOne();unset($copy['id']);$copy['description']='Duplicate fixture merchant';
$db->createCommand()->insert('merchants',$copy)->execute();$duplicateMerchant=(int)$db->lastInsertID;
policyCheck(Policy::kind($owner)===Policy::CONFLICT&&Policy::merchantForManager($owner)===null&&!Policy::qualifiedMerchant($merchant),'Multiple merchants rejected, never first selected');
$db->createCommand()->delete('merchants',['id'=>$duplicateMerchant])->execute();
$duplicate=$db->createCommand('SELECT * FROM users WHERE id=:id',[':id'=>$owner])->queryOne();unset($duplicate['id']);$duplicate['username']='policy-duplicate';$duplicate['email']=' '.strtoupper($duplicate['email']).' ';
$db->createCommand()->insert('users',$duplicate)->execute();$duplicateUser=(int)$db->lastInsertID;
policyCheck(Policy::kind($owner)===Policy::CONFLICT&&Policy::merchantForManager($owner)===null,'Normalized ambiguous email rejected');
$db->createCommand()->delete('users',['id'=>$duplicateUser])->execute();
policyCheck(Policy::merchantForManager($owner)===$merchant,'Conflict removal restores consistent classification without writes by policy');
policyCheck(Policy::kind((int)$ids['runtime-admin'])===Policy::PLATFORM_ADMIN,'Admin without membership remains separate, not operator');
$before=[];foreach(['users','merchants','member_profiles','pos_operators'] as $t)$before[$t]=$db->createCommand('SELECT COUNT(*) FROM '.$t)->queryScalar();
Policy::kind($owner);Policy::merchantForManager($owner);Policy::qualifiedMerchant($merchant);Policy::canActivateMerchant($pg);Policy::isOperatorAccount($operator);
$after=[];foreach(array_keys($before) as $t)$after[$t]=$db->createCommand('SELECT COUNT(*) FROM '.$t)->queryScalar();
policyCheck($before===$after,'Policy calls do not create records');
$db->createCommand()->renameTable('member_profiles','member_profiles_policy_failure')->execute();
$failed=false;
try {Policy::kind($owner);}catch(yii\db\Exception $e){$failed=true;}
finally {$db->createCommand()->renameTable('member_profiles_policy_failure','member_profiles')->execute();}
policyCheck($failed,'Missing identity schema propagates failure, never permissive fallback');
echo "PASS $checks shared account-eligibility checks\n";
