<?php
namespace app\components;

use app\components\btcpayserver\Client\AbstractBps;
use app\components\btcpayserver\Client\Store;

use BTCPayServer\Result\Store as ResultStore;

class BTCPayServer extends AbstractBps 
{
    /**
     * This method View rate settings of the specified store
     * 
     * @param string $storeId 
     * @param string $currencyPair 
     * 
     * @return object store
     */
    public function getRates(string $storeId, string $currencyPair)
    {
        $client = new Store($this->getHost(), $this->getKey());

        $url = $client->getApiUrl() . 'stores/' . urlencode($storeId) . '/rates?currencyPair=' . $currencyPair;
        $headers = $client->getRequestHeaders();
        $method = 'GET';
        
        $response = $client->getHttpClient()->request($method, $url, $headers);

        if ($response->getStatus() === 200) {
            return new ResultStore(json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR));
        } else {
            return $this->getMessageError($response);
        }
        
    }

    /**
     * This method update the rates store settings
     * 
     * @param string $storeId 
     * 
     * @return object store
     */
    public function updateRates(string $storeId, array $settings)
    {
        $client = new Store($this->getHost(), $this->getKey());
       
        $url = $client->getApiUrl() . 'stores/' . urlencode($storeId) . '/rates/configuration';
        $headers = $client->getRequestHeaders();
        $method = 'PUT';

        $response = $client->getHttpClient()->request($method, $url, $headers, json_encode($settings));

        if ($response->getStatus() === 200) {
            return new ResultStore(json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR));
        } else {
            return $this->getMessageError($response);
        }
    }

    /**
     * This method update the store settings
     * 
     * @param string $storeId 
     * @param array $settings
     * 
     * @return object store
     */
    public function updateStore(string $storeId, array $settings)
    {
        $client = new Store($this->getHost(), $this->getKey());

        $url = $client->getApiUrl() . 'stores/' . urlencode($storeId);
        $headers = $client->getRequestHeaders();
        $method = 'PUT';

        $response = $client->getHttpClient()->request($method, $url, $headers, json_encode($settings));
        // echo "<pre>" . print_r($response->getBody(), true) . "</pre>";
        // exit;

        if ($response->getStatus() === 200) {
            return new ResultStore(json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR));
        } else {
            return $this->getMessageError($response);
        }
    }
}
