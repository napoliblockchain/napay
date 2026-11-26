<?php

use yii\db\Migration;

/**
 * SIP-040 fase A2: registro degli eventi di consenso, in sola aggiunta (l'utente web ha solo SELECT e INSERT).
 * Pregresso: un evento 'backfill' per ogni voce con valore 1 già registrata, con la sua data e la versione se nota;
 * valori 0 e righe mancanti non generano eventi (nessun consenso inventato). Utente eliminato -> user_id NULL.
 */
class m261126_130000_create_consent_events extends Migration
{
    public function up()
    {
        if ($this->db->schema->getTableSchema('{{%consent_events}}', true) !== null) return;
        $this->execute('CREATE TABLE {{%consent_events}} ('
            . 'id BIGINT NOT NULL AUTO_INCREMENT,'
            . 'user_id INT NULL,'
            . "item VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "action VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . 'document_id INT NULL,'
            . "source VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . 'created_at BIGINT NOT NULL,'
            . 'PRIMARY KEY (id),'
            . 'KEY `idx-consent-events-user` (user_id, id),'
            . 'CONSTRAINT `fk-consent-events-user` FOREIGN KEY (user_id) REFERENCES {{%users}} (id) ON DELETE SET NULL,'
            . 'CONSTRAINT `fk-consent-events-document` FOREIGN KEY (document_id) REFERENCES {{%legal_documents}} (id) ON DELETE RESTRICT,'
            . "CONSTRAINT `ck-consent-events-item` CHECK (item IN ('statute','privacy','terms','marketing')),"
            . "CONSTRAINT `ck-consent-events-action` CHECK (action IN ('given','refused','withdrawn')),"
            . "CONSTRAINT `ck-consent-events-source` CHECK (source IN ('signup','profile','reaccept','backfill')),"
            . 'CONSTRAINT `ck-consent-events-time` CHECK (created_at >= 0)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        foreach ([
            ['statute', 'consenso_statuto', 'timestamp_statuto', 'NULL'],
            ['privacy', 'consenso_privacy', 'timestamp_privacy', 'privacy_document_id'],
            ['terms', 'consenso_condizioni', 'timestamp_condizioni', 'terms_document_id'],
            ['marketing', 'consenso_marketing', 'timestamp_marketing', 'NULL'],
        ] as [$item, $flag, $time, $document]) {
            $this->execute("INSERT INTO {{%consent_events}} (user_id, item, action, document_id, source, created_at)
                SELECT user_id, '$item', 'given', $document, 'backfill', GREATEST(COALESCE($time, 0), 0)
                FROM {{%user_consensus}} WHERE $flag = 1 ORDER BY id");
        }
    }

    public function down()
    {
        $this->dropTable('{{%consent_events}}');
    }
}
