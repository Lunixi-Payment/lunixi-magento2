<?php

declare(strict_types=1);

namespace Lunixi\Payments\Block;

use Lunixi\Payments\Vendor\Lunixi\Sdk\Commerce\CheckoutSession;

class Checkout extends \Magento\Framework\View\Element\Template
{
    public function bind(CheckoutSession $session): void
    {
        $config = json_decode($session->browserConfig(), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($config)) {
            throw new \RuntimeException('A structured browser checkout configuration is required.');
        }
        $this->setData('browser_config', $config);
    }

    public function mageInitJson(): string
    {
        return json_encode(
            ['Lunixi_Payments/js/checkout' => $this->getData('browser_config')],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
        );
    }
}
