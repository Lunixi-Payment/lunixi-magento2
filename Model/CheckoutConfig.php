<?php

declare(strict_types=1);

namespace Lunixi\Payments\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\UrlInterface;

class CheckoutConfig implements ConfigProviderInterface
{
    public function __construct(private readonly UrlInterface $url)
    {
    }
    /** @return array<string,mixed> */
    public function getConfig(): array
    {
        return [
            'payment' => [
                'lunixi' => [
                    'startUrl' => $this->url->getUrl('lunixi/checkout/start', ['_secure' => true]),
                ],
            ],
        ];
    }
}
