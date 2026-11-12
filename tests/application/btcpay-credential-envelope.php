<?php
// SIP-022 B1a: busta credenziali BTCPay. Test puri: chiavi casuali in memoria, nessun DB, file, rete o ambiente.
// Nessun output con chiavi, password, buste complete o testo in chiaro: solo etichette.
if (PHP_SAPI !== 'cli') exit(1);
ini_set('zend.exception_ignore_args', '0');
require dirname(__DIR__, 2) . '/components/btcpay/provisioning/CredentialEnvelope.php';

use app\components\btcpay\provisioning\CredentialEnvelope as Envelope;

$checks = 0;
function check($condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    ++$checks; echo 'PASS: ' . $label . "\n";
}
/** L'operazione deve fallire con il codice atteso, senza eccezione precedente; restituisce l'eccezione. */
function refused(callable $fn, string $code, string $label): Throwable {
    try { $fn(); } catch (InvalidArgumentException | UnexpectedValueException $e) {
        check($e->getMessage() === $code && $e->getPrevious() === null, $label);
        return $e;
    }
    throw new RuntimeException('FAIL (accepted): ' . $label);
}

$kek1 = random_bytes(32); $kek2 = random_bytes(32);
$keyring = ['k1' => $kek1];
$context = ['account_id' => 7, 'merchant_id' => 3, 'instance' => hash('sha256', 'https://btcpay.local'), 'kind' => 'api_key', 'generation' => 1];
$secret = 'chiave-di-prova-' . bin2hex(random_bytes(16));

// --- round trip e forma della busta
foreach (Envelope::KINDS as $kind) {
    $ctx = ['kind' => $kind] + $context;
    $env = Envelope::encrypt($secret, $ctx, 'k1', $kek1);
    check(Envelope::decrypt($env, $ctx, $keyring) === $secret, "Round trip for $kind");
}
$env = Envelope::encrypt($secret, $context, 'k1', $kek1);
check(array_keys($env) === ['version', 'kek_version', 'secret_nonce', 'ciphertext', 'dek_nonce', 'wrapped_dek'] && $env['version'] === 1 && $env['kek_version'] === 'k1',
    'Envelope has exactly the six fields, version 1');
check(strlen(base64_decode($env['secret_nonce'], true)) === 24 && strlen(base64_decode($env['dek_nonce'], true)) === 24
    && strlen(base64_decode($env['wrapped_dek'], true)) === 48 && strlen(base64_decode($env['ciphertext'], true)) === strlen($secret) + 16,
    'Binary lengths: nonces 24, wrapped DEK 48, ciphertext = plaintext + 16');
$other = Envelope::encrypt($secret, $context, 'k1', $kek1);
check($other['secret_nonce'] !== $env['secret_nonce'] && $other['dek_nonce'] !== $env['dek_nonce']
    && $other['ciphertext'] !== $env['ciphertext'] && $other['wrapped_dek'] !== $env['wrapped_dek'], 'Same secret twice: independent nonces, DEK and ciphertexts');
check(Envelope::decrypt(Envelope::encrypt(str_repeat('x', 16384), $context, 'k1', $kek1), $context, $keyring) === str_repeat('x', 16384), 'Plaintext of exactly 16384 bytes accepted');

// --- dati associati: ogni campo del contesto e la versione KEK legano la busta
foreach (['account_id' => 8, 'merchant_id' => 4, 'instance' => hash('sha256', 'https://other'), 'kind' => 'onboarding_password', 'generation' => 2] as $field => $value) {
    refused(fn() => Envelope::decrypt($env, [$field => $value] + $context, $keyring), 'decryption_failed', "Context field $field bound to the envelope");
}
refused(fn() => Envelope::decrypt(['kek_version' => 'k2'] + $env, $context, ['k1' => $kek1, 'k2' => $kek1]), 'decryption_failed',
    'KEK version bound to the wrapping (same key under another version refused)');

