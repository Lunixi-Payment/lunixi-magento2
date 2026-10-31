<?php

declare(strict_types=1);

namespace Lunixi\Payments\Controller\Checkout;

use Lunixi\Payments\Model\Runtime;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Checkout\Model\Session;
use Magento\Customer\Model\Session as CustomerSession;

class Pay implements HttpGetActionInterface
{
    public function __construct(
        private readonly Runtime $runtime,
        private readonly \Magento\Framework\App\Request\Http $request,
        private readonly \Magento\Framework\App\Response\Http $response,
        private readonly PageFactory $pages,
        private readonly RedirectFactory $redirects,
        private readonly Session $checkout,
        private readonly CustomerSession $customer,
        private readonly \Magento\Store\Model\StoreManagerInterface $stores,
    ) {
    }
    public function execute()
    {
        $this->response
            ->setHeader('Cache-Control', 'no-store', true)
            ->setHeader('Referrer-Policy', 'no-referrer', true);
        $id = filter_var($this->request->getParam('order_id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        try {
            if (!$this->request->isSecure() || !$id) {
                throw new \RuntimeException('HTTPS order required.');
            }
            $this->runtime->owned(
                $id,
                (int) $this->customer->getCustomerId(),
                (int) $this->checkout->getLastRealOrder()->getId(),
                (int) $this->stores->getStore()->getId(),
            );
            $context = $this->runtime->context($id);
            $session = $this->runtime->browser($id);
            if ($context['hosted']) {
                return $this->redirects->create()->setUrl($session->hostedUrl());
            }
            $page = $this->pages->create();
            $block = $page->getLayout()->getBlock('lunixi.checkout');
            if (!($block instanceof \Lunixi\Payments\Block\Checkout)) {
                throw new \RuntimeException('Native checkout view missing.');
            }
            $block->bind($session);
            return $page;
        } catch (\Throwable) {
            $page = $this->pages->create();
            return $page;
        }
    }
}
