<?php

namespace app\components\legacy;

use PDO;
use app\components\db\V2Bootstrap as Db;

/**
 * Import automatico dell'associazione dalla vecchia app Yii1 (TIP-020).
 * Ripetibile a ogni avvio: upsert per ID legacy; il legacy resta la fonte per i record importati.
 * Legacy letto in una transazione READ ONLY; tutto il lato v2 in una transazione che si chiude solo
 * se conteggi e totali coincidono.
 */
final class LegacyImporter
{
    // np_users_type: id => descrizione attesa (struttura identica tra copie e produzione)
    public const USER_TYPES = [1 => 'POS', 2 => 'Commerciante', 3 => 'Amministratore', 5 => 'Socio', 6 => 'Istituto'];
    // Istituti: funzione Covid-19 di Bolt, non migrata
    public const SKIPPED_TYPES = [6];
    // POS importati disattivati finché il modulo POS non è portato (TIP-004)
    public const INACTIVE_TYPES = [1];
    // tipo legacy => livello privilegio v2 (cariche di vertice e Segretario sono Amministratori)
    public const PRIVILEGE_LEVELS = [1 => 0, 2 => 30, 3 => 50, 5 => 0];
    public const STATUSES = ['paid' => 'paid', 'new' => 'pending', 'expired' => 'expired', 'invalid' => 'invalid'];
    // np_settings_webapp: solo valori non segreti (i segreti legacy vanno ruotati e passati da env)
    public const SETTINGS = [
        'quota_iscrizione_socio', 'quota_iscrizione_socioGiuridico',
        'progressivo_ricevute_anno', 'progressivo_ricevute_pagamenti',
        'gdpr_titolare', 'gdpr_vat', 'gdpr_address', 'gdpr_cap', 'gdpr_city', 'gdpr_country',
        'gdpr_telefono', 'gdpr_fax', 'gdpr_email', 'gdpr_dpo_denomination', 'gdpr_dpo_email', 'gdpr_dpo_telefono',
    ];
    private const MAX_FILE_BYTES = 20 * 1024 * 1024;
    private const PICTURE = '/bundles/site/images/anonymous.png';

    /** Import configured through LEGACY_DB_DSN: association data in v2 stays read-only meanwhile. */
    public static function active(): bool
    {
        return trim((string) getenv('LEGACY_DB_DSN')) !== '';
    }

    public static function run(PDO $source, PDO $target, ?string $filesDir = null): array
    {
        $lock = 'napay-legacy-import-' . substr(hash('sha256', Db::rows($target, 'SELECT DATABASE() AS name')[0]['name']), 0, 40);
        if (Db::rows($target, 'SELECT GET_LOCK(?,0) AS acquired', [$lock])[0]['acquired'] !== '1') throw new LegacyImportException('Another legacy import owns the database lock.');
        $source->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $source->exec('START TRANSACTION READ ONLY');
        $target->beginTransaction();
        try {
            $report = self::import($source, $target, $filesDir);
            self::reconcile($source, $target);
            $target->commit();
            return $report;
        } catch (\Throwable $error) {
            if ($target->inTransaction()) $target->rollBack();
            throw $error;
        } finally {
            $source->exec('ROLLBACK');
            Db::rows($target, 'SELECT RELEASE_LOCK(?) AS released', [$lock]);
        }
    }

