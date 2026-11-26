<?php
namespace app\components\pos\tts;

use kornrunner\Keccak;

/**
 * SIP-021 fase 3: indirizzi wallet TTS. Formato 0x + 40 esadecimali; maiuscole/minuscole miste solo se
 * conformi al checksum EIP-55. Forma normalizzata = checksum EIP-55 (salvata e mostrata).
 */
final class TtsAddress
{
    /** @return string|null indirizzo in forma EIP-55, null se non valido */
    public static function normalize(string $address): ?string
    {
        if (preg_match('/^0x([0-9a-fA-F]{40})$/D', $address, $match) !== 1) return null;
        $hex = $match[1];
        $checksum = self::checksum($hex);
        $mixed = strtolower($hex) !== $hex && strtoupper($hex) !== $hex;
        if ($mixed && $checksum !== '0x' . $hex) return null;
        // l'indirizzo nullo non riceve pagamenti
        return strtolower($hex) === str_repeat('0', 40) ? null : $checksum;
    }

    /** Argomento indirizzo di un evento o di una chiamata ABI: 32 byte esadecimali minuscoli. */
    public static function word(string $address): string
    {
        return str_repeat('0', 24) . strtolower(substr($address, 2));
    }

    private static function checksum(string $hex): string
    {
        $lower = strtolower($hex);
        $hash = Keccak::hash($lower, 256);
        $out = '0x';
        for ($i = 0; $i < 40; ++$i) $out .= hexdec($hash[$i]) >= 8 ? strtoupper($lower[$i]) : $lower[$i];
        return $out;
    }
}
