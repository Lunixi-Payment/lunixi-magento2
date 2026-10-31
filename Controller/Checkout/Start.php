<?php

declare(strict_types=1);

namespace Lunixi\Payments\Controller\Checkout;

use Lunixi\Payments\Model\Runtime;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Checkout\Model\Session;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Message\ManagerInterface;

class Start implements HttpPostActionInterface
{
    public function __construct(
        private readonly Runtime $runtime,
        private readonly \Magento\Framework\App\Request\Http $request,
        private readonly RedirectFactory $redirects,
        private readonly Session $checkout,
        private readonly CustomerSession $customer,
        private readonly \Magento\Store\Model\StoreManagerInterface $stores,
        private readonly Validator $formKey,
        private readonly ManagerInterface $messages,
    ) {
    }
    public function execute()
    {
        $result = $this->redirects->create();
        if (!$this->request->isSecure() || !$this->formKey->validate($this->request)) {
            return $result->setPath('checkout/cart');
        }
        $id = (int) $this->checkout->getLastRealOrder()->getId();
        try {
            $this->runtime->owned(
                $id,
                (int) $this->customer->getCustomerId(),
                $id,
                (int) $this->stores->getStore()->getId(),
            );
            $this->runtime->start($id);
            return $result->setPath('lunixi/checkout/pay', ['order_id' => $id, '_secure' => true]);
        } catch (\Throwable) {
            $this->messages->addErrorMessage(
                __(
                    'Payment is awaiting verification. Please contact the merchant before attempting another charge.',
                ),
            );
            return $result->setPath('lunixi/checkout/pay', ['order_id' => $id, '_secure' => true]);
        }
    }
}
