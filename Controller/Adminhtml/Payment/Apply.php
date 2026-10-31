<?php

declare(strict_types=1);

namespace Lunixi\Payments\Controller\Adminhtml\Payment;

use Lunixi\Payments\Model\Runtime;
use Lunixi\Payments\Model\NativeOrders;
use Lunixi\Payments\Vendor\Lunixi\Sdk\Commerce\Money;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Data\Form\FormKey\Validator;

class Apply extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Lunixi_Payments::payment';
    public function __construct(
        Context $context,
        private readonly Runtime $runtime,
        private readonly Validator $formKey,
    ) {
        parent::__construct($context);
    }
    public function execute()
    {
        $id = filter_var($this->getRequest()->getParam('order_id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        try {
            if (
                !$id ||
                !$this->getRequest()->isSecure() ||
                !$this->formKey->validate($this->getRequest())
            ) {
                throw new \RuntimeException('Permission and CSRF token required.');
            }
            $action = (string) $this->getRequest()->getParam('action');
            if ($action === 'reconcile') {
                $this->runtime->reconcile($id);
            } else {
                $context = $this->runtime->context($id);
                $amount =
                    $action === 'refund'
                        ? Money::minor(
                            $this->getRequest()->getParam('amount'),
                            NativeOrders::exponent($context['snapshot']['currency']),
                        )
                        : null;
                $this->runtime->mutate(
                    $id,
                    (string) $this->getRequest()->getParam('operation_id'),
                    $action,
                    $amount,
                );
            }
            $this->messageManager->addSuccessMessage(
                __('Verified payment state has been applied to the native order.'),
            );
        } catch (\Throwable) {
            $this->messageManager->addErrorMessage(
                __(
                    'The operation is pending or requires review. Reconcile the saved operation before retrying.',
                ),
            );
        }
        return $this->resultRedirectFactory
            ->create()
            ->setPath('lunixi/payment/view', ['order_id' => $id ?: 0]);
    }
}
