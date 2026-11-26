<?php

use yii\db\Migration;

/**
 * SIP-030 step 5: BTCPay stores of v1 stores ("bridge", BTCPAY_STORES_API_KEY) and the POS apps created there for their
 * terminals, so the v2 POS can take BTC before the store moves to the per-merchant model (SIP-022).
 * btcpay_bridge_stores: v1 store on the bridge + state of the Napay webhook; btcpay_bridge_pos_links: one POS app per
 * terminal, created once (marker in the app name, unknown outcome = GET-only reconciliation). No secrets stored.
 */
class m261119_100000_create_btcpay_bridge_links extends Migration
{
    public function up()
    {
        $ascii = 'CHARACTER SET ascii COLLATE ascii_bin';
        if ($this->db->schema->getTableSchema('{{%btcpay_bridge_stores}}', true) === null) {
            $this->execute('CREATE TABLE {{%btcpay_bridge_stores}} ('
                . 'id INT NOT NULL AUTO_INCREMENT,'
                . "instance CHAR(64) $ascii NOT NULL,"
                . 'merchant_id INT NOT NULL,'
                . 'store_id INT NOT NULL,'
                . "remote_store_id VARCHAR(64) $ascii NOT NULL,"
                . "webhook_state VARCHAR(16) $ascii NOT NULL DEFAULT 'pending',"
                . "last_error_code VARCHAR(64) $ascii NULL,"
                . 'attempts SMALLINT NOT NULL DEFAULT 0,'
                . 'next_attempt_at BIGINT NOT NULL DEFAULT 0,'
                . 'created_at BIGINT NOT NULL,'
                . 'updated_at BIGINT NOT NULL,'
                . 'PRIMARY KEY (id),'
                . 'UNIQUE KEY `uq-btcpay-bridge-stores-store` (instance, store_id),'
                . 'UNIQUE KEY `uq-btcpay-bridge-stores-remote` (instance, remote_store_id),'
                . 'UNIQUE KEY `uq-btcpay-bridge-stores-scope` (id, instance, merchant_id, store_id),'
                . 'KEY `idx-btcpay-bridge-stores-napay` (store_id, merchant_id),'
                . 'CONSTRAINT `fk-btcpay-bridge-stores-store` FOREIGN KEY (store_id, merchant_id) REFERENCES {{%stores}} (id, merchant_id),'
                . "CONSTRAINT `ck-btcpay-bridge-stores-instance` CHECK (REGEXP_LIKE(instance, '^[0-9a-f]{64}$', 'c')),"
                . "CONSTRAINT `ck-btcpay-bridge-stores-remote` CHECK (REGEXP_LIKE(remote_store_id, '^[A-Za-z0-9_-]{1,64}$', 'c')),"
                . "CONSTRAINT `ck-btcpay-bridge-stores-webhook` CHECK (webhook_state IN ('pending','synced','failed','disabled')),"
                . "CONSTRAINT `ck-btcpay-bridge-stores-error` CHECK (last_error_code IS NULL OR REGEXP_LIKE(last_error_code, '^[a-z0-9_]{1,64}$', 'c')),"
                . 'CONSTRAINT `ck-btcpay-bridge-stores-time` CHECK (attempts >= 0 AND next_attempt_at >= 0 AND created_at >= 0 AND updated_at >= created_at)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin');
        }
        if ($this->db->schema->getTableSchema('{{%btcpay_bridge_pos_links}}', true) === null) {
            $this->execute('CREATE TABLE {{%btcpay_bridge_pos_links}} ('
                . 'id INT NOT NULL AUTO_INCREMENT,'
                . "instance CHAR(64) $ascii NOT NULL,"
                . 'bridge_store_id INT NOT NULL,'
                . 'merchant_id INT NOT NULL,'
                . 'store_id INT NOT NULL,'
                . 'terminal_id INT NOT NULL,'
                . "marker CHAR(32) $ascii NOT NULL,"
                . "remote_app_id VARCHAR(64) $ascii NULL,"
                . "currency CHAR(3) $ascii NULL,"
                . "state VARCHAR(24) $ascii NOT NULL DEFAULT 'pending',"
                . "last_error_code VARCHAR(64) $ascii NULL,"
                . 'attempts SMALLINT NOT NULL DEFAULT 0,'
                . 'next_attempt_at BIGINT NOT NULL DEFAULT 0,'
                . 'creating_at BIGINT NULL,'
                . 'created_at BIGINT NOT NULL,'
                . 'updated_at BIGINT NOT NULL,'
                . 'PRIMARY KEY (id),'
                . 'UNIQUE KEY `uq-btcpay-bridge-pos-terminal` (instance, terminal_id),'
                . 'UNIQUE KEY `uq-btcpay-bridge-pos-remote` (instance, remote_app_id),'
                . 'UNIQUE KEY `uq-btcpay-bridge-pos-marker` (marker),'
                . 'KEY `idx-btcpay-bridge-pos-store` (bridge_store_id, instance, merchant_id, store_id),'
                . 'KEY `idx-btcpay-bridge-pos-napay` (terminal_id, store_id, merchant_id),'
                . 'CONSTRAINT `fk-btcpay-bridge-pos-store` FOREIGN KEY (bridge_store_id, instance, merchant_id, store_id)'
                . ' REFERENCES {{%btcpay_bridge_stores}} (id, instance, merchant_id, store_id),'
                . 'CONSTRAINT `fk-btcpay-bridge-pos-terminal` FOREIGN KEY (terminal_id, store_id, merchant_id) REFERENCES {{%pos_terminals}} (id, store_id, merchant_id),'
                . "CONSTRAINT `ck-btcpay-bridge-pos-instance` CHECK (REGEXP_LIKE(instance, '^[0-9a-f]{64}$', 'c')),"
                . "CONSTRAINT `ck-btcpay-bridge-pos-marker` CHECK (REGEXP_LIKE(marker, '^[0-9a-f]{32}$', 'c')),"
                . "CONSTRAINT `ck-btcpay-bridge-pos-remote` CHECK (remote_app_id IS NULL OR REGEXP_LIKE(remote_app_id, '^[A-Za-z0-9_-]{1,64}$', 'c')),"
                . "CONSTRAINT `ck-btcpay-bridge-pos-currency` CHECK (currency IS NULL OR REGEXP_LIKE(currency, '^[A-Z]{3}$', 'c')),"
                . "CONSTRAINT `ck-btcpay-bridge-pos-state` CHECK (state IN ('pending','creating','reconcile_required','synced','failed')),"
                . "CONSTRAINT `ck-btcpay-bridge-pos-synced` CHECK (state <> 'synced' OR remote_app_id IS NOT NULL),"
                . "CONSTRAINT `ck-btcpay-bridge-pos-creating` CHECK (state NOT IN ('creating','reconcile_required') OR creating_at IS NOT NULL),"
                . "CONSTRAINT `ck-btcpay-bridge-pos-error` CHECK (last_error_code IS NULL OR REGEXP_LIKE(last_error_code, '^[a-z0-9_]{1,64}$', 'c')),"
                . 'CONSTRAINT `ck-btcpay-bridge-pos-time` CHECK (attempts >= 0 AND next_attempt_at >= 0 AND created_at >= 0 AND updated_at >= created_at)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_bin');
        }
        $this->db->schema->refresh();
    }

    public function down()
    {
        echo "BTCPay bridge links rollback requires an approved backup restore.\n";
        return false;
    }
}
