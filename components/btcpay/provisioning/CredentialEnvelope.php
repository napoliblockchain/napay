<?php

namespace app\components\btcpay\provisioning;

/**
 * SIP-022 B1a: busta per le credenziali BTCPay dei merchant (password di onboarding, chiave API).
 * Funzioni pure: niente Yii, DB, file, ambiente, rete o log; nessuna chiave generata o salvata qui.
 *
 * Due livelli XChaCha20-Poly1305 IETF:
 * - segreto cifrato con una DEK casuale nuova, dati associati = formato, tabella e contesto;
 * - DEK cifrata con la KEK, dati associati = gli stessi più la versione della KEK.
 * rewrap cambia solo il secondo livello: testo cifrato e nonce del segreto restano invariati.
 * Errori con codici fissi, mai valori, eccezioni originali o input nel messaggio.
 */
final class CredentialEnvelope
{
    public const FORMAT = 1;
    public const KINDS = ['onboarding_password', 'api_key'];
    public const MAX_PLAINTEXT = 16384;

    private const TABLE = 'btcpay_merchant_credentials';
    private const FIELDS = ['version', 'kek_version', 'secret_nonce', 'ciphertext', 'dek_nonce', 'wrapped_dek'];
    private const CONTEXT = ['account_id', 'merchant_id', 'instance', 'kind', 'generation'];
    private const INT_MAX = 2147483647;
    private const KEY_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;
    private const NONCE_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
    private const TAG_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;

    /** @return array{version:int,kek_version:string,secret_nonce:string,ciphertext:string,dek_nonce:string,wrapped_dek:string} */
    public static function encrypt(#[\SensitiveParameter] string $plaintext, array $context, string $kekVersion, #[\SensitiveParameter] string $kek): array
    {
        try {
            if ($plaintext === '' || strlen($plaintext) > self::MAX_PLAINTEXT) throw new \InvalidArgumentException('invalid_plaintext');
            $context = self::context($context);
            self::kekVersion($kekVersion);
            self::key($kek);
            $dek = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
            try {
                $secretNonce = random_bytes(self::NONCE_BYTES);
                $dekNonce = random_bytes(self::NONCE_BYTES);
                $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, self::secretAd($context), $secretNonce, $dek);
                $wrapped = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($dek, self::wrapAd($context, $kekVersion), $dekNonce, $kek);
            } finally {
                sodium_memzero($dek);
            }
            return self::envelope($kekVersion, $secretNonce, $ciphertext, $dekNonce, $wrapped);
        } catch (\SodiumException $error) {
            // errore interno di sodium: codice fisso, nessuna eccezione originale
            throw new \UnexpectedValueException('crypto_error');
        }
    }

