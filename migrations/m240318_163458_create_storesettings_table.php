<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%storesettings}}`.
 */
class m240318_163458_create_storesettings_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%storesettings}}', [
            'id' => $this->primaryKey(),
            'store_id' => $this->integer()->notNull(),

            // create store
            'bps_storeid' => $this->string(512)->defaultValue(NULL),
            'website' => $this->string(256)->defaultValue(NULL),
            'defaultCurrency' => $this->string(16)->notNull(), // USD, EUR
            'invoiceExpiration' => $this->integer()->defaultValue(900),
            'displayExpirationTimer' => $this->integer()->defaultValue(300),
            'monitoringExpiration' => $this->integer()->defaultValue(3600),
            'speedPolicy' => $this->string(256)->defaultValue(NULL), // 0:"HighSpeed" 1:"MediumSpeed" 6:"LowSpeed" 2:"LowMediumSpeed"
            'lightningDescriptionTemplate' => $this->string(512)->defaultValue(NULL), // The BOLT11 description of the lightning invoice in the checkout. You can use placeholders '{StoreName}', '{ItemDescription}' and '{OrderId}'.
            'paymentTolerance' => $this->integer()->defaultValue(0), // Consider an invoice fully paid, even if the payment is missing 'x' % of the full amount.
            'anyoneCanCreateInvoice' => $this->boolean()->defaultValue(0),
            'requiresRefundEmail' => $this->boolean()->defaultValue(0),
            'checkoutType' => $this->string(16)->defaultValue('V1'), // V1, V2
            'receipt' => $this->json()->defaultValue(null), // ricevuta: è un object
            'lightningAmountInSatoshi' => $this->boolean()->defaultValue(0),
            'lightningPrivateRouteHints' => $this->boolean()->defaultValue(0),
            'onChainWithLnInvoiceFallback' => $this->boolean()->defaultValue(0),
            'redirectAutomatically' => $this->boolean()->defaultValue(0),
            'showRecommendedFee' => $this->boolean()->defaultValue(true),
            'recommendedFeeBlockTarget' => $this->integer()->defaultValue(1), // numero di blocchi per calcolare la fee raccomandata
            'defaultLang' => $this->string(16)->defaultValue('it'),
            'customLogo' => $this->string(512)->defaultValue(NULL),
            'customCSS' => $this->string(512)->defaultValue(NULL),
            'htmlTitle' => $this->string(512)->defaultValue(NULL),
            'networkFeeMode' => $this->string(256)->defaultValue('Always'), // Enum: "MultiplePaymentsOnly" "Always" "Never" Check whether network fee should be added to the invoice if on-chain payment is used
            'payJoinEnabled' => $this->boolean()->defaultValue(0),
            'lazyPaymentMethods' => $this->boolean()->defaultValue(0),
            'defaultPaymentMethod' => $this->string(256)->defaultValue('BTC'), // BTC_LightningNetwork to specify Lightning to be the default or BTC_OnChain/ BTC for on-chain to be the default.
            'paymentMethodCriteria' => $this->json()->defaultValue(null), // Array of objects [{paymentMethod: 'BTC_like', currencyCode: EUR, amount: >=0, above: 0/true (if the criterion is above or below the amount)}]

            // store rate settings
            'spread' => $this->float()->defaultValue(0),
            'preferredSource' => $this->string(512)->defaultValue(NULL),
            'isCustomScript' => $this->boolean()->defaultValue(0),
            'effectiveScript' => $this->string(512)->defaultValue(NULL), //  the custom script used to calculate a currency pair's exchange rate.

            // derivation scheme 
            'derivationScheme' => $this->string(512)->defaultValue(NULL),
            'label' => $this->string(512)->defaultValue(NULL),
            'accountKeyPath' => $this->string(512)->defaultValue(NULL),

        ]);

        $this->createIndex('{{%idx-storesettings-store_id}}', '{{%storesettings}}', 'store_id');
        $this->addForeignKey(
            '{{%fk-storesettings-store_id}}',
            '{{%storesettings}}',
            'store_id',
            '{{%stores}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropTable('{{%storesettings}}');
    }
}
