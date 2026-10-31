<?php

declare(strict_types=1);

namespace Lunixi\Payments\Controller\Checkout;

use Lunixi\Payments\Model\Runtime;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Checkout\Model\Session;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Store\Model\StoreManagerInterface;

/** After 303, native SameSite=Lax session/ownership applies again; no payment status is trusted. */
class Returned implements HttpGetActionInterface
{
    public function __construct(
        private readonly PageFactory $pages,
        private readonly RedirectFactory $redirects,
        private readonly Runtime $runtime,
        private readonly Session $checkout,
        private readonly CustomerSession $customer,
        private readonly StoreManagerInterface $stores,
    ) {
    }

    public function execute()
    {
        try {
            $id = (int) $this->checkout->getLastRealOrder()->getId();
            $this->runtime->owned(
                $id,
                (int) $this->customer->getCustomerId(),
                $id,
                (int) $this->stores->getStore()->getId(),
            );
            return $this->pages->create();
        } catch (\Throwable) {
            return $this->redirects->create()->setPath('checkout/cart');
        }
    }
}