    private static function import(PDO $source, PDO $target, ?string $filesDir): array
    {
        $types = [];
        foreach (Db::rows($source, 'SELECT id_users_type, `desc` FROM np_users_type') as $row) $types[(int) $row['id_users_type']] = trim((string) $row['desc']);
        foreach ($types as $id => $description) {
            if ((self::USER_TYPES[$id] ?? null) !== $description) throw new LegacyImportException('Unknown legacy user type.');
        }
        $privileges = [];
        foreach (array_unique(self::PRIVILEGE_LEVELS) as $level) {
            $rows = Db::rows($target, 'SELECT id FROM privileges WHERE level=?', [$level]);
            if (count($rows) !== 1) throw new LegacyImportException('v2 privileges do not match the expected levels.');
            $privileges[$level] = (int) $rows[0]['id'];
        }
        $report = [];
        // nel legacy città = ID di np_comuni_italiani (tabella vuota in v2): si importa il nome
        $comuni = [];
        foreach (Db::rows($source, 'SELECT id_comune, citta, sigla FROM np_comuni_italiani') as $row) {
            $comuni[(int) $row['id_comune']] = trim($row['citta']) . (trim($row['sigla']) !== '' ? ' (' . trim($row['sigla']) . ')' : '');
        }

        $roles = self::upsertLookup($source, $target, 'SELECT id_carica AS id, description FROM st_cariche',
            'association_roles', ['description'], static fn($r) => [trim((string) $r['description'])]);
        $fees = self::upsertLookup($source, $target, 'SELECT id_quota AS id, description, extension, importo FROM st_quote',
            'membership_fees', ['description', 'duration', 'amount'],
            static fn($r) => [trim((string) $r['description']), (int) $r['extension'], self::money($r['importo'])]);
        $methods = self::upsertLookup($source, $target, 'SELECT id_tipo_pagamento AS id, description, permission FROM st_tipo_pagamenti',
            'payment_methods', ['description', 'min_level'], static fn($r) => [trim((string) $r['description']), (int) $r['permission']]);
        $report['roles'] = count($roles);
        $report['fees'] = count($fees);
        $report['payment_methods'] = count($methods);

        // utenti e profili soci
        $users = ['created' => 0, 'updated' => 0, 'linked' => 0, 'skipped_type' => 0, 'skipped_email' => 0, 'inactive_pos' => 0];
        $seen = [];
        $userMap = [];
        foreach (Db::rows($source, 'SELECT * FROM np_users ORDER BY id_user') as $row) {
            $type = (int) $row['id_users_type'];
            if (in_array($type, self::SKIPPED_TYPES, true)) { $users['skipped_type']++; continue; }
            $email = self::email($row['email']);
            if ($email === null) { $users['skipped_email']++; continue; }
            if (isset($seen[strtolower($email)])) throw new LegacyImportException('Duplicate email in legacy users.');
            $seen[strtolower($email)] = true;
            $active = in_array($type, self::INACTIVE_TYPES, true) ? 0 : ((int) $row['status_activation_code'] === 1 ? 1 : 0);
            if (in_array($type, self::INACTIVE_TYPES, true)) $users['inactive_pos']++;
            $fields = [
                'username' => $email, 'email' => $email, 'password' => (string) $row['password'],
                'first_name' => trim((string) $row['name']), 'last_name' => trim((string) $row['surname']),
                'privilege_id' => $privileges[self::PRIVILEGE_LEVELS[$type]], 'is_merchant' => $type === 2 ? 1 : 0, 'is_active' => $active,
            ];
            $corporate = (string)$row['corporate'] === '1' ? 1 : 0;
            $profile = Db::rows($target, 'SELECT id, user_id, user_imported, is_corporate FROM member_profiles WHERE legacy_user_id=?', [$row['id_user']])[0] ?? null;
            if ($profile) {
                if ((string)$profile['is_corporate'] !== (string)$corporate) {
                    throw new LegacyImportException('Legacy member type differs from the registered profile; automatic conversion refused.');
                }
                $userId = (int) $profile['user_id'];
                if ($profile['user_imported'] === '1') {
                    self::update($target, 'users', $fields, 'id', $userId);
                    $users['updated']++;
                }
            } else {
                $existing = Db::rows($target, 'SELECT id FROM users WHERE email=? LIMIT 2', [$email]);
                if (count($existing) > 1) throw new LegacyImportException('Legacy email matches more than one v2 user.');
                if ($existing) {
                    // utente v2 già presente: si collega e basta (password e privilegio v2 restano)
                    $userId = (int) $existing[0]['id'];
                    if (Db::rows($target, 'SELECT COUNT(*) AS total FROM member_profiles WHERE user_id=?', [$userId])[0]['total'] !== '0') {
                        throw new LegacyImportException('v2 user already linked to another legacy user.');
                    }
                    $imported = 0;
                    $users['linked']++;
                } else {
                    $userId = self::insert($target, 'users', $fields + [
                        'oauth_provider' => 'SELF', 'oauth_uid' => bin2hex(random_bytes(16)), 'authKey' => bin2hex(random_bytes(16)),
                        'accessToken' => bin2hex(random_bytes(32)), 'picture' => self::PICTURE, 'activationCode' => null,
                    ]);
                    $imported = 1;
                    $users['created']++;
                }
                $profileId = self::insert($target, 'member_profiles', ['user_id' => $userId, 'legacy_user_id' => (int) $row['id_user'], 'user_imported' => $imported, 'is_corporate'=>$corporate]);
                $profile = ['id' => $profileId];
            }
            self::update($target, 'member_profiles', [
                'legacy_user_type' => $type, 'association_role_id' => $roles[(int) $row['id_carica']] ?? null,
                'denomination' => self::text($row['denomination']),
                'vat' => self::text($row['vat']), 'address' => self::text($row['address']), 'cap' => self::text($row['cap']),
                'city' => self::city($row['city'], $comuni), 'country' => self::text($row['country']),
            ], 'id', (int) $profile['id']);
            $userMap[(int) $row['id_user']] = $userId;
        }
        $report['users'] = $users;

        // pagamenti quote
        $payments = ['imported' => 0, 'skipped_user' => 0, 'removed' => 0];
        $kept = [];
        foreach (Db::rows($source, 'SELECT * FROM st_pagamenti ORDER BY id_pagamento') as $row) {
            if (!isset($userMap[(int) $row['id_user']])) { $payments['skipped_user']++; continue; }
            if (!isset($fees[(int) $row['id_quota']], $methods[(int) $row['id_tipo_pagamento']])) throw new LegacyImportException('Legacy payment references a missing fee or method.');
            self::upsert($target, 'membership_payments', (int) $row['id_pagamento'], [
                'user_id' => $userMap[(int) $row['id_user']], 'fee_id' => $fees[(int) $row['id_quota']],
                'payment_method_id' => $methods[(int) $row['id_tipo_pagamento']],
                'year' => (int) $row['anno'] ?: null, 'receipt_number' => (int) $row['progressivo'] ?: null,
                'amount' => self::money($row['importo']), 'registered_on' => $row['data_registrazione'],
                'starts_on' => $row['data_inizio'], 'expires_on' => $row['data_scadenza'],
                'btcpay_invoice_id' => self::text($row['id_invoice_bps']),
                'paypal_txn_id' => in_array(trim((string) $row['paypal_txn_id']), ['', '0'], true) ? null : trim((string) $row['paypal_txn_id']),
                'status' => self::status($row['status']),
            ]);
            $kept[] = (int) $row['id_pagamento'];
            $payments['imported']++;
        }
        $payments['removed'] = self::removeMissing($target, 'membership_payments', $kept);
        $report['payments'] = $payments;

        // verbali (file PDF dalla cartella legacy, se montata)
        $minutes = ['imported' => 0, 'files' => 0, 'files_missing' => 0, 'removed' => 0];
        $kept = [];
        foreach (Db::rows($source, 'SELECT * FROM st_verbali ORDER BY id_verbali') as $row) {
            $fields = ['meeting_date' => $row['data_verbale'], 'description' => trim((string) $row['descrizione_verbale']), 'file_name' => self::text($row['url_verbale'])];
            $content = self::file($filesDir, (string) $row['url_verbale']);
            if ($content !== null) {
                $fields += ['file_sha256' => hash('sha256', $content), 'file_content' => $content];
                $minutes['files']++;
            } elseif (Db::rows($target, 'SELECT COUNT(*) AS total FROM assembly_minutes WHERE legacy_id=? AND file_content IS NOT NULL', [$row['id_verbali']])[0]['total'] === '0') {
                // file già importato in un avvio precedente: si conserva anche se la cartella non è più montata
                $minutes['files_missing']++;
            }
            self::upsert($target, 'assembly_minutes', (int) $row['id_verbali'], $fields);
            $kept[] = (int) $row['id_verbali'];
            $minutes['imported']++;
        }
        $minutes['removed'] = self::removeMissing($target, 'assembly_minutes', $kept);
        $report['minutes'] = $minutes;

        // tabelle di riferimento: rimozioni dopo pagamenti e profili
        self::removeMissing($target, 'membership_fees', array_keys($fees));
        self::removeMissing($target, 'payment_methods', array_keys($methods));
        self::removeMissing($target, 'association_roles', array_keys($roles));

        $settings = 0;
        $values = Db::rows($source, 'SELECT setting_name, setting_value FROM np_settings_webapp WHERE setting_name IN (' . implode(',', array_fill(0, count(self::SETTINGS), '?')) . ') ORDER BY id_setting', self::SETTINGS);
        foreach ($values as $row) {
            $existing = Db::rows($target, 'SELECT id FROM settings WHERE code=? LIMIT 2', [$row['setting_name']]);
            if (count($existing) > 1) throw new LegacyImportException('Duplicate v2 setting code.');
            $value = $row['setting_name'] === 'gdpr_city' ? (string) self::city($row['setting_value'], $comuni) : (string) $row['setting_value'];
            $fields = ['description' => 'Associazione: ' . $row['setting_name'], 'code' => $row['setting_name'], 'value' => mb_substr($value, 0, 2048)];
            $existing ? self::update($target, 'settings', $fields, 'id', (int) $existing[0]['id']) : self::insert($target, 'settings', $fields);
            $settings++;
        }
        $report['settings'] = $settings;
        return $report;
    }

