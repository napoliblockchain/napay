<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "storesettings".
 *
 * @property int $id
 * @property int $store_id
 * @property string|null $bps_storeid
 * @property string|null $website
 * @property string $defaultCurrency
 * @property int|null $invoiceExpiration
 * @property int|null $displayExpirationTimer
 * @property int|null $monitoringExpiration
 * @property string|null $speedPolicy
 * @property string|null $lightningDescriptionTemplate
 * @property int|null $paymentTolerance
 * @property int|null $anyoneCanCreateInvoice
 * @property int|null $requiresRefundEmail
 * @property string|null $checkoutType
 * @property string|null $receipt
 * @property int|null $lightningAmountInSatoshi
 * @property int|null $lightningPrivateRouteHints
 * @property int|null $onChainWithLnInvoiceFallback
 * @property int|null $redirectAutomatically
 * @property int|null $showRecommendedFee
 * @property int|null $recommendedFeeBlockTarget
 * @property string|null $defaultLang
 * @property string|null $customLogo
 * @property string|null $customCSS
 * @property string|null $htmlTitle
 * @property string|null $networkFeeMode
 * @property int|null $payJoinEnabled
 * @property int|null $lazyPaymentMethods
 * @property string|null $defaultPaymentMethod
 * @property string|null $paymentMethodCriteria
 * @property float|null $spread
 * @property string|null $preferredSource
 * @property int|null $isCustomScript
 * @property string|null $effectiveScript
 * @property string|null $derivationScheme
 * @property string|null $label
 * @property string|null $accountKeyPath
 *
 * @property Stores $store
 */
class Storesettings extends \yii\db\ActiveRecord
{
    // receipt
    public $receipt_enabled;
    public $receipt_showPayments;
    public $receipt_showQR;

    // paymenth method criteria
    public $paymentMethod_BTC_amount;
    public $paymentMethod_BTC_above;
    public $paymentMethod_LN_amount;
    public $paymentMethod_LN_above;


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'storesettings';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['store_id', 'defaultCurrency'], 'required'],
            [['store_id', 'invoiceExpiration', 'displayExpirationTimer', 'monitoringExpiration', 'paymentTolerance', 'anyoneCanCreateInvoice', 'requiresRefundEmail', 'lightningAmountInSatoshi', 'lightningPrivateRouteHints', 'onChainWithLnInvoiceFallback', 'redirectAutomatically', 'showRecommendedFee', 'recommendedFeeBlockTarget', 'payJoinEnabled', 'lazyPaymentMethods', 'isCustomScript'], 'integer'],
            [['spread'], 'number'],
            [['receipt', 'paymentMethodCriteria'], 'safe'],
            [['bps_storeid', 'lightningDescriptionTemplate', 'customLogo', 'customCSS', 'htmlTitle', 'preferredSource', 'effectiveScript', 'derivationScheme', 'label', 'accountKeyPath'], 'string', 'max' => 512],
            [['website', 'speedPolicy', 'networkFeeMode', 'defaultPaymentMethod'], 'string', 'max' => 256],
            [['defaultCurrency', 'checkoutType', 'defaultLang'], 'string', 'max' => 16],
            [['store_id'], 'exist', 'skipOnError' => true, 'targetClass' => Stores::class, 'targetAttribute' => ['store_id' => 'id']],

            ['website', 'url']
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('app', 'ID'),
            'store_id' => Yii::t('app', 'Negozio'),
            'bps_storeid' => Yii::t('app', 'ID Negozio'),
            'website' => Yii::t('app', 'Website'),
            'defaultCurrency' => Yii::t('app', 'Valuta predefinita'),
            'invoiceExpiration' => Yii::t('app', 'Tempo di attesa pagamento (sec.)'),
            'displayExpirationTimer' => Yii::t('app', 'Mostra Timer di scadenza'),
            'monitoringExpiration' => Yii::t('app', 'Monitoraggio della scadenza'),
            'speedPolicy' => Yii::t('app', 'Politica di velocità delle transazioni'),
            'lightningDescriptionTemplate' => Yii::t('app', 'Modello di descrizione Lightning'),
            'paymentTolerance' => Yii::t('app', 'Tolleranza sul pagamento'),
            'anyoneCanCreateInvoice' => Yii::t('app', 'Chiunque può creare una transazione'),
            'requiresRefundEmail' => Yii::t('app', 'Richiedi mail per il rimborso'),
            'checkoutType' => Yii::t('app', 'Modalità di pagamento'),
            'receipt' => Yii::t('app', 'Ricevuta'),
            'lightningAmountInSatoshi' => Yii::t('app', 'Importo Lightning in Satoshi'),
            'lightningPrivateRouteHints' => Yii::t('app', 'Indicazioni per le route private Lightning'),
            'onChainWithLnInvoiceFallback' => Yii::t('app', 'Conferma sulla blockchain con opzione di fallback su fattura LN'),
            'redirectAutomatically' => Yii::t('app', 'redirezione automaticamente'),
            'showRecommendedFee' => Yii::t('app', 'Visualizza commissione consigliata'),
            'recommendedFeeBlockTarget' => Yii::t('app', 'Numero di blocchi consigliato per la commissione'),
            'defaultLang' => Yii::t('app', 'Lingua predefinita'),
            'customLogo' => Yii::t('app', 'Logo personalizzato'),
            'customCSS' => Yii::t('app', 'Css personalizzato'),
            'htmlTitle' => Yii::t('app', 'Titolo Html'),
            'networkFeeMode' => Yii::t('app', 'Modalità di commissione di rete'),
            'payJoinEnabled' => Yii::t('app', 'Pay Join abilitato'),
            'lazyPaymentMethods' => Yii::t('app', 'Metodi di pagamento ritardati'),
            'defaultPaymentMethod' => Yii::t('app', 'Metodo di pagamento predefinito'),
            'paymentMethodCriteria' => Yii::t('app', 'Criteri dei metodi di pagamento'),
            'spread' => Yii::t('app', 'Spread'),
            'preferredSource' => Yii::t('app', 'Origine predefinita'),
            'isCustomScript' => Yii::t('app', 'Is Custom Script'),
            'effectiveScript' => Yii::t('app', 'Effective Script'),
            'derivationScheme' => Yii::t('app', 'Derivation Scheme'),
            'label' => Yii::t('app', 'Label'),
            'accountKeyPath' => Yii::t('app', 'Account Key Path'),

            'receipt_enabled' => Yii::t('app', 'Abilita pagina di ricevuta per transazioni saldate'),
            'receipt_showPayments' => Yii::t('app', 'Mostra l\'elenco dei pagamenti nella pagina di ricevuta'),
            'receipt_showQR' => Yii::t('app', 'Mostra il codice QR della ricevuta nella pagina di ricevuta'),

            'paymentMethod_BTC_amount' => 'BTC On chain' ,
            'paymentMethod_BTC_above' => 'Superiore',
            'paymentMethod_LN_amount' => 'BTC Off chain',
            'paymentMethod_LN_above' =>  'Superiore',
       
        ];

    }

    /**
     * Gets query for [[Store]].
     *
     * @return \yii\db\ActiveQuery|\app\models\query\StoresQuery
     */
    public function getStore()
    {
        return $this->hasOne(Stores::class, ['id' => 'store_id']);
    }

    /**
     * {@inheritdoc}
     * @return \app\models\query\StoresettingsQuery the active query used by this AR class.
     */
    public static function find()
    {
        return new \app\models\query\StoresettingsQuery(get_called_class());
    }
}