    public static function decrypt(#[\SensitiveParameter] array $envelope, array $context, #[\SensitiveParameter] array $keyring): string
    {
        try {
            $context = self::context($context);
            $parts = self::parse($envelope);
            $dek = self::unwrap($parts, $context, $keyring);
            try {
                $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($parts['ciphertext'], self::secretAd($context), $parts['secret_nonce'], $dek);
            } finally {
                sodium_memzero($dek);
            }
            if ($plaintext === false || $plaintext === '') throw new \UnexpectedValueException('decryption_failed');
            return $plaintext;
        } catch (\SodiumException $error) {
            // errore interno di sodium: codice fisso, nessuna eccezione originale
            throw new \UnexpectedValueException('crypto_error');
        }
    }

    /** Nuova KEK: verifica entrambi i livelli, poi cifra la stessa DEK con un nuovo nonce. Non restituisce il segreto. */
    public static function rewrap(#[\SensitiveParameter] array $envelope, array $context, #[\SensitiveParameter] array $keyring, string $newKekVersion, #[\SensitiveParameter] string $newKek): array
    {
        try {
            $context = self::context($context);
            self::kekVersion($newKekVersion);
            self::key($newKek);
            $parts = self::parse($envelope);
            $dek = self::unwrap($parts, $context, $keyring);
            try {
                $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($parts['ciphertext'], self::secretAd($context), $parts['secret_nonce'], $dek);
                if ($plaintext === false || $plaintext === '') throw new \UnexpectedValueException('decryption_failed');
                sodium_memzero($plaintext);
                $dekNonce = random_bytes(self::NONCE_BYTES);
                $wrapped = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($dek, self::wrapAd($context, $newKekVersion), $dekNonce, $newKek);
            } finally {
                sodium_memzero($dek);
            }
            return self::envelope($newKekVersion, $parts['secret_nonce'], $parts['ciphertext'], $dekNonce, $wrapped);
        } catch (\SodiumException $error) {
            // errore interno di sodium: codice fisso, nessuna eccezione originale
            throw new \UnexpectedValueException('crypto_error');
        }
    }

    private static function unwrap(array $parts, array $context, #[\SensitiveParameter] array $keyring): string
    {
        $version = $parts['kek_version'];
        // nessun ripiego su un'altra chiave: versione assente = errore
        if (!array_key_exists($version, $keyring) || !is_string($keyring[$version])) throw new \InvalidArgumentException('unknown_kek_version');
        $kek = $keyring[$version];
        self::key($kek);
        $dek = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($parts['wrapped_dek'], self::wrapAd($context, $version), $parts['dek_nonce'], $kek);
        if ($dek === false || strlen($dek) !== self::KEY_BYTES) throw new \UnexpectedValueException('decryption_failed');
        return $dek;
    }

    /** Contesto canonico: esattamente i campi previsti, tipi stretti, ordine fisso. */
    private static function context(array $context): array
    {
        $keys = array_keys($context);
        sort($keys);
        $expected = self::CONTEXT;
        sort($expected);
        if ($keys !== $expected) throw new \InvalidArgumentException('invalid_context');
        foreach (['account_id', 'merchant_id', 'generation'] as $field) {
            if (!is_int($context[$field]) || $context[$field] < 1 || $context[$field] > self::INT_MAX) throw new \InvalidArgumentException('invalid_context');
        }
        if (!is_string($context['instance']) || preg_match('/^[0-9a-f]{64}$/D', $context['instance']) !== 1
            || !in_array($context['kind'], self::KINDS, true)) throw new \InvalidArgumentException('invalid_context');
        $canonical = [];
        foreach (self::CONTEXT as $field) $canonical[$field] = $context[$field];
        return $canonical;
    }

    private static function secretAd(array $context): string
    {
        return 'napay-btcpay-credential|format=' . self::FORMAT . '|table=' . self::TABLE
            . '|account_id=' . $context['account_id'] . '|merchant_id=' . $context['merchant_id']
            . '|instance=' . $context['instance'] . '|kind=' . $context['kind'] . '|generation=' . $context['generation'];
    }

    private static function wrapAd(array $context, string $kekVersion): string
    {
        return self::secretAd($context) . '|kek_version=' . $kekVersion;
    }

    private static function kekVersion($version): void
    {
        if (!is_string($version) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $version) !== 1) throw new \InvalidArgumentException('invalid_kek_version');
    }

    private static function key(#[\SensitiveParameter] $key): void
    {
        if (!is_string($key) || strlen($key) !== self::KEY_BYTES) throw new \InvalidArgumentException('invalid_key');
    }

    /** Busta: esattamente i sei campi, versione intera 1, base64 standard canonica, lunghezze esatte. */
    private static function parse(#[\SensitiveParameter] array $envelope): array
    {
        $keys = array_keys($envelope);
        sort($keys);
        $expected = self::FIELDS;
        sort($expected);
        if ($keys !== $expected || $envelope['version'] !== self::FORMAT) throw new \InvalidArgumentException('invalid_envelope');
        self::kekVersion($envelope['kek_version']);
        $parts = ['kek_version' => $envelope['kek_version']];
        $limits = [
            'secret_nonce' => [self::NONCE_BYTES, self::NONCE_BYTES],
            'dek_nonce' => [self::NONCE_BYTES, self::NONCE_BYTES],
            'wrapped_dek' => [self::KEY_BYTES + self::TAG_BYTES, self::KEY_BYTES + self::TAG_BYTES],
            'ciphertext' => [1 + self::TAG_BYTES, self::MAX_PLAINTEXT + self::TAG_BYTES],
        ];
        foreach ($limits as $field => [$min, $max]) {
            $value = $envelope[$field];
            $binary = is_string($value) ? base64_decode($value, true) : false;
            if ($binary === false || base64_encode($binary) !== $value || strlen($binary) < $min || strlen($binary) > $max) {
                throw new \InvalidArgumentException('invalid_envelope');
            }
            $parts[$field] = $binary;
        }
        return $parts;
    }

    private static function envelope(string $kekVersion, string $secretNonce, string $ciphertext, string $dekNonce, string $wrapped): array
    {
        return [
            'version' => self::FORMAT,
            'kek_version' => $kekVersion,
            'secret_nonce' => base64_encode($secretNonce),
            'ciphertext' => base64_encode($ciphertext),
            'dek_nonce' => base64_encode($dekNonce),
            'wrapped_dek' => base64_encode($wrapped),
        ];
    }
}
