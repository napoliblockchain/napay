<?php

namespace app\components\legacy;

use PDO;
use app\components\btcpay\StoreSettingsPayload;
use app\components\db\V2Bootstrap as Db;
use app\components\merchant\MerchantLogo;

/**
 * SIP-030 passo 5a: merchant, negozi, impostazioni, wallet TTS e logo dalla vecchia app Yii1, dentro la transazione di
 * LegacyImporter. Import una tantum prima dell'avvio della v2: poi sono dati v2 come gli altri. Ripetibile senza doppioni
 * (legacy_id è solo la chiave tecnica dell'import): crea ciò che manca, non riscrive mai ciò che esiste già.
 * - Merchant: titolare = utente v1 importato; un merchant v2 già esistente dello stesso titolare viene solo collegato.
 * - Negozi: attivi ed eliminati (eliminati = archiviati); il negozio senza merchant è lo store delle quote associative
 *   (non diventa un negozio: il suo ID BTCPay va in BTCPAY_STORE_ID, riportato nel report).
 * - Impostazioni: standard v2 alla creazione; dalla v1 solo sito, ID dello store BTCPay (ponte, SIP-030 decisione 1) e xpub
 *   valida da np_mpk. Mai i segreti v1 (chiavi exchange, securityToken, nonce) né la wallet_key dei wallet.
 * - Wallet TTS del titolare copiato su tutti i suoi negozi attivi; logo del primo negozio con logo = logo del merchant,
 *   letto da custom/<storeid>/CustomLogo.* della cartella file v1 e ricodificato (mai sovrascritto se già presente).
 * - Passo 5b: POS v1 = casse del loro negozio (np_pos), senza operatori (li invita il merchant); POS eliminati o di negozi
 *   archiviati = casse disabilitate. Codice di abbinamento v1 non importato. Chiave tecnica in legacy_pos_terminals.
 */
final class LegacyMerchantImport
{
    private const STORE_ID = '/^[A-Za-z0-9]{20,64}$/D';
    private const XPUB = '/^[xyztuv]pub[1-9A-HJ-NP-Za-km-z]{100,112}(?:-\[(?:p2sh|legacy)\])?$/D';
    private const TTS = '/^0x[0-9a-fA-F]{40}$/D';
    private const LOGO = '#^https?://[^/\s]{1,253}/custom/([A-Za-z0-9]{20,64})/CustomLogo\.(png|jpe?g)$#Di';
    private const COUNTRIES = ['italia' => 'IT', 'italy' => 'IT'];

