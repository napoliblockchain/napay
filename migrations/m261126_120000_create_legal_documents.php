<?php

use yii\db\Migration;

/**
 * SIP-040 fase A: documenti legali versionati (informativa privacy, condizioni di utilizzo, cookie policy) e versione
 * accettata nei consensi. Le versioni pubblicate sono immutabili (model e test); NULL nei consensi = accettazione
 * registrata prima del versionamento (righe legacy), mai una versione inventata.
 */
class m261126_120000_create_legal_documents extends Migration
{
    public function up()
    {
        if ($this->db->schema->getTableSchema('{{%legal_documents}}', true) === null) {
            $this->execute('CREATE TABLE {{%legal_documents}} ('
                . 'id INT NOT NULL AUTO_INCREMENT,'
                . "type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                . 'version INT NOT NULL,'
                . 'title VARCHAR(200) NOT NULL,'
                . 'body MEDIUMTEXT NOT NULL,'
                . "status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',"
                . 'created_by INT NULL,'
                . 'created_at BIGINT NOT NULL,'
                . 'updated_at BIGINT NOT NULL,'
                . 'published_by INT NULL,'
                . 'published_at BIGINT NULL,'
                . 'PRIMARY KEY (id),'
                . 'UNIQUE KEY `uq-legal-documents-version` (type, version),'
                . 'KEY `idx-legal-documents-current` (type, status, version),'
                . 'CONSTRAINT `fk-legal-documents-created-by` FOREIGN KEY (created_by) REFERENCES {{%users}} (id) ON DELETE SET NULL,'
                . 'CONSTRAINT `fk-legal-documents-published-by` FOREIGN KEY (published_by) REFERENCES {{%users}} (id) ON DELETE SET NULL,'
                . "CONSTRAINT `ck-legal-documents-type` CHECK (type IN ('privacy','terms','cookies')),"
                . "CONSTRAINT `ck-legal-documents-status` CHECK (status IN ('draft','published')),"
                . "CONSTRAINT `ck-legal-documents-published` CHECK ((status = 'draft' AND published_at IS NULL) OR (status = 'published' AND published_at IS NOT NULL)),"
                . 'CONSTRAINT `ck-legal-documents-version` CHECK (version >= 1),'
                . 'CONSTRAINT `ck-legal-documents-time` CHECK (created_at >= 0 AND updated_at >= created_at)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }
        $consensus = $this->db->schema->getTableSchema('{{%user_consensus}}', true);
        foreach (['privacy_document_id' => 'privacy', 'terms_document_id' => 'terms'] as $column => $name) {
            if ($consensus->getColumn($column) === null) {
                $this->addColumn('{{%user_consensus}}', $column, $this->integer()->null());
                $this->addForeignKey("fk-user-consensus-$name-document", '{{%user_consensus}}', $column, '{{%legal_documents}}', 'id', 'RESTRICT', 'CASCADE');
            }
        }
    }

    public function down()
    {
        foreach (['privacy_document_id' => 'privacy', 'terms_document_id' => 'terms'] as $column => $name) {
            $this->dropForeignKey("fk-user-consensus-$name-document", '{{%user_consensus}}');
            $this->dropColumn('{{%user_consensus}}', $column);
        }
        $this->dropTable('{{%legal_documents}}');
    }
}
