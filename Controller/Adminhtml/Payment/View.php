<?php

declare(strict_types=1);

namespace Lunixi\Payments\Controller\Adminhtml\Payment;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;
use Lunixi\Payments\Model\NativeOrders;

class View extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Lunixi_Payments::payment';
    public function __construct(
        Context $context,
        private readonly PageFactory $pages,
        private readonly NativeOrders $orders,
    ) {
        parent::__construct($context);
    }
    public function execute()
    {
        $id = filter_var($this->getRequest()->getParam('order_id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        try {
            if (!$id) {
                throw new \RuntimeException('Unknown order.');
            }
            $this->orders->load($id);
            $page = $this->pages->create();
            $page->getConfig()->getTitle()->prepend((string) __('Lunixi payment'));
            $block = $page->getLayout()->getBlock('lunixi.admin.payment');
            if (!($block instanceof \Lunixi\Payments\Block\Adminhtml\Payment)) {
                throw new \RuntimeException('Native payment view missing.');
            }
            $block->setData('order_id', $id);
            return $page;
        } catch (\Throwable) {
            return $this->resultRedirectFactory->create()->setPath('sales/order/index');
        }
    }
}
