<?php
namespace app\components\pos\tts;

/**
 * Cambio TTS/EUR degli incassi POS: centesimi di euro per 1 TTS. Oggi da TTS_EUR_RATE ("1.00", due decimali al massimo;
 * assente = 1.00), più avanti da un endpoint di quotazione come per un exchange (stessa interfaccia).
 * Il cassiere digita il prezzo in EUR; il cliente paga in TTS l'importo convertito, arrotondato per eccesso al centesimo.
 */
final class TtsRate
{
    public const FIAT = 'EUR';

    /** @return int|null centesimi di EUR per 1 TTS; null = configurazione non valida (incasso TTS non disponibile, mai un cambio inventato) */
    public static function current(): ?int
    {
        $value = trim((string) env('TTS_EUR_RATE', ''));
        if ($value === '') return 100;
        if (preg_match('/^(0|[1-9][0-9]{0,5})(?:\.([0-9]{1,2}))?$/D', $value, $m) !== 1) return null;
        $cents = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        return $cents >= 1 ? $cents : null;
    }

    /** Centesimi di TTS per un importo in centesimi di EUR: per eccesso (mai meno del prezzo). */
    public static function tts(int $fiatCents, int $rateCents): int
    {
        if ($fiatCents < 1 || $rateCents < 1) throw new \InvalidArgumentException('invalid_rate');
        return intdiv($fiatCents * 100 + $rateCents - 1, $rateCents);
    }
}
