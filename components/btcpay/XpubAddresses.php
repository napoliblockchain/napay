<?php

namespace app\components\btcpay;

/**
 * SIP-022 xpub (decisione A): validazione della chiave pubblica estesa del merchant e anteprima dei primi indirizzi di
 * ricezione, come li genererà BTCPay (ramo 0/i relativo alla chiave). Solo dati pubblici: nessuna chiave privata,
 * nessuna chiamata di rete. Aritmetica secp256k1 con GMP (derivazione BIP32 non indurita, CKDpub).
 *
 * Formati (prefisso SLIP-132 e suffisso BTCPay): zpub/vpub e xpub/tpub = segwit nativo (bech32); ypub/upub o
 * xpub/tpub con `-[p2sh]` = segwit in P2SH; xpub/tpub con `-[legacy]` = legacy P2PKH. Le chiavi private (xprv, tprv,
 * zprv, ...) sono sempre rifiutate.
 */
final class XpubAddresses
{
    public const NETWORKS = ['mainnet', 'testnet', 'regtest'];
    /** versione => [rete (main|test), tipo di indirizzo predefinito] */
    private const VERSIONS = [
        '0488b21e' => ['main', 'p2wpkh'], '049d7cb2' => ['main', 'p2sh-p2wpkh'], '04b24746' => ['main', 'p2wpkh'],
        '043587cf' => ['test', 'p2wpkh'], '044a5262' => ['test', 'p2sh-p2wpkh'], '045f1cf6' => ['test', 'p2wpkh'],
    ];
    private const SUFFIXES = ['' => null, '-[legacy]' => 'p2pkh', '-[p2sh]' => 'p2sh-p2wpkh'];
    private const BASE58 = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    private const BECH32 = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    private const P = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F';
    private const N = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141';
    private const GX = '79BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798';
    private const GY = '483ADA7726A3C4655DA4FBFC0E1108A8FD17B448A68554199C47D08FFB10D4B8';

    private function __construct(private readonly string $chainCode, private readonly array $point, private readonly string $type, private readonly string $network) {}