    /**
     * @param array<int,int> $userMap utente v1 -> utente v2 (già importati)
     * @param array<int,string> $comuni ID comune v1 -> nome
     */
    public static function import(PDO $source, PDO $target, array $userMap, array $comuni, ?string $filesDir, int $now): array
    {
        $report = ['merchants' => 0, 'merchants_linked' => 0, 'merchants_skipped' => 0, 'stores' => 0, 'stores_archived' => 0, 'stores_skipped' => 0,
            'btcpay_stores' => 0, 'websites' => 0, 'xpubs' => 0, 'xpubs_invalid' => 0, 'tts_wallets' => 0, 'tts_wallets_invalid' => 0,
            'logos' => 0, 'logos_missing' => 0, 'terminals' => 0, 'terminals_disabled' => 0, 'terminals_skipped' => 0, 'membership_store' => null];
        $merchants = [];
        foreach (Db::rows($source, 'SELECT * FROM np_merchants ORDER BY id_merchant') as $row) {
            $legacyUser = trim((string) $row['id_user']);
            $userId = ctype_digit($legacyUser) ? ($userMap[(int) $legacyUser] ?? null) : null;
            if ((int) $row['deleted'] === 1 || $userId === null) { $report['merchants_skipped']++; continue; }
            $email = (string) Db::rows($target, 'SELECT email FROM users WHERE id=?', [$userId])[0]['email'];
            $fields = ['user_id' => $userId, 'description' => self::cut($row['denomination'], 256) ?? 'Merchant ' . (int) $row['id_merchant'], 'email' => mb_substr($email, 0, 256),
                'vatNumber' => self::cut($row['vat'], 16) ?? '', 'phone' => '', 'mobile' => '', 'addressStreet' => self::cut($row['address'], 512) ?? '',
                'addressNumberHouse' => '', 'addressCity' => self::cut(self::city($row['city'], $comuni), 256) ?? '', 'addressZip' => self::cut($row['cap'], 20) ?? '',
                'addressProvince' => '', 'addressCountry' => self::country($row['county'])];
            $existing = Db::rows($target, 'SELECT id, legacy_id FROM merchants WHERE legacy_id=? OR (legacy_id IS NULL AND user_id=?) ORDER BY legacy_id IS NULL LIMIT 2',
                [(int) $row['id_merchant'], $userId]);
            if ($existing === []) {
                $id = self::insert($target, 'merchants', $fields + ['legacy_id' => (int) $row['id_merchant']]);
            } else {
                // già presente (import ripetuto o merchant già attivato nella v2 dal titolare): mai riscritto
                $id = (int) $existing[0]['id'];
                if ($existing[0]['legacy_id'] === null) {
                    self::update($target, 'merchants', ['legacy_id' => (int) $row['id_merchant']], $id);
                    $report['merchants_linked']++;
                }
            }
            $merchants[(int) $row['id_merchant']] = ['id' => $id, 'user' => $userId, 'legacy_user' => (int) $legacyUser, 'email' => $email];
            $report['merchants']++;
        }

        $settings = [];
        foreach (Db::rows($source, "SELECT id_store, setting_name, setting_value FROM np_settings_stores WHERE setting_name IN ('bps_storeid','store_website','CustomLogo') ORDER BY id_setting") as $row) {
            $settings[(int) $row['id_store']][$row['setting_name']] = trim((string) $row['setting_value']);
        }
        $xpubs = [];
        foreach (Db::rows($source, "SELECT id_store, DerivationScheme FROM np_mpk WHERE asset='BTC' ORDER BY id_mpk") as $row) {
            $scheme = trim((string) $row['DerivationScheme']);
            if (preg_match(self::XPUB, $scheme) === 1) $xpubs[(int) $row['id_store']] = $scheme;
            else $report['xpubs_invalid']++;
        }
        $stores = [];
        foreach (Db::rows($source, 'SELECT * FROM np_stores ORDER BY id_store') as $row) {
            $legacyStore = (int) $row['id_store'];
            $storeSettings = $settings[$legacyStore] ?? [];
            $btcpay = trim((string) ($row['bps_storeid'] ?? '')) ?: ($storeSettings['bps_storeid'] ?? '');
            $btcpay = preg_match(self::STORE_ID, $btcpay) === 1 ? $btcpay : null;
            $merchant = $merchants[(int) $row['id_merchant']] ?? null;
            if ((int) $row['id_merchant'] === 0 && (int) $row['deleted'] !== 1) { $report['membership_store'] = $btcpay; continue; }
            if ($merchant === null) { $report['stores_skipped']++; continue; }
            $archived = (int) $row['deleted'] === 1;
            $fields = ['merchant_id' => $merchant['id'], 'description' => self::cut($row['denomination'], 256) ?? 'Negozio ' . $legacyStore, 'email' => mb_substr($merchant['email'], 0, 256),
                'phone' => '', 'mobile' => '', 'addressStreet' => self::cut($row['address'], 512) ?? '', 'addressNumberHouse' => '',
                'addressCity' => self::cut(self::city($row['city'], $comuni), 256) ?? '', 'addressZip' => self::cut($row['cap'], 20) ?? '', 'addressProvince' => '',
                'addressCountry' => self::country($row['nation'] ?? $row['county'])];
            $existing = Db::rows($target, 'SELECT id FROM stores WHERE legacy_id=?', [$legacyStore])[0] ?? null;
            // import ripetuto: il negozio già creato resta com'è nella v2
            $storeId = $existing !== null ? (int) $existing['id'] : self::insert($target, 'stores', $fields + ['archived_at' => $archived ? $now : null, 'legacy_id' => $legacyStore]);
            // nella v1 "0" = campo vuoto
            $website = StoreSettingsPayload::url(self::cut($storeSettings['store_website'] ?? null, 255), true);
            $owned = ['bps_storeid' => $btcpay, 'website' => $website, 'derivationScheme' => $xpubs[$legacyStore] ?? null];
            // standard v2 (SIP-033); logo non salvato: lo calcola il web dal merchant all'aggiornamento dello store
            if (Db::rows($target, 'SELECT store_id FROM storesettings WHERE store_id=?', [$storeId]) === []) {
                self::insert($target, 'storesettings', ['store_id' => $storeId, 'customLogo' => null] + StoreSettingsPayload::defaultRow() + $owned);
            }
            $report['stores']++;
            if ($archived) $report['stores_archived']++;
            if ($btcpay !== null) $report['btcpay_stores']++;
            if ($website !== null) $report['websites']++;
            if ($owned['derivationScheme'] !== null) $report['xpubs']++;
            $stores[$legacyStore] = ['id' => $storeId, 'merchant' => (int) $row['id_merchant'], 'archived' => $archived, 'logo' => $storeSettings['CustomLogo'] ?? ''];
        }

        // passo 5b: POS v1 = casse del negozio importato (merchant del negozio v2), mai riscritte
        foreach (Db::rows($source, 'SELECT id_pos, denomination, id_store, deleted FROM np_pos ORDER BY id_pos') as $row) {
            $store = $stores[(int) $row['id_store']] ?? null;
            if ($store === null) { $report['terminals_skipped']++; continue; }
            $report['terminals']++;
            $enabled = (int) $row['deleted'] !== 1 && !$store['archived'];
            if (!$enabled) $report['terminals_disabled']++;
            if (Db::rows($target, 'SELECT terminal_id FROM legacy_pos_terminals WHERE legacy_id=?', [(int) $row['id_pos']]) !== []) continue;
            $terminal = self::insert($target, 'pos_terminals', ['public_id' => self::uuid(), 'merchant_id' => $merchants[$store['merchant']]['id'], 'store_id' => $store['id'],
                'name' => self::cut($row['denomination'], 256) ?? 'Cassa ' . (int) $row['id_pos'], 'is_enabled' => $enabled ? 1 : 0, 'created_at' => $now, 'updated_at' => $now]);
            self::insert($target, 'legacy_pos_terminals', ['legacy_id' => (int) $row['id_pos'], 'terminal_id' => $terminal]);
        }

        // wallet TTS del titolare su tutti i suoi negozi attivi (mai la wallet_key v1)
        $wallets = [];
        foreach (Db::rows($source, 'SELECT id_user, wallet_address FROM np_wallets ORDER BY id_wallet') as $row) {
            $address = trim((string) $row['wallet_address']);
            if (preg_match(self::TTS, $address) !== 1) { $report['tts_wallets_invalid']++; continue; }
            $wallets[(int) $row['id_user']] = strtolower($address);
        }
        foreach ($stores as $store) {
            $merchant = $merchants[$store['merchant']];
            $address = $wallets[$merchant['legacy_user']] ?? null;
            if ($store['archived'] || $address === null) continue;
            $fields = ['merchant_id' => $merchant['id'], 'address' => $address, 'updated_by' => $merchant['user'], 'updated_at' => $now];
            if (Db::rows($target, 'SELECT store_id FROM store_tts_wallets WHERE store_id=?', [$store['id']]) === []) self::insert($target, 'store_tts_wallets', ['store_id' => $store['id']] + $fields);
            $report['tts_wallets']++;
        }

        // logo: primo negozio del merchant con un logo v1 leggibile; mai sopra un logo già presente nella v2
        $done = [];
        foreach ($stores as $store) {
            if (isset($done[$store['merchant']]) || preg_match(self::LOGO, $store['logo'], $m) !== 1) continue;
            $merchant = $merchants[$store['merchant']];
            $done[$store['merchant']] = true;
            if (Db::rows($target, 'SELECT merchant_id FROM merchant_logos WHERE merchant_id=?', [$merchant['id']]) !== []) continue;
            $path = $filesDir === null ? null : $filesDir . '/custom/' . $m[1] . '/CustomLogo.' . strtolower($m[2]);
            $logo = $path !== null && is_file($path) && !is_link($path) ? MerchantLogo::normalize($path) : 'missing';
            if (!is_array($logo)) { $report['logos_missing']++; continue; }
            self::insert($target, 'merchant_logos', ['merchant_id' => $merchant['id'], 'sha256' => $logo['sha256'], 'content' => $logo['content'],
                'width' => $logo['width'], 'height' => $logo['height'], 'updated_by' => $merchant['user'], 'updated_at' => $now]);
            $report['logos']++;
        }
        return $report;
    }

