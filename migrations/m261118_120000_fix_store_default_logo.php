<?php

use yii\db\Migration;

/**
 * SIP-033: the default store logo pointed to a file that never existed (logopos.png). Only rows still holding exactly that
 * default are moved to the Napay brand logo; logos chosen by merchants (v1 custom URLs) are untouched. Store-only data,
 * no remote requests: BTCPay receives the logo with the next store_update.
 */
class m261118_120000_fix_store_default_logo extends Migration
{
    private const OLD = '/bundles/site/images/logopos.png';
    private const NEW = '/bundles/site/images/logobrand-294.png';

    public function up()
    {
        $this->execute('UPDATE {{%storesettings}} SET customLogo = CONCAT(LEFT(customLogo, CHAR_LENGTH(customLogo) - :len), :new)'
            . ' WHERE customLogo LIKE :pattern AND customLogo REGEXP :shape',
            [':len' => strlen(self::OLD), ':new' => self::NEW, ':pattern' => '%' . self::OLD, ':shape' => '^https?://[^/?#]+/bundles/site/images/logopos[.]png$']);
    }

    public function down()
    {
        echo "Default logo fix is not reverted: the old file does not exist.\n";
        return false;
    }
}
