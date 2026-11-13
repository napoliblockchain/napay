<?php
namespace app\components\btcpay\provisioning;

/**
 * SIP-022 B1 §4: quando ritentare dopo una risposta che prova la mancata esecuzione (429).
 * Retry-After: secondi interi non negativi o IMF-fixdate rigorosa (RFC 9110). Solo assente o non interpretabile
 * usa il backoff; un valore valido, anche enorme, non ricade mai su un'attesa breve.
 * Prossimo tentativo = max(scadenza server, adesso + backoff); oltre 24 ore deferred (solo admin).
 * Puro: nessun HTTP, DB, configurazione o segreto; jitter iniettabile.
 */
final class RetryPolicy
{
    public const BASE_SECONDS = 30;
    public const MAX_BACKOFF = 3600;
    public const DEFER_AFTER = 86400;
    public const MAX_ATTEMPT = 1000;
    private const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    private readonly \Closure $jitter;

    /** @param ?\Closure $jitter fn(int $max): int in 0..$max; predefinito random_int */
    public function __construct(?\Closure $jitter = null)
    {
        $this->jitter = $jitter ?? static fn(int $max): int => random_int(0, $max);
    }

    /**
     * @param int $attempt tentativo appena fallito (1 = primo)
     * @return array{next_attempt_at:int, deferred:bool, server:bool} server = Retry-After valido e usato
     */
    public function decide(?string $retryAfter, int $attempt, int $now): array
    {
        if ($attempt < 1 || $attempt > self::MAX_ATTEMPT) throw new \InvalidArgumentException('invalid_attempt');
        // Un orologio che non può rappresentare il backoff massimo non viene saturato: accorcerebbe l'attesa minima.
        if ($now < 0 || $now > PHP_INT_MAX - self::MAX_BACKOFF) throw new \InvalidArgumentException('invalid_now');
        $backoff = $this->backoff($attempt);
        $next = $now + $backoff;
        $server = $retryAfter === null ? null : self::serverTime($retryAfter, $now);
        if ($server !== null && $server > $next) $next = $server;
        return ['next_attempt_at' => $next, 'deferred' => $next - $now > self::DEFER_AFTER, 'server' => $server !== null];
    }

    /** 30 s * 2^(n-1) con jitter fino a un quinto, mai oltre un'ora. */
    private function backoff(int $attempt): int
    {
        $delay = $attempt > 7 ? self::MAX_BACKOFF : min(self::BASE_SECONDS << ($attempt - 1), self::MAX_BACKOFF);
        $max = intdiv($delay, 5);
        $jitter = ($this->jitter)($max);
        if (!is_int($jitter) || $jitter < 0 || $jitter > $max) throw new \UnexpectedValueException('invalid_jitter');
        return min($delay + $jitter, self::MAX_BACKOFF);
    }

    /** Istante indicato dal server, o null se il valore non è interpretabile. Valori oltre l'intero massimo: PHP_INT_MAX. */
    private static function serverTime(string $value, int $now): ?int
    {
        $value = trim($value, " \t");
        if ($value !== '' && strspn($value, '0123456789') === strlen($value)) {
            $digits = ltrim($value, '0');
            if ($digits === '') return $now;
            $limit = (string) PHP_INT_MAX;
            if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) return PHP_INT_MAX;
            $seconds = (int) $digits;
            return $seconds > PHP_INT_MAX - $now ? PHP_INT_MAX : $now + $seconds;
        }
        if (preg_match('/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun), ([0-9]{2}) (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) ([0-9]{4}) ([0-9]{2}):([0-9]{2}):([0-9]{2}) GMT$/D', $value, $m) !== 1) return null;
        [, $day, $date, $month, $year, $hour, $minute, $second] = $m;
        $month = array_search($month, self::MONTHS, true) + 1;
        if (!checkdate($month, (int) $date, (int) $year) || (int) $hour > 23 || (int) $minute > 59 || (int) $second > 60) return null;
        $time = gmmktime((int) $hour, (int) $minute, (int) $second, $month, (int) $date, (int) $year);
        // Il giorno della settimana deve corrispondere alla data (60 s = secondo intercalare, stesso giorno).
        if ($time === false || self::DAYS[(int) gmdate('N', $time - ((int) $second === 60 ? 1 : 0)) - 1] !== $day) return null;
        return max($time, $now);
    }
}