    /** Merchant e negozi v1 importabili e v2 importati devono coincidere. */
    public static function reconcile(PDO $source, PDO $target): void
    {
        $userMap = [];
        foreach (Db::rows($target, 'SELECT legacy_user_id, user_id FROM member_profiles WHERE legacy_user_id IS NOT NULL') as $row) $userMap[(int) $row['legacy_user_id']] = (int) $row['user_id'];
        $merchants = 0; $owners = [];
        foreach (Db::rows($source, 'SELECT id_merchant, id_user, deleted FROM np_merchants') as $row) {
            $legacyUser = trim((string) $row['id_user']);
            if ((int) $row['deleted'] === 1 || !ctype_digit($legacyUser) || !isset($userMap[(int) $legacyUser])) continue;
            $merchants++;
            $owners[] = (int) $row['id_merchant'];
        }
        $stores = 0;
        foreach (Db::rows($source, 'SELECT id_merchant FROM np_stores') as $row) if (in_array((int) $row['id_merchant'], $owners, true)) $stores++;
        if ((int) Db::rows($target, 'SELECT COUNT(*) AS n FROM merchants WHERE legacy_id IS NOT NULL')[0]['n'] !== $merchants
            || (int) Db::rows($target, 'SELECT COUNT(*) AS n FROM stores WHERE legacy_id IS NOT NULL')[0]['n'] !== $stores) {
            throw new LegacyImportException('Legacy reconciliation failed: merchants and stores.');
        }
        // casse: ogni POS v1 di un negozio importato ha la sua cassa, nel negozio giusto
        $imported = [];
        foreach (Db::rows($target, 'SELECT legacy_id FROM stores WHERE legacy_id IS NOT NULL') as $row) $imported[(int) $row['legacy_id']] = true;
        $terminals = 0;
        foreach (Db::rows($source, 'SELECT id_store FROM np_pos') as $row) if (isset($imported[(int) $row['id_store']])) $terminals++;
        $linked = (int) Db::rows($target, 'SELECT COUNT(*) AS n FROM legacy_pos_terminals l JOIN pos_terminals t ON t.id=l.terminal_id JOIN stores s ON s.id=t.store_id'
            . ' AND s.merchant_id=t.merchant_id WHERE s.legacy_id IS NOT NULL')[0]['n'];
        if ($linked !== $terminals || (int) Db::rows($target, 'SELECT COUNT(*) AS n FROM legacy_pos_terminals')[0]['n'] !== $terminals) {
            throw new LegacyImportException('Legacy reconciliation failed: POS terminals.');
        }
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private static function cut($value, int $length): ?string
    {
        $text = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', (string) $value) ?? '');
        return $text === '' || $text === '0' ? null : mb_substr($text, 0, $length);
    }

    private static function city($value, array $comuni): ?string
    {
        $text = trim((string) $value);
        return ctype_digit($text) && isset($comuni[(int) $text]) ? $comuni[(int) $text] : ($text === '' ? null : $text);
    }

    private static function country($value): string
    {
        $text = trim((string) $value);
        return self::COUNTRIES[mb_strtolower($text)] ?? (preg_match('/^[A-Za-z]{2}$/D', $text) === 1 ? strtoupper($text) : mb_substr($text, 0, 10));
    }

    private static function insert(PDO $target, string $table, array $fields): int
    {
        $columns = implode(',', array_map(static fn($c) => "`$c`", array_keys($fields)));
        self::execute($target, "INSERT INTO $table ($columns) VALUES (" . implode(',', array_fill(0, count($fields), '?')) . ')', $fields);
        return (int) $target->lastInsertId();
    }

    private static function update(PDO $target, string $table, array $fields, int $id, string $key = 'id'): void
    {
        $set = implode(',', array_map(static fn($c) => "`$c`=?", array_keys($fields)));
        self::execute($target, "UPDATE $table SET $set WHERE `$key`=?", $fields + ['__key' => $id]);
    }

    private static function execute(PDO $target, string $sql, array $values): void
    {
        $query = $target->prepare($sql);
        $i = 0;
        foreach ($values as $value) $query->bindValue(++$i, $value, $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR));
        $query->execute();
        $query->closeCursor();
    }
}
