<?php

use yii\db\Migration;

/**
 * Elenco ufficiale dei comuni ISTAT (aggiornato al 21/02/2026) per città e provincia degli indirizzi.
 * Dati dal file nel repository (data/istat), verificato per checksum: nessun download.
 * Tabella nuova e separata da np_comuni_italiani, che il bootstrap confronta con il dataset v2 importato.
 */
class m261115_100000_create_istat_comuni extends Migration
{
    private const FILE = '@app/data/istat/comuni-20260221.csv';
    private const SHA256 = 'c1a6837f0107213a93ba94f053d58de4163e3dd7cfcc8531ec1d1d7bab1152a4';
    private const ROWS = 7894;

    public function safeUp()
    {
        $file = Yii::getAlias(self::FILE);
        if (!is_file($file) || !hash_equals(self::SHA256, hash_file('sha256', $file))) throw new RuntimeException('ISTAT municipalities file missing or changed.');
        $this->createTable('{{%istat_comuni}}', [
            'codice' => 'CHAR(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
            'denominazione' => $this->string(200)->notNull(),
            'provincia' => $this->string(100)->notNull(),
            'sigla' => 'CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
            'PRIMARY KEY (codice)',
        ], 'CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci ENGINE=InnoDB');
        $this->createIndex('idx-istat-comuni-denominazione', '{{%istat_comuni}}', 'denominazione');
        $handle = fopen($file, 'r');
        if (fgetcsv($handle, 0, ',', '"', '') !== ['codice', 'denominazione', 'provincia', 'sigla']) throw new RuntimeException('ISTAT municipalities header mismatch.');
        $batch = [];
        $count = 0;
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $batch[] = $row;
            ++$count;
            if (count($batch) === 1000) { $this->db->createCommand()->batchInsert('{{%istat_comuni}}', ['codice', 'denominazione', 'provincia', 'sigla'], $batch)->execute(); $batch = []; }
        }
        fclose($handle);
        if ($batch !== []) $this->db->createCommand()->batchInsert('{{%istat_comuni}}', ['codice', 'denominazione', 'provincia', 'sigla'], $batch)->execute();
        if ($count !== self::ROWS || (int) $this->db->createCommand('SELECT COUNT(*) FROM {{%istat_comuni}}')->queryScalar() !== self::ROWS) {
            throw new RuntimeException('ISTAT municipalities count mismatch.');
        }
    }

    public function safeDown()
    {
        $this->dropTable('{{%istat_comuni}}');
    }
}
