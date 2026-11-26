<?php

declare(strict_types=1);

namespace app\components\btcpayserver\Client;

use app\components\btcpayserver\Result\Pos as ResultPos;
use \BTCPayServer\Client\AbstractClient;

class Pos extends AbstractClient
{
   
    public function createPos(string $storeId, array $params): ResultPos 
    {
        $url = $this->getApiUrl() . 'stores/' . urlencode($storeId) . '/apps/pos';
        $headers = $this->getRequestHeaders();
        $method = 'POST';

        $body = json_encode(
            [
                "appName" => $params["appName"],
                "title" => $params["title"],
                "description" => $params["description"],
                "template" => $params["template"],
                "defaultView" => $params["defaultView"],
                "currency" => $params["currency"],
                "showCustomAmount" => $params['showCustomAmount'],
                "showDiscount" => $params['showDiscount'],
                "enableTips" => $params['enableTips'],
                "customAmountPayButtonText" => $params["customAmountPayButtonText"],
                "fixedAmountPayButtonText" => $params["fixedAmountPayButtonText"],
                "tipText" => $params["tipText"],
                "customCSSLink" => $params["customCSSLink"],
                "embeddedCSS" => $params["embeddedCSS"],
                "notificationUrl" => $params["notificationUrl"],
                "redirectUrl" => $params["redirectUrl"],
                "redirectAutomatically" => $params['redirectAutomatically'],
                "requiresRefundEmail" => $params['requiresRefundEmail'],
                "checkoutType" => $params["checkoutType"],
                "formId" => $params["formId"]
            ],
            JSON_THROW_ON_ERROR
        );

        $response = $this->getHttpClient()->request($method, $url, $headers, $body);

        if ($response->getStatus() === 200) {
            return new ResultPos(json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR));
        } else {
            throw $this->getExceptionByStatusCode($method, $url, $response);
        }
    }


    /**
     * This method update the pos settings
     * 
     * @param string $posId 
     * @param array $settings
     * 
     * @return object pos
     */
    public function updatePos(string $posId, array $settings): ResultPos
    {
        $url = $this->getApiUrl() . 'apps/pos/' . urlencode($posId);
        $headers = $this->getRequestHeaders();
        $method = 'PUT';

        $response = $this->getHttpClient()->request($method, $url, $headers, json_encode($settings));

        if ($response->getStatus() === 200) {
            return new ResultPos(json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR));
        } else {
            throw $this->getExceptionByStatusCode($method, $url, $response);
        }
    }

    /**
     * This method remove the pos 
     * 
     * @param string $posId 
     */
    public function removePos(string $posId): bool
    {
        $url = $this->getApiUrl() . 'apps/' . urlencode($posId);
        $headers = $this->getRequestHeaders();
        $method = 'DELETE';

        $response = $this->getHttpClient()->request($method, $url, $headers);

        if ($response->getStatus() === 200) {
            return true;
        } else {
            throw $this->getExceptionByStatusCode($method, $url, $response);
        }
    }

   
    public function getPos(string $posId): ResultPos
    {
        $url = $this->getApiUrl() . 'apps/pos/' . urlencode($posId);
        $headers = $this->getRequestHeaders();
        $method = 'GET';
        $response = $this->getHttpClient()->request($method, $url, $headers);

        if ($response->getStatus() === 200) {
            return new ResultPos(json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR));
        } else {
            throw $this->getExceptionByStatusCode($method, $url, $response);
        }
    }

    /**
     * @return \BTCPayServer\Result\Pos[]
     */
    public function getAllPos(string $storeId): array
    {
        $url = $this->getApiUrl() . 'stores/' . urlencode($storeId) . '/apps';
        $headers = $this->getRequestHeaders();
        $method = 'GET';
        $response = $this->getHttpClient()->request($method, $url, $headers);

        if ($response->getStatus() === 200) {
            $r = [];
            $data = json_decode($response->getBody(), true);
            foreach ($data as $item) {
                $item = new ResultPos($item);
                $r[] = $item;
            }
            return $r;
        } else {
            throw $this->getExceptionByStatusCode($method, $url, $response);
        }
    }
}
