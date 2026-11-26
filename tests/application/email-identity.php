<?php
// R2: shared identity comparisons and safe signup collision handling, disposable DB only.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/config/env.php';
if (env('NAPAY_TEST_DB_CONFIRM') !== 'isolated-fixture' || env('DB_DATABASE') !== 'napay_test_runtime') exit(1);
define('YII_ENABLE_ERROR_HANDLER', false);
require $root . '/vendor/autoload.php';
require $root . '/vendor/yiisoft/yii2/Yii.php';
$app = new yii\web\Application(require $root . '/config/web.php');
$db = $app->db;
if ($db->createCommand('SELECT DATABASE()')->queryScalar() !== 'napay_test_runtime') exit(1);

use app\components\identity\EmailIdentity;
use app\models\Users;
use app\models\forms\SignupForm;

$checks = 0;
function emailCheck(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    ++$checks;
    echo 'PASS: ' . $label . "\n";
}
$source = Users::findByUsername('runtime-user');
$original = $source->email;
$created = [];
$copy = function (string $email) use ($db, $source, &$created): int {
    $row = $source->getAttributes(null, ['id', 'email_normalized']);
    $row['email'] = $email;
    $row['username'] = 'r2-email-' . bin2hex(random_bytes(8));
    $db->createCommand()->insert('users', $row)->execute();
    $created[] = (int) $db->lastInsertID;
    return (int) $db->lastInsertID;
};
$fields = ['email'=>'new-r2@example.invalid', 'first_name'=>'R2', 'last_name'=>'Fixture',
    'password'=>'r2-fixture-password', 'repeat_password'=>'r2-fixture-password', 'member_type'=>'pf'];
try {
    emailCheck(EmailIdentity::normalize('  USER@Example.Invalid  ') === 'user@example.invalid', 'Email normalization matches lowercase and SQL space trimming');
    emailCheck(EmailIdentity::normalize([]) === '' && Users::findByEmail([]) === null, 'Non-string email cannot select account');
    $db->createCommand()->update('users', ['email'=>'  UsEr@Example.Invalid  '], ['id'=>$source->id])->execute();
    emailCheck((int) Users::findByEmail('USER@example.invalid')?->id === (int) $source->id, 'Legacy case and surrounding spaces resolve same identity');
    emailCheck($db->createCommand('SELECT email FROM users WHERE id=:id', [':id'=>$source->id])->queryScalar() === '  UsEr@Example.Invalid  ', 'Lookup does not rewrite legacy identity');
    $form = new SignupForm(array_replace($fields, ['email'=>' USER@EXAMPLE.INVALID ']));
    emailCheck(!$form->validate() && $form->hasErrors('email'), 'Signup rejects normalized existing email before insert');
    $form = new SignupForm(array_replace($fields, ['email'=>' NEW-R2@EXAMPLE.INVALID ']));
    emailCheck($form->validate() && $form->email === 'new-r2@example.invalid', 'New signup validates and normalizes email');
    $plain = $copy('accent-r2@example.invalid');
    // Binary comparison must not inherit accent-insensitive users table collation.
    $accented = $copy("\xc3\xa1ccent-r2@example.invalid");
    emailCheck((int) Users::findByEmail('accent-r2@example.invalid')?->id === $plain, 'ASCII identity is not ambiguous with accented legacy address');
    emailCheck((int) Users::findByEmail("\xc3\xa1ccent-r2@example.invalid")?->id === $accented, 'Binary lookup selects exact accented legacy address');
    emailCheck((int) Users::findByEmail(" \xc3\x81CCENT-R2@EXAMPLE.INVALID ")?->id === $accented, 'Legacy Unicode case normalization uses same database mapping on both operands');
    $sharpS = $copy("\xc3\x9f-r2@example.invalid");
    emailCheck((int) Users::findByEmail("\xe1\xba\x9e-R2@EXAMPLE.INVALID")?->id === $sharpS, 'Explicit Unicode 9 collation precedes LOWER regardless of source or connection collation');
    $race = new class extends SignupForm {
        public $afterValidation;
        public function validate($attributeNames = null, $clearErrors = true) {
            $valid = parent::validate($attributeNames, $clearErrors);
            if ($valid && $this->afterValidation !== null) {
                $callback = $this->afterValidation;
                $this->afterValidation = null;
                $callback($this->email);
            }
            return $valid;
        }
    };
    $race->setAttributes($fields);
    $race->afterValidation = fn($email) => $copy(strtoupper($email));
    emailCheck($race->signup() === false && $race->hasErrors('email'), 'Signup race loses safely to DB unique constraint');
    emailCheck((int) Users::find()->where(EmailIdentity::condition($fields['email']))->count() === 1, 'Signup race leaves exactly one account');
    $errors = json_encode($race->getErrors());
    emailCheck(!str_contains($errors, 'SQL') && !str_contains($errors, '1062') && !str_contains($errors, 'uq-users'), 'Signup collision exposes no database details');
    require_once __DIR__ . '/email-legacy-fixture.php';
    napayTestLegacyEmailCollision(Users::findOne($source->id), function () {
        emailCheck(Users::findByEmail('user@example.invalid') === null, 'Damaged legacy schema still rejects ambiguous ownership');
    });
} finally {
    $db->createCommand()->update('users', ['email'=>$original], ['id'=>$source->id])->execute();
    foreach ($created as $id) $db->createCommand()->delete('users', ['id'=>$id])->execute();
}
echo "PASS $checks email identity checks\n";