// --- manomissioni
$flip = function (string $field) use ($env) {
    $bin = base64_decode($env[$field], true); $bin[0] = chr(ord($bin[0]) ^ 1);
    return [$field => base64_encode($bin)] + $env;
};
foreach (['ciphertext', 'secret_nonce', 'dek_nonce', 'wrapped_dek'] as $field) {
    refused(fn() => Envelope::decrypt($flip($field), $context, $keyring), 'decryption_failed', "Tampered $field refused");
}
refused(fn() => Envelope::decrypt($env, $context, ['k1' => $kek2]), 'decryption_failed', 'Wrong KEK refused');
refused(fn() => Envelope::decrypt($env, $context, ['k2' => $kek1]), 'unknown_kek_version', 'Missing KEK version refused, no fallback to another key');
refused(fn() => Envelope::decrypt($env, $context, []), 'unknown_kek_version', 'Empty keyring refused');
refused(fn() => Envelope::decrypt($env, $context, ['k1' => substr($kek1, 0, 31)]), 'invalid_key', 'Short KEK in keyring refused');

// --- formato della busta
$bad = [
    'missing field' => array_diff_key($env, ['dek_nonce' => 1]),
    'extra field' => $env + ['note' => 'x'],
    'string version' => ['version' => '1'] + $env,
    'version 2' => ['version' => 2] + $env,
    'invalid kek_version' => ['kek_version' => 'k 1'] + $env,
    'long kek_version' => ['kek_version' => str_repeat('a', 65)] + $env,
    'base64 without padding' => ['secret_nonce' => rtrim($env['secret_nonce'], '=') . (str_ends_with($env['secret_nonce'], '=') ? '' : 'A')] + $env,
    'url-safe base64' => ['ciphertext' => strtr($env['ciphertext'], '+/', '-_') . (strpbrk($env['ciphertext'], '+/') === false ? '-' : '')] + $env,
    'whitespace in base64' => ['wrapped_dek' => ' ' . $env['wrapped_dek']] + $env,
    'non-string field' => ['ciphertext' => 12345] + $env,
    'short nonce' => ['secret_nonce' => base64_encode(random_bytes(23))] + $env,
    'long wrapped DEK' => ['wrapped_dek' => base64_encode(random_bytes(49))] + $env,
    'short ciphertext' => ['ciphertext' => base64_encode(random_bytes(16))] + $env,
    'huge ciphertext' => ['ciphertext' => base64_encode(random_bytes(16401))] + $env,
];
foreach ($bad as $label => $candidate) {
    $code = $label === 'invalid kek_version' || $label === 'long kek_version' ? 'invalid_kek_version' : 'invalid_envelope';
    refused(fn() => Envelope::decrypt($candidate, $context, $keyring), $code, "Malformed envelope refused: $label");
}

// --- contesto e input
$badContexts = [
    'missing field' => array_diff_key($context, ['generation' => 1]),
    'extra field' => $context + ['email' => 'x@example.invalid'],
    'numeric string id' => ['account_id' => '7'] + $context,
    'zero id' => ['merchant_id' => 0] + $context,
    'id over INT max' => ['account_id' => 2147483648] + $context,
    'uppercase instance' => ['instance' => strtoupper($context['instance'])] + $context,
    'short instance' => ['instance' => substr($context['instance'], 1)] + $context,
    'unknown kind' => ['kind' => 'password'] + $context,
    'zero generation' => ['generation' => 0] + $context,
];
foreach ($badContexts as $label => $candidate) {
    refused(fn() => Envelope::encrypt($secret, $candidate, 'k1', $kek1), 'invalid_context', "Invalid context refused: $label");
}
$reordered = ['generation' => 1, 'kind' => 'api_key', 'instance' => $context['instance'], 'merchant_id' => 3, 'account_id' => 7];
check(Envelope::decrypt($env, $reordered, $keyring) === $secret, 'Context canonicalized: key order does not matter');
refused(fn() => Envelope::encrypt('', $context, 'k1', $kek1), 'invalid_plaintext', 'Empty plaintext refused');
refused(fn() => Envelope::encrypt(str_repeat('x', 16385), $context, 'k1', $kek1), 'invalid_plaintext', 'Plaintext over 16384 bytes refused');
refused(fn() => Envelope::encrypt($secret, $context, 'k1', random_bytes(33)), 'invalid_key', 'KEK of 33 bytes refused');
refused(fn() => Envelope::encrypt($secret, $context, 'k/1', $kek1), 'invalid_kek_version', 'Invalid KEK version refused');

