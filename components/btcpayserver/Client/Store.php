<?php
namespace app\components\btcpayserver\Client;

use ReflectionMethod;

class Store extends AbstractBps 
{
    private $_host;
    private $_key;

    private $ApiUrl;
    private $Headers;
    private $HttpClient;

    /**
     * Class constructor
     */
    public function __construct($host, $key)
    {
        $this->_host = $host;
        $this->_key = $key;
        
        // enable invoking of a protected method
        $this->ApiUrl = $this->getMethod('\BTCPayServer\Client\Store', 'getApiUrl');
        $this->Headers = $this->getMethod('\BTCPayServer\Client\Store', 'getRequestHeaders');
        $this->HttpClient = $this->getMethod('\BTCPayServer\Client\Store', 'getHttpClient');
    }


    /**
     * questa funzione rende richiamabile un metodo privato della classe $class
     * 
     * @return Restituisce la chiamata al metodo
     */
    private function getMethod($class, $method)
    {
        // enable invoking of a protected method
        $class = new ReflectionMethod($class, $method);
        $class->setAccessible(true);

        // richiamo la classe dello store
        $store = new \BTCPayServer\Client\Store($this->_host, $this->_key);

        return $class->invoke($store);
    }


    public function getApiUrl(){
        return $this->ApiUrl;
    }

    public function getRequestHeaders()
    {
        return $this->Headers;
    }

    public function getHttpClient()
    {
        return $this->HttpClient;
    }
   
}
