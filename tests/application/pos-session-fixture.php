<?php

/** Explicit POS identity for isolated API tests; never retrofit real member accounts. */
function napayTestPosSessionFixture(): array
{
    $db = Yii::$app->db;
    if (PHP_SAPI !== 'cli' || getenv('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture'
        || $db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') {
        throw new RuntimeException('Disposable POS fixture required.');
    }
    $user = (int) $db->createCommand("SELECT id FROM users WHERE username='runtime-user'")->queryScalar();
    $merchant = $db->createCommand("SELECT m.id,m.user_id FROM merchants m JOIN users u ON u.id=m.user_id WHERE u.username='runtime-merchant'")->queryOne();
    if (!$user || !$merchant) throw new RuntimeException('Missing POS fixture identities.');
    foreach (['member_profiles', 'membership_payments', 'merchants'] as $table) {
        if ((int) $db->createCommand('SELECT COUNT(*) FROM ' . $table . ' WHERE user_id=:u', [':u'=>$user])->queryScalar() !== 0) {
            throw new RuntimeException('POS fixture must not reuse member or owner account.');
        }
    }
    $member = $db->createCommand('SELECT is_corporate FROM member_profiles WHERE user_id=:u', [':u'=>$merchant['user_id']])->queryScalar();
    if ($member === false) {
        $db->createCommand()->insert('member_profiles', ['user_id'=>$merchant['user_id'], 'is_corporate'=>1, 'denomination'=>'POS fixture PG'])->execute();
    } elseif ((int) $member !== 1) throw new RuntimeException('POS fixture owner must be PG.');
    $operator = app\models\PosOperators::findOne($user);
    if ($operator === null) {
        $operator = new app\models\PosOperators(['user_id'=>$user, 'merchant_id'=>(int) $merchant['id']]);
    } elseif ((int) $operator->merchant_id !== (int) $merchant['id']) throw new RuntimeException('Unexpected fixture operator ownership.');
    $operator->is_enabled = 1;
    if (!$operator->save()) throw new RuntimeException('POS operator fixture not saved.');
    return ['user_id'=>$user, 'merchant_id'=>(int) $merchant['id']];
}
