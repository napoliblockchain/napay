<?php
// SIP-022 xpub: anteprima indirizzi dalla chiave pubblica estesa. Test puro con i vettori ufficiali BIP84/BIP49/BIP44.
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__, 2) . '/components/btcpay/XpubAddresses.php';
use app\components\btcpay\XpubAddresses;

$checks = 0;
function check($condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException('FAIL: ' . $label); ++$checks; echo 'PASS: ' . $label . "\n"; }
function refused(string $scheme, string $network, string $code): bool {
    try { XpubAddresses::parse($scheme, $network); } catch (InvalidArgumentException $e) { return $e->getMessage() === $code; }
    return false;
}
// mnemonic "abandon ... about": vettori dei BIP (account 0, ramo di ricezione)
$zpub = 'zpub6rFR7y4Q2AijBEqTUquhVz398htDFrtymD9xYYfG1m4wAcvPhXNfE3EfH1r1ADqtfSdVCToUG868RvUUkgDKf31mGDtKsAYz2oz2AGutZYs';
check(XpubAddresses::parse($zpub, 'mainnet')->receiveAddresses(2) === ['bc1qcr8te4kr609gcawutmrza0j4xv80jy8z306fyu', 'bc1qnjg0jd8228aq7egyzacy8cys3knf9xvrerkf9g'],
    'BIP84 zpub: native segwit addresses as in the standard');
$ypub = 'ypub6Ww3ibxVfGzLrAH1PNcjyAWenMTbbAosGNB6VvmSEgytSER9azLDWCxoJwW7Ke7icmizBMXrzBx9979FfaHxHcrArf3zbeJJJUZPf663zsP';
check(XpubAddresses::parse($ypub, 'mainnet')->receiveAddresses(1) === ['37VucYSaXLCAsxYyAPfbSi9eh4iEcbShgf'], 'BIP49 ypub: segwit in P2SH');
$xpub = 'xpub6BosfCnifzxcFwrSzQiqu2DBVTshkCXacvNsWGYJVVhhawA7d4R5WSWGFNbi8Aw6ZRc1brxMyWMzG3DSSSSoekkudhUd9yLb6qx39T9nMdj';
check(XpubAddresses::parse($xpub . '-[legacy]', 'mainnet')->receiveAddresses(1) === ['1LqBGSKuX5yYUonjxT5qGfpUsXKYYWeabA'], 'BIP44 xpub with -[legacy]: P2PKH');
check(XpubAddresses::parse($xpub, 'mainnet')->type() === 'p2wpkh', 'Plain xpub = native segwit, as BTCPay');
// vpub di test fornita dall'utente (regtest), indirizzo atteso tra i primi di ricezione
$vpub = 'vpub5Zf9o1xg1TVJN3pte4sDD73BMq71dLFTZPgqvBxe872nMEiDtoBo99udYHBuxP3wDbhie79PmTtL8GxYR5ana9FgxdKTp13TvV3kU6UaHrP';
$regtest = XpubAddresses::parse($vpub, 'regtest')->receiveAddresses(20);
check(in_array('bcrt1qnynpu9auq7msewe4t5lwkv042x6vvp6wxcdmtx', $regtest, true) && str_starts_with($regtest[0], 'bcrt1q'), 'User vpub on regtest: the funded address is among the first receive addresses');
check(str_starts_with(XpubAddresses::parse($vpub, 'testnet')->receiveAddresses(1)[0], 'tb1q'), 'Same vpub on testnet: tb1 addresses');
check(refused($zpub, 'regtest', 'xpub_network') && refused($vpub, 'mainnet', 'xpub_network'), 'Key of the other network refused');
check(refused(substr($zpub, 0, -1) . ($zpub[-1] === 's' ? 't' : 's'), 'mainnet', 'xpub_checksum'), 'Typo caught by the checksum');
check(refused('xprv9s21ZrQH143K3QTDL4LXw2F7HEK3wJUD2nW2nRk4stbPy6cq3jPPqjiChkVvvNKmPGJxWUtg6LnF5kejMRNNU3TGtRBeJgk33yuGBxrMPHi', 'mainnet', 'xpub_not_public'), 'Private extended keys always refused');
check(refused('not a key', 'mainnet', 'xpub_format') && refused($zpub . '-[legacy]', 'mainnet', 'xpub_format') && refused($zpub, 'signet', 'invalid_network'), 'Malformed input, suffix on zpub and unknown network refused');
check(XpubAddresses::mask($vpub) === 'vpub5Zf9…UaHrP', 'Saved key shown only abbreviated');
echo "PASS $checks xpub address checks\n";
