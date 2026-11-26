<?php
namespace app\components\btcpayserver\Client;

use yii\base\Component;
use app\components\btcpayserver\Client\Pos;

class AbstractBps extends Component 
{   
    private $_host;
    private $_key;

    /**
     * Class constructor
     */
    public function __construct($host, $key)
    {
        $this->_host = $host;
        $this->_key = $key;
    }

    public function getHost(){
        return $this->_host;
    }
    public function getKey(){
        return $this->_key;
    }


    /**
     * Restituisce l'oggetto classe Store 
     */
    public function setStore(){
        return new \BTCPayServer\Client\Store($this->_host, $this->_key);
    }

    /**
     * Restituisce l'oggetto classe Pos 
     */
    public function setPos()
    {
        return new Pos($this->_host, $this->_key);
    }

    /**
     * Restituisce l'oggetto classe Invoice 
     */
    public function setInvoice()
    {
        return new \BTCPayServer\Client\Invoice($this->_host, $this->_key);
    }

    /**
     * Restituisce l'oggetto classe InvoiceCheckoutOptions 
     */
    public function setInvoiceCheckoutOptions()
    {
        return new \BTCPayServer\Client\InvoiceCheckoutOptions();
    }

    /**
     * Restituisce l'oggetto classe Util\PreciseNumber 
     */
    public function setPreciseNumber($number)
    {
        return \BTCPayServer\Util\PreciseNumber::parseString((float) $number);
    }

    /**
     * Update BTC Wallet OnChain payment methods.
     *
     * @param string $storeId
     * @param string $cryptoCode
     *
     * @param array $settings Array of data to update. e.g
     *                        [
     *                          'enabled' => true,
     *                          'derivationScheme' => 'xpub...',
     *                          'label' => 'string',
     *                          'accountKeyPath' => "abcd82a1/84'/0'/0'"
     *                        ]
     *
     */
    public function setPaymentMethodOnChain()
    {
        return new \BTCPayServer\Client\StorePaymentMethodOnChain($this->_host, $this->_key);
    }

    /**
     * Update LightningNetwork payment methods. Allows you to enable/disable
     * them, and you can set the store LN node to be internal or some external
     * node, see the Greenfield API docs for details.
     *
     * @param string $storeId
     * @param string $cryptoCode
     * @param array $settings Array of data to update. e.g
     *                        [
     *                          'enabled' => true,
     *                          'connectionString' => 'Internal Node'
     *                        ]
     *
     */
    public function setPaymentMethodLightningNetwork()
    {
        return new \BTCPayServer\Client\StorePaymentMethodLightningNetwork($this->_host, $this->_key);
    }


    /**
     * This method update the pos settings
     * 
     * @param string $posId 
     * @param array $settings
     * 
     * @return object pos
     */
    public function updatePos($posId, $settings)
    {
        $method = new \BTCPayServer\Client\Pos($this->_host, $this->_key);
        return $method->updatePos($posId, $settings);
    }

    /**
     * This method remove the pos app
     * 
     * @param string $posId 
     */
    public function removePos($posId)
    {
        $method = new \BTCPayServer\Client\Pos($this->_host, $this->_key);
        return $method->removePos($posId);
    }

    public function getMessageError($response) : Array
    {
        $decode = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $return = [
            'statusCode' => $response->getStatus(),
            'message' => $decode[0]['message'] ?? 'Not found!',
        ];
        return $return;
    }

    /**
     * Restituisce l'oggetto classe Webhook 
     */
    public function setWebhook()
    {
        return new \BTCPayServer\Client\Webhook($this->_host, $this->_key);
    }
}