    /** Legacy (escluso ciò che non si importa) e v2 devono coincidere: conteggi, totali per anno/metodo/stato. */
    public static function reconcile(PDO $source, PDO $target): void
    {
        $skippedTypes = implode(',', array_map('intval', self::SKIPPED_TYPES));
        $legacyUsers = [];
        foreach (Db::rows($source, "SELECT id_user, email FROM np_users WHERE id_users_type NOT IN ($skippedTypes)") as $row) {
            if (self::email($row['email']) !== null) $legacyUsers[] = (int) $row['id_user'];
        }
        sort($legacyUsers);
        $v2Users = array_map('intval', array_column(Db::rows($target, 'SELECT legacy_user_id FROM member_profiles WHERE legacy_user_id IS NOT NULL ORDER BY legacy_user_id'), 'legacy_user_id'));
        if (array_diff($legacyUsers, $v2Users)) throw new LegacyImportException('Legacy reconciliation failed: users.');

        foreach ([['st_cariche', 'association_roles'], ['st_quote', 'membership_fees'], ['st_tipo_pagamenti', 'payment_methods'], ['st_verbali', 'assembly_minutes']] as [$legacy, $v2]) {
            $a = Db::rows($source, "SELECT COUNT(*) AS total FROM $legacy")[0]['total'];
            $b = Db::rows($target, "SELECT COUNT(*) AS total FROM $v2 WHERE legacy_id IS NOT NULL")[0]['total'];
            if ($a !== $b) throw new LegacyImportException("Legacy reconciliation failed: $v2.");
        }

        $expected = [];
        $ids = $legacyUsers ? implode(',', $legacyUsers) : '0';
        foreach (Db::rows($source, "SELECT YEAR(data_registrazione) AS y, id_tipo_pagamento AS m, status AS s, importo AS a FROM st_pagamenti WHERE id_user IN ($ids)") as $row) {
            $key = $row['y'] . '|' . $row['m'] . '|' . self::status($row['s']);
            $expected[$key] = [($expected[$key][0] ?? 0) + 1, ($expected[$key][1] ?? 0) + self::cents(self::money($row['a']))];
        }
        $actual = [];
        foreach (Db::rows($target, 'SELECT YEAR(p.registered_on) AS y, m.legacy_id AS m, p.status AS s, COUNT(*) AS n, SUM(p.amount) AS a FROM membership_payments p JOIN payment_methods m ON m.id=p.payment_method_id WHERE p.legacy_id IS NOT NULL GROUP BY 1,2,3') as $row) {
            $actual[$row['y'] . '|' . $row['m'] . '|' . $row['s']] = [(int) $row['n'], self::cents($row['a'])];
        }
        ksort($expected);
        ksort($actual);
        if ($expected !== $actual) throw new LegacyImportException('Legacy reconciliation failed: payment totals.');
    }

