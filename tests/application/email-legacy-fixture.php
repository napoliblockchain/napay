<?php

/** Corrupt only a disposable test DB to retain pre-upgrade ambiguity regressions. */
function napayTestLegacyEmailCollision(\app\models\Users $source, callable $check): void
{
    $db = Yii::$app->db;
    if (PHP_SAPI !== 'cli' || getenv('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture'
        || $db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') {
        throw new RuntimeException('Disposable email fixture required.');
    }
    $db->createCommand('ALTER TABLE users DROP INDEX `uq-users-email-normalized`')->execute();
    $duplicate = new \app\models\Users();
    try {
        $duplicate->setAttributes($source->getAttributes(null, ['id', 'email_normalized']), false);
        $duplicate->username = 'email-collision-fixture-' . bin2hex(random_bytes(8));
        $duplicate->email = ' ' . strtoupper($source->email) . ' ';
        if (!$duplicate->save(false)) throw new RuntimeException('Email fixture not saved.');
        $check($duplicate);
    } finally {
        if (!$duplicate->isNewRecord) $duplicate->delete();
        $db->createCommand('ALTER TABLE users ADD UNIQUE INDEX `uq-users-email-normalized` (email_normalized)')->execute();
    }
}
