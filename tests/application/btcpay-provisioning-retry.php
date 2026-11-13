<?php
// SIP-022 B1 §4: politica dei ritentativi. Test puri: nessun DB, file, rete o ambiente.
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__, 2) . '/components/btcpay/provisioning/RetryPolicy.php';

use app\components\btcpay\provisioning\RetryPolicy;

$checks = 0;
function check($condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    ++$checks; echo 'PASS: ' . $label . "\n";
}
function refused(callable $fn, string $class, string $code, string $label): void {
    $ok = false;
    try { $fn(); } catch (Throwable $e) { $ok = $e instanceof $class && $e->getMessage() === $code; }
    check($ok, $label);
}
$now = 1_800_000_000;
$none = new RetryPolicy(fn(int $max): int => 0);
$full = new RetryPolicy(fn(int $max): int => $max);
$at = static fn(RetryPolicy $policy, ?string $header, int $attempt = 1): array => $policy->decide($header, $attempt, $now);
$backoffOnly = static fn(int $delay): array => ['next_attempt_at' => $now + $delay, 'deferred' => false, 'server' => false];

// --- Backoff: solo senza Retry-After valido ---
foreach ([1 => 30, 2 => 60, 3 => 120, 4 => 240, 5 => 480, 6 => 960, 7 => 1920, 8 => 3600, 9 => 3600, 1000 => 3600] as $attempt => $delay) {
    check($at($none, null, $attempt) === $backoffOnly($delay), "Backoff attempt $attempt = {$delay}s");
}
foreach ([1 => 36, 6 => 1152, 7 => 2304, 8 => 3600, 1000 => 3600] as $attempt => $delay) {
    check($at($full, null, $attempt) === $backoffOnly($delay), "Maximum jitter attempt $attempt = {$delay}s, never over one hour");
}
$default = new RetryPolicy();
$seen = [];
for ($i = 0; $i < 300; ++$i) $seen[$default->decide(null, 1, $now)['next_attempt_at'] - $now] = true;
check(min(array_keys($seen)) >= 30 && max(array_keys($seen)) <= 36 && count($seen) > 1, 'Default jitter random within 0..delay/5');
foreach ([-1, 7, '3', 2.0, null] as $bad) {
    refused(fn() => (new RetryPolicy(fn(int $max) => $bad))->decide(null, 1, $now), UnexpectedValueException::class, 'invalid_jitter', 'Out-of-range jitter refused: ' . var_export($bad, true));
}
refused(fn() => $none->decide(null, 0, $now), InvalidArgumentException::class, 'invalid_attempt', 'Attempt 0 refused');
refused(fn() => $none->decide(null, 1001, $now), InvalidArgumentException::class, 'invalid_attempt', 'Attempt over the queue limit refused');
refused(fn() => $none->decide(null, 1, -1), InvalidArgumentException::class, 'invalid_now', 'Negative clock refused');

// --- Retry-After in secondi ---
check($at($none, '120') === ['next_attempt_at' => $now + 120, 'deferred' => false, 'server' => true], 'Server seconds later than backoff win');
check($at($none, '10') === ['next_attempt_at' => $now + 30, 'deferred' => false, 'server' => true], 'Backoff later than server seconds wins');
check($at($none, '0')['next_attempt_at'] === $now + 30 && $at($none, '0')['server'], 'Zero seconds valid, backoff floor kept');
check($at($none, "  120\t")['next_attempt_at'] === $now + 120, 'Optional whitespace around the value accepted');
check($at($none, '000120')['next_attempt_at'] === $now + 120, 'Leading zeros accepted');
check($at($none, '86400') === ['next_attempt_at' => $now + 86400, 'deferred' => false, 'server' => true], 'Exactly 24 hours still automatic');
check($at($none, '86401') === ['next_attempt_at' => $now + 86401, 'deferred' => true, 'server' => true], 'Over 24 hours deferred to admin');
foreach (['9223372036854775807', '9223372036854775808', str_repeat('9', 40), '0000' . str_repeat('9', 25)] as $huge) {
    check($at($none, $huge) === ['next_attempt_at' => PHP_INT_MAX, 'deferred' => true, 'server' => true], 'Huge valid seconds never fall back to backoff: ' . strlen($huge) . ' digits');
}
check($at($none, (string) (PHP_INT_MAX - $now))['next_attempt_at'] === PHP_INT_MAX && $at($none, (string) (PHP_INT_MAX - $now - 1))['next_attempt_at'] === PHP_INT_MAX - 1, 'Addition at the integer limit without overflow');
foreach (['', ' ', '-5', '+5', '1.5', '1e3', '12 s', '0x10', "120\n", "\u{0661}\u{0662}", '120,130', 'soon'] as $bad) {
    check($at($none, $bad, 3) === $backoffOnly(120), 'Unparsable value uses backoff: ' . json_encode($bad));
}