    private static function upsertLookup(PDO $source, PDO $target, string $sql, string $table, array $columns, callable $values): array
    {
        $map = [];
        foreach (Db::rows($source, $sql) as $row) {
            $map[(int) $row['id']] = self::upsert($target, $table, (int) $row['id'], array_combine($columns, $values($row)));
        }
        return $map;
    }

    private static function upsert(PDO $target, string $table, int $legacyId, array $fields): int
    {
        $row = Db::rows($target, "SELECT id FROM $table WHERE legacy_id=?", [$legacyId])[0] ?? null;
        if ($row) {
            self::update($target, $table, $fields, 'id', (int) $row['id']);
            return (int) $row['id'];
        }
        return self::insert($target, $table, $fields + ['legacy_id' => $legacyId]);
    }

    private static function insert(PDO $target, string $table, array $fields): int
    {
        $columns = implode(',', array_map(static fn($c) => "`$c`", array_keys($fields)));
        self::execute($target, "INSERT INTO $table ($columns) VALUES (" . implode(',', array_fill(0, count($fields), '?')) . ')', $fields);
        return (int) $target->lastInsertId();
    }

    private static function update(PDO $target, string $table, array $fields, string $key, int $id): void
    {
        $set = implode(',', array_map(static fn($c) => "`$c`=?", array_keys($fields)));
        self::execute($target, "UPDATE $table SET $set WHERE `$key`=?", $fields + ['__key' => $id]);
    }

