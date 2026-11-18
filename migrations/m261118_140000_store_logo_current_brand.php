<?php

use yii\db\Migration;

/**
 * SIP-033: the default store logo becomes the current Napay brand (icon and wordmark, web/brand/logo-wordmark.png).
 * Only rows still holding one of the previous Napay defaults are moved; logos of merchants are untouched. Store-only
 * data, no remote requests: BTCPay receives the logo with the next store_update.
 */
class m261118_140000_store_logo_current_brand extends Migration
{
    private const OLD = ['/bundles/site/images/logopos.png', '/bundles/site/images/logobrand-294.png'];
    private const NEW = '/brand/logo-wordmark.png';

    public function up()
    {
        foreach (self::OLD as $old) {
            $this->execute('UPDATE {{%storesettings}} SET customLogo = CONCAT(LEFT(customLogo, CHAR_LENGTH(customLogo) - :len), :new)'
                . ' WHERE customLogo LIKE :pattern AND customLogo REGEXP :shape',
                [':len' => strlen($old), ':new' => self::NEW, ':pattern' => '%' . $old, ':shape' => '^https?://[^/?#]+' . preg_quote($old, null) . '$']);
        }
    }

    public function down()
    {
        echo "Default logo update is not reverted.\n";
        return false;
    }
}
