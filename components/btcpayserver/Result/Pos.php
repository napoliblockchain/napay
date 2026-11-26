<?php

declare(strict_types=1);

namespace app\components\btcpayserver\Result;

use \BTCPayServer\Result\AbstractResult;


class Pos extends AbstractResult
{
    public function getName(): string
    {
        $data = $this->getData();
        return $data['appName'];
    }

    public function getTitle(): string
    {
        $data = $this->getData();
        return $data['title'];
    }

    public function getDescription(): string
    {
        $data = $this->getData();
        return $data['description'];
    }

    public function getTemplate(): string
    {
        $data = $this->getData();
        return $data['template'];
    }

    public function getDefaultView(): string
    {
        $data = $this->getData();
        return $data['defaultView'];
    }

    public function getCurrency(): string
    {
        $data = $this->getData();
        return $data['currency'];
    }

    public function showCustomAmount(): bool
    {
        $data = $this->getData();
        return $data['showCustomAmount'];
    }

    public function showDiscount(): bool
    {
        $data = $this->getData();
        return $data['showDiscount'];
    }

    public function enableTips(): bool
    {
        $data = $this->getData();
        return $data['enableTips'];
    }

    public function customAmountPayButtonText(): string
    {
        $data = $this->getData();
        return $data['customAmountPayButtonText'];
    }

    public function fixedAmountPayButtonText(): string
    {
        $data = $this->getData();
        return $data['fixedAmountPayButtonText'];
    }

    public function tipText(): string
    {
        $data = $this->getData();
        return $data['tipText'];
    }

    public function customCSSLink(): string
    {
        $data = $this->getData();
        return $data['customCSSLink'];
    }

    public function embeddedCSS(): string
    {
        $data = $this->getData();
        return $data['embeddedCSS'];
    }

    public function notificationUrl(): string
    {
        $data = $this->getData();
        return $data['notificationUrl'];
    }

    public function redirectUrl(): string
    {
        $data = $this->getData();
        return $data['redirectUrl'];
    }

    public function redirectAutomatically(): bool
    {
        $data = $this->getData();
        return $data['redirectAutomatically'];
    }

    public function requiresRefundEmail(): bool
    {
        $data = $this->getData();
        return $data['requiresRefundEmail'];
    }

    public function checkoutType(): string
    {
        $data = $this->getData();
        return $data['checkoutType'];
    }

    

    public function getId(): string
    {
        $data = $this->getData();
        return $data['id'];
    }
}