    private static function execute(PDO $target, string $sql, array $values): void
    {
        $query = $target->prepare($sql);
        $i = 0;
        foreach ($values as $value) {
            $query->bindValue(++$i, $value, $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR));
        }
        $query->execute();
        $query->closeCursor();
    }

    private static function removeMissing(PDO $target, string $table, array $legacyIds): int
    {
        $sql = "DELETE FROM $table WHERE legacy_id IS NOT NULL" . ($legacyIds ? ' AND legacy_id NOT IN (' . implode(',', array_map('intval', $legacyIds)) . ')' : '');
        return $target->exec($sql);
    }

    private static function email($value): ?string
    {
        // le email legacy possono avere spazi/tab invisibili ai bordi
        $email = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', (string) $value);
        return $email !== '' && strlen($email) <= 60 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private static function money($value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }

    /** "870.10" -> 87010: somme esatte senza float */
    private static function cents(string $decimal): int
    {
        if (!preg_match('/^(-?)(\d+)\.(\d{2})$/D', $decimal, $m)) throw new LegacyImportException('Unexpected amount format.');
        return ($m[1] === '-' ? -1 : 1) * ((int) $m[2] * 100 + (int) $m[3]);
    }

    private static function status($value): string
    {
        $status = strtolower(trim((string) $value));
        return self::STATUSES[$status] ?? mb_substr($status, 0, 32);
    }

    private static function text($value): ?string
    {
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    private static function city($value, array $comuni): ?string
    {
        $text = self::text($value);
        return $text !== null && ctype_digit($text) && isset($comuni[(int) $text]) ? $comuni[(int) $text] : $text;
    }

    private static function file(?string $directory, string $name): ?string
    {
        if ($directory === null || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,200}$/D', $name)) return null;
        $path = $directory . '/' . $name;
        if (!is_file($path) || is_link($path) || filesize($path) > self::MAX_FILE_BYTES) return null;
        $content = file_get_contents($path);
        return $content === false ? null : $content;
    }
}