// --- Retry-After come IMF-fixdate ---
$date = static fn(int $time): string => gmdate('D, d M Y H:i:s \G\M\T', $time);
check($at($none, $date($now + 600)) === ['next_attempt_at' => $now + 600, 'deferred' => false, 'server' => true], 'IMF-fixdate in the future honoured');
check($at($none, $date($now + 90000)) === ['next_attempt_at' => $now + 90000, 'deferred' => true, 'server' => true], 'IMF-fixdate beyond 24 hours deferred');
check($at($none, $date($now - 600)) === ['next_attempt_at' => $now + 30, 'deferred' => false, 'server' => true], 'Past IMF-fixdate: never before backoff');
check($at($none, 'Fri, 31 Dec 9999 23:59:59 GMT')['deferred'], 'Far-future date valid and deferred');
$leap = (new RetryPolicy(fn(int $max): int => 0))->decide('Sat, 31 Dec 2016 23:59:60 GMT', 1, 1483228000);
check($leap['server'] && $leap['next_attempt_at'] === 1483228800, 'Leap second accepted on its own weekday');
$valid = $date($now + 600);
foreach ([
    'Wrong weekday' => str_replace(substr($valid, 0, 3), substr($valid, 0, 3) === 'Mon' ? 'Tue' : 'Mon', $valid),
    'Lowercase GMT' => substr($valid, 0, -3) . 'gmt',
    'Other zone' => substr($valid, 0, -3) . 'UTC',
    'Numeric offset' => substr($valid, 0, -3) . '+0000',
    'Double space' => str_replace(', ', ',  ', $valid),
    'Missing comma' => str_replace(',', '', $valid),
    'Trailing text' => $valid . ' x',
    'RFC 850 (obsolete)' => 'Sunday, 06-Nov-94 08:49:37 GMT',
    'asctime (obsolete)' => 'Sun Nov  6 08:49:37 1994',
    'Single-digit day' => 'Sun, 6 Nov 1994 08:49:37 GMT',
    'Impossible date' => 'Mon, 31 Feb 2027 10:00:00 GMT',
    'Hour 24' => 'Mon, 15 Feb 2027 24:00:00 GMT',
    'Minute 60' => 'Mon, 15 Feb 2027 10:60:00 GMT',
    'Second 61' => 'Mon, 15 Feb 2027 10:00:61 GMT',
    'Lowercase month' => 'Mon, 15 feb 2027 10:00:00 GMT',
    'Year zero' => 'Sat, 01 Jan 0000 00:00:00 GMT',
] as $label => $bad) {
    check($at($none, $bad) === $backoffOnly(30), 'Invalid date uses backoff: ' . $label);
}

// --- Purezza ---
$source = file_get_contents(dirname(__DIR__, 2) . '/components/btcpay/provisioning/RetryPolicy.php');
check(preg_match('/curl|PDO|Yii|getenv|\$_(SERVER|ENV)|file_|fopen|time\(\)|microtime|date_default|error_log|echo /i', $source) === 0, 'No HTTP, DB, environment, clock or logging in the policy');
$methods = array_map(fn($m) => $m->name, (new ReflectionClass(RetryPolicy::class))->getMethods(ReflectionMethod::IS_PUBLIC));
sort($methods);
check($methods === ['__construct', 'decide'] && (new ReflectionClass(RetryPolicy::class))->isFinal(), 'Single public decision method');

echo "PASS $checks BTCPay retry policy checks\n";
