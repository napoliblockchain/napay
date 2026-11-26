<?php

use yii\db\Migration;

/**
 * SIP-030 punto 3: wallet TTS per negozio, come il wallet BTC. Ogni negozio riceve gli incassi TTS dei suoi POS
 * sul proprio indirizzo (lo stesso indirizzo può servire più negozi). L'indirizzo per merchant di SIP-021 viene
 * copiato su tutti i negozi del merchant, poi la tabella per merchant è rimossa. Ripresa sicura dopo un'interruzione.
 */
class m261116_100000_create_store_tts_wallets extends Migration
{
    private const TABLE = 'store_tts_wallets';
    private const LEGACY = 'merchant_tts_wallets';
    private const COLUMNS = [
        'store_id' => 'int', 'merchant_id' => 'int', 'address' => 'char(42)', 'updated_by' => 'int', 'updated_at' => 'bigint',
    ];

    public function up()
    {
        if ($this->db->driverName !== 'mysql' || !preg_match('/^8\.4\./', $this->db->getServerVersion())) {
            throw new RuntimeException('Store TTS wallets require MySQL 8.4.');
        }
        $table = $this->db->quoteTableName($this->table(self::TABLE));
        if ($this->db->schema->getTableSchema($this->table(self::TABLE), true) === null) {
            $ascii = 'CHARACTER SET ascii COLLATE ascii_bin';
            $this->execute("CREATE TABLE $table (
                `store_id` INT NOT NULL, `merchant_id` INT NOT NULL, `address` CHAR(42) $ascii NOT NULL,
                `updated_by` INT NOT NULL, `updated_at` BIGINT NOT NULL,
                PRIMARY KEY (`store_id`),
                KEY `idx-store-tts-wallets-merchant` (`store_id`, `merchant_id`),
                KEY `idx-store-tts-wallets-updater` (`updated_by`),
                CONSTRAINT `fk-store-tts-wallets-store` FOREIGN KEY (`store_id`, `merchant_id`) REFERENCES " . $this->db->quoteTableName($this->table('stores')) . " (`id`, `merchant_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT `fk-store-tts-wallets-updater` FOREIGN KEY (`updated_by`) REFERENCES " . $this->db->quoteTableName($this->table('users')) . " (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT `ck-store-tts-wallets-address` CHECK (REGEXP_LIKE(address, '^0x[0-9a-fA-F]{40}$', 'c')),
                CONSTRAINT `ck-store-tts-wallets-time` CHECK (updated_at >= 0)
            ) ENGINE=InnoDB CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_bin");
        }
        // ripresa: solo la definizione attesa, nessuna riparazione automatica
        $columns = $this->db->createCommand('SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t ORDER BY ORDINAL_POSITION',
            [':t' => $this->table(self::TABLE)])->queryAll();
        if (array_combine(array_column($columns, 'COLUMN_NAME'), array_column($columns, 'DATA_TYPE')) !== array_map(fn($type) => explode('(', $type)[0], self::COLUMNS)) {
            throw new RuntimeException('Store TTS wallets schema is incompatible; no automatic repair permitted.');
        }
        if ($this->db->schema->getTableSchema($this->table(self::LEGACY), true) !== null) {
            // indirizzo del merchant su tutti i suoi negozi; un negozio già impostato non viene toccato
            $this->execute("INSERT IGNORE INTO $table (store_id, merchant_id, address, updated_by, updated_at)
                SELECT s.id, s.merchant_id, w.address, w.updated_by, w.updated_at
                FROM " . $this->db->quoteTableName($this->table(self::LEGACY)) . ' w JOIN ' . $this->db->quoteTableName($this->table('stores')) . ' s ON s.merchant_id = w.merchant_id');
            $missing = (int) $this->db->createCommand('SELECT COUNT(*) FROM ' . $this->db->quoteTableName($this->table(self::LEGACY)) . ' w JOIN '
                . $this->db->quoteTableName($this->table('stores')) . " s ON s.merchant_id = w.merchant_id LEFT JOIN $table n ON n.store_id = s.id WHERE n.store_id IS NULL")->queryScalar();
            if ($missing !== 0) throw new RuntimeException('Store TTS wallets copy incomplete.');
            $this->dropTable($this->table(self::LEGACY));
        }
        $this->db->schema->refresh();
    }

    public function down()
    {
        echo "Store TTS wallets rollback requires an approved backup restore.\n";
        return false;
    }

    private function table(string $name): string { return $this->db->tablePrefix . $name; }
}
