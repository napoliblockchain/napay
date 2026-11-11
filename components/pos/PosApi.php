<?php

namespace app\components\pos;

use app\components\Throttle;
use app\models\PosOperatorSessions;
use app\models\Users;
use yii\base\InvalidConfigException;

/**
 * API server-to-server per i POS (SIP-021): autenticazione del servizio, rate limit, sessioni operatore.
 * Segreti solo da configurazione privata: POS_API_CLIENT_KEYS_SHA256 contiene i digest delle chiavi, mai le chiavi.
 */
final class PosApi
{
    public const TOKEN_PREFIX = 'napos_';
    public const DEFAULT_TTL = 43200;
    private const MAX_TTL = 86400;
    private const MIN_TTL = 300;
    // finestre del rate limit: [limite, secondi]
    public const LIMIT_CLIENT = [60, 60];
    public const LIMIT_IP = [10, 60];
    public const LIMIT_ACCOUNT_FAILURES = [5, 900];

    /**
     * Digest SHA-256 (hex) delle chiavi client autorizzate; due valori permettono la rotazione.
     * @throws InvalidConfigException configurazione assente o non valida: API disattivata (fail secure)
     */
    public static function clientKeyDigests(): array
    {
        $raw = trim((string) getenv('POS_API_CLIENT_KEYS_SHA256'));
        $digests = array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
        if ($digests === [] || count($digests) > 4) throw new InvalidConfigException('POS API client keys not configured.');
        foreach ($digests as $digest) {
            if (!preg_match('/^[a-f0-9]{64}$/D', $digest)) throw new InvalidConfigException('POS API client keys not configured.');
        }
        return $digests;
    }

    /** @throws InvalidConfigException */
    public static function ttl(): int
    {
        $raw = getenv('POS_SESSION_TTL_SECONDS');
        if ($raw === false || trim($raw) === '') return self::DEFAULT_TTL;
        $ttl = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => self::MIN_TTL, 'max_range' => self::MAX_TTL]]);
        if ($ttl === false) throw new InvalidConfigException('Invalid POS session TTL.');
        return $ttl;
    }

    /**
     * Prefisso pubblico (16 hex) del digest della chiave presentata, o null se non autorizzata.
     * Confronto a tempo costante con tutti i digest configurati.
     * @throws InvalidConfigException
     */
    public static function authenticateClient($key): ?string
    {
        $digests = self::clientKeyDigests();
        if (!is_string($key) || $key === '' || strlen($key) > 256) return null;
        $presented = hash('sha256', $key);
        $match = null;
        foreach ($digests as $digest) {
            if (hash_equals($digest, $presented)) $match = $digest;
        }
        return $match === null ? null : substr($match, 0, 16);
    }

    /**
     * Rate limit a finestra fissa (components/Throttle).
     * @return int secondi di attesa (0 = consentito); $count=false controlla senza conteggiare
     */
    public static function throttle(string $key, array $limit, bool $count = true): int
    {
        return Throttle::hit('pos-api|' . $key, $limit, $count);
    }

    public static function resetThrottle(string $key): void
    {
        Throttle::reset('pos-api|' . $key);
    }

    public static function accountKey(string $email): string
    {
        return 'account|' . strtolower(trim($email));
    }

    /**
     * Nuova sessione: restituisce il token in chiaro una sola volta, nel DB resta il digest.
     * @return array{0: string, 1: PosOperatorSessions}
     * @throws InvalidConfigException
     */
    public static function createSession(Users $user, string $clientPrefix, ?string $ip): array
    {
        $token = self::TOKEN_PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = time();
        $session = new PosOperatorSessions([
            'user_id' => $user->id,
            'token_sha256' => hash('sha256', $token),
            'client_key_prefix' => $clientPrefix,
            'credential_fingerprint' => self::fingerprint($user),
            'created_at' => $now,
            'expires_at' => $now + self::ttl(),
            'created_ip' => $ip,
        ]);
        if (!$session->save(false)) throw new \RuntimeException('POS session not saved.');
        return [$token, $session];
    }

    /**
     * Sessione ancora valida per questo token e client: non revocata, non scaduta, utente attivo, password invariata.
     */
    public static function findActiveSession($token, string $clientPrefix): ?PosOperatorSessions
    {
        if (!is_string($token) || !str_starts_with($token, self::TOKEN_PREFIX) || strlen($token) > 128) return null;
        $session = PosOperatorSessions::find()->where(['token_sha256' => hash('sha256', $token)])->with('user')->one();
        if ($session === null || $session->revoked_at !== null || (int) $session->expires_at <= time()
            || !hash_equals($session->client_key_prefix, $clientPrefix)) return null;
        $user = $session->user;
        if ($user === null || !$user->validateStatus() || !hash_equals($session->credential_fingerprint, self::fingerprint($user))) return null;
        return $session;
    }

    public static function revoke(PosOperatorSessions $session, string $reason): void
    {
        PosOperatorSessions::updateAll(['revoked_at' => time(), 'revoked_reason' => mb_substr($reason, 0, 32)], ['id' => $session->id, 'revoked_at' => null]);
    }

    /** Cambio o reset della password = nuova impronta: le sessioni precedenti non valgono più */
    private static function fingerprint(Users $user): string
    {
        return hash('sha256', 'pos-session|' . $user->password);
    }
}
