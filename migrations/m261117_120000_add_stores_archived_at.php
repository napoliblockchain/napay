<?php

use yii\db\Migration;

/**
 * SIP-022 passo 3b: negozio eliminato = archiviato (`archived_at`), mai cancellato: terminali, impostazioni, pagamenti e
 * collegamenti BTCPay lo referenziano. Nascosto da elenchi e selettori, terminali disabilitati, storico dei pagamenti visibile.
 */
class m261117_120000_add_stores_archived_at extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%stores}}', 'archived_at', $this->bigInteger()->null());
    }

    public function safeDown()
    {
        echo "Store archive rollback requires an approved backup restore.\n";
        return false;
    }
}