    /**
     * @param string $network rete di BTCPay (mainnet|testnet|regtest): la chiave deve essere della stessa famiglia
     * @throws \InvalidArgumentException con un codice generico (mai il valore inserito)
     */
    public static function parse(string $scheme, string $network): self
    {
        if (!in_array($network, self::NETWORKS, true)) throw new \InvalidArgumentException('invalid_network');
        $scheme = trim($scheme);
        if (preg_match('/^([1-9A-HJ-NP-Za-km-z]{100,120})(-\[(?:legacy|p2sh)\])?$/D', $scheme, $m) !== 1) throw new \InvalidArgumentException('xpub_format');
        $raw = self::base58Decode($m[1]);
        if ($raw === null || strlen($raw) !== 82) throw new \InvalidArgumentException('xpub_format');
        $payload = substr($raw, 0, 78);
        if (!hash_equals(substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4), substr($raw, 78))) throw new \InvalidArgumentException('xpub_checksum');
        $version = bin2hex(substr($payload, 0, 4));
        if (!isset(self::VERSIONS[$version])) throw new \InvalidArgumentException('xpub_not_public');
        [$family, $type] = self::VERSIONS[$version];
        if (($family === 'main') !== ($network === 'mainnet')) throw new \InvalidArgumentException('xpub_network');
        $suffix = $m[2] ?? '';
        if ($suffix !== '') {
            // il suffisso BTCPay vale solo per xpub/tpub (per ypub/zpub/upub/vpub il tipo è nel prefisso)
            if (!in_array($version, ['0488b21e', '043587cf'], true)) throw new \InvalidArgumentException('xpub_format');
            $type = self::SUFFIXES[$suffix];
        }
        $key = substr($payload, 45, 33);
        $point = self::decompress($key) ?? throw new \InvalidArgumentException('xpub_key');
        return new self(substr($payload, 13, 32), $point, $type, $network);
    }

    /** @return string[] primi $count indirizzi di ricezione (0/0 .. 0/$count-1) */
    public function receiveAddresses(int $count = 5): array
    {
        if ($count < 1 || $count > 20) throw new \InvalidArgumentException('invalid_count');
        [$branchCode, $branch] = self::child($this->chainCode, $this->point, 0);
        $addresses = [];
        for ($i = 0; $i < $count; ++$i) {
            [, $child] = self::child($branchCode, $branch, $i);
            $addresses[] = $this->address(self::compress($child));
        }
        return $addresses;
    }

    public function type(): string { return $this->type; }

    /** Forma abbreviata da mostrare (mai la chiave intera nelle pagine dopo il salvataggio). */
    public static function mask(string $scheme): string
    {
        $scheme = trim($scheme);
        return strlen($scheme) <= 14 ? '…' : substr($scheme, 0, 8) . '…' . substr($scheme, -5);
    }

    // --- BIP32 CKDpub (indici non induriti)

    private static function child(string $chainCode, array $point, int $index): array
    {
        $i = hash_hmac('sha512', self::compress($point) . pack('N', $index), $chainCode, true);
        $il = gmp_import(substr($i, 0, 32));
        if (gmp_cmp($il, gmp_init(self::N, 16)) >= 0) throw new \InvalidArgumentException('xpub_derivation');
        $child = self::add(self::multiply($il, [gmp_init(self::GX, 16), gmp_init(self::GY, 16)]), $point);
        if ($child === null) throw new \InvalidArgumentException('xpub_derivation');
        return [substr($i, 32), $child];
    }

    private static function add(?array $a, ?array $b): ?array
    {
        if ($a === null) return $b;
        if ($b === null) return $a;
        $p = gmp_init(self::P, 16);
        if (gmp_cmp($a[0], $b[0]) === 0) {
            if (gmp_cmp(gmp_mod(gmp_add($a[1], $b[1]), $p), 0) === 0) return null;
            $l = gmp_mod(gmp_mul(gmp_mul(3, gmp_pow($a[0], 2)), gmp_invert(gmp_mul(2, $a[1]), $p)), $p);
        } else {
            $l = gmp_mod(gmp_mul(gmp_sub($b[1], $a[1]), gmp_invert(gmp_mod(gmp_sub($b[0], $a[0]), $p), $p)), $p);
        }
        $x = gmp_mod(gmp_sub(gmp_sub(gmp_pow($l, 2), $a[0]), $b[0]), $p);
        return [$x, gmp_mod(gmp_sub(gmp_mul($l, gmp_sub($a[0], $x)), $a[1]), $p)];
    }

    private static function multiply(\GMP $k, array $point): ?array
    {
        $result = null;
        for ($bit = gmp_strval($k, 2), $n = strlen($bit), $j = 0; $j < $n; ++$j) {
            $result = self::add($result, $result);
            if ($bit[$j] === '1') $result = self::add($result, $point);
        }
        return $result;
    }

    private static function decompress(string $key): ?array
    {
        $prefix = ord($key[0]);
        if (strlen($key) !== 33 || ($prefix !== 2 && $prefix !== 3)) return null;
        $p = gmp_init(self::P, 16);
        $x = gmp_import(substr($key, 1));
        if (gmp_cmp($x, $p) >= 0) return null;
        $y2 = gmp_mod(gmp_add(gmp_pow($x, 3), 7), $p);
        $y = gmp_powm($y2, gmp_div_q(gmp_add($p, 1), 4), $p);
        if (gmp_cmp(gmp_powm($y, 2, $p), $y2) !== 0) return null;
        if (gmp_intval(gmp_mod($y, 2)) !== ($prefix & 1)) $y = gmp_sub($p, $y);
        return [$x, $y];
    }

    private static function compress(array $point): string
    {
        return chr(gmp_intval(gmp_mod($point[1], 2)) === 0 ? 2 : 3) . str_pad(gmp_export($point[0]), 32, "\0", STR_PAD_LEFT);
    }

    // --- indirizzi

    private function address(string $publicKey): string
    {
        $hash = hash('ripemd160', hash('sha256', $publicKey, true), true);
        $main = $this->network === 'mainnet';
        return match ($this->type) {
            'p2wpkh' => self::segwit(['mainnet' => 'bc', 'testnet' => 'tb', 'regtest' => 'bcrt'][$this->network], $hash),
            'p2sh-p2wpkh' => self::base58Check(($main ? "\x05" : "\xc4") . hash('ripemd160', hash('sha256', "\x00\x14" . $hash, true), true)),
            default => self::base58Check(($main ? "\x00" : "\x6f") . $hash),
        };
    }

    private static function segwit(string $hrp, string $program): string
    {
        $data = [0];
        $acc = 0; $bits = 0;
        foreach (str_split($program) as $byte) {
            $acc = ($acc << 8) | ord($byte); $bits += 8;
            while ($bits >= 5) { $bits -= 5; $data[] = ($acc >> $bits) & 31; }
        }
        if ($bits > 0) $data[] = ($acc << (5 - $bits)) & 31;
        $values = array_merge(array_map(fn($c) => ord($c) >> 5, str_split($hrp)), [0], array_map(fn($c) => ord($c) & 31, str_split($hrp)), $data, [0, 0, 0, 0, 0, 0]);
        $polymod = self::polymod($values) ^ 1;
        $out = $hrp . '1';
        foreach ($data as $d) $out .= self::BECH32[$d];
        for ($i = 0; $i < 6; ++$i) $out .= self::BECH32[($polymod >> (5 * (5 - $i))) & 31];
        return $out;
    }

    private static function polymod(array $values): int
    {
        $generator = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
        $chk = 1;
        foreach ($values as $v) {
            $top = $chk >> 25;
            $chk = (($chk & 0x1ffffff) << 5) ^ $v;
            for ($i = 0; $i < 5; ++$i) if (($top >> $i) & 1) $chk ^= $generator[$i];
        }
        return $chk;
    }

    private static function base58Check(string $payload): string
    {
        $data = $payload . substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
        $n = gmp_import($data);
        $out = '';
        while (gmp_cmp($n, 0) > 0) { [$n, $r] = gmp_div_qr($n, 58); $out = self::BASE58[gmp_intval($r)] . $out; }
        for ($i = 0; $i < strlen($data) && $data[$i] === "\0"; ++$i) $out = '1' . $out;
        return $out;
    }

    private static function base58Decode(string $text): ?string
    {
        $n = gmp_init(0);
        foreach (str_split($text) as $c) {
            $v = strpos(self::BASE58, $c);
            if ($v === false) return null;
            $n = gmp_add(gmp_mul($n, 58), $v);
        }
        $bytes = gmp_cmp($n, 0) === 0 ? '' : gmp_export($n);
        for ($i = 0; $i < strlen($text) && $text[$i] === '1'; ++$i) $bytes = "\0" . $bytes;
        return $bytes;
    }
}