// --- rewrap
$rewrapped = Envelope::rewrap($env, $context, $keyring, 'k2', $kek2);
check($rewrapped['ciphertext'] === $env['ciphertext'] && $rewrapped['secret_nonce'] === $env['secret_nonce']
    && $rewrapped['kek_version'] === 'k2' && $rewrapped['dek_nonce'] !== $env['dek_nonce'] && $rewrapped['wrapped_dek'] !== $env['wrapped_dek'],
    'Rewrap keeps ciphertext and secret nonce, changes version, DEK nonce and wrapping');
check(array_keys($rewrapped) === array_keys($env) && !in_array($secret, $rewrapped, true), 'Rewrap returns only an envelope, never the plaintext');
check(Envelope::decrypt($rewrapped, $context, ['k2' => $kek2]) === $secret, 'Rewrapped envelope decrypts with the new KEK');
refused(fn() => Envelope::decrypt($rewrapped, $context, ['k1' => $kek1]), 'unknown_kek_version', 'Rewrapped envelope refused by the old keyring');
refused(fn() => Envelope::decrypt($rewrapped, $context, ['k2' => $kek1]), 'decryption_failed', 'Rewrapped envelope refused with the old key under the new version');
refused(fn() => Envelope::rewrap($env, $context, ['k1' => $kek2], 'k2', $kek2), 'decryption_failed', 'Rewrap with a wrong old KEK refused');
refused(fn() => Envelope::rewrap($flip('ciphertext'), $context, $keyring, 'k2', $kek2), 'decryption_failed', 'Rewrap verifies the secret before wrapping again');
refused(fn() => Envelope::rewrap($env, ['generation' => 2] + $context, $keyring, 'k2', $kek2), 'decryption_failed', 'Rewrap bound to the same context');

// --- errori e trace senza segreti (try/catch diretto: nessuna closure del test nella trace)
/** Testo completo di messaggio e trace con argomenti (exception_ignore_args=0). */
function exposed(Throwable $e): string { return $e->getMessage() . (string) $e . $e->getTraceAsString() . print_r($e->getTrace(), true); }
$forbidden = [$secret, $kek1, $kek2, base64_encode($kek1), base64_encode($kek2), bin2hex($kek1), bin2hex($kek2), $env['ciphertext'], $env['wrapped_dek']];
$leaks = fn(string $text) => array_filter($forbidden, fn($value) => str_contains($text, $value));
try { Envelope::decrypt($env, $context, ['k1' => $kek2]); throw new RuntimeException('FAIL: decrypt accepted'); }
catch (UnexpectedValueException $e) { check($e->getMessage() === 'decryption_failed' && !$leaks(exposed($e)), 'Decrypt error: message and trace without secret, key or envelope'); }
try { Envelope::rewrap($env, $context, ['k1' => $kek2], 'k2', $kek2); throw new RuntimeException('FAIL: rewrap accepted'); }
catch (UnexpectedValueException $e) { check(!$leaks(exposed($e)), 'Rewrap error: trace without old/new KEK or envelope'); }
try { Envelope::encrypt($secret, ['kind' => 'x'] + $context, 'k1', $kek1); throw new RuntimeException('FAIL: encrypt accepted'); }
catch (InvalidArgumentException $e) { check(!$leaks(exposed($e)), 'Encrypt error: trace without plaintext or KEK'); }

echo "PASS $checks B1a credential envelope checks\n";
