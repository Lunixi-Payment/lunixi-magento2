<?php

declare(strict_types=1);

namespace Lunixi\Payments\Plugin;

use Magento\Sales\Block\Adminhtml\Order\View;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\LayoutInterface;

class OrderActions
{
    public function __construct(private readonly AuthorizationInterface $authorization)
    {
    }
    /** @return array{LayoutInterface} */
    public function beforeSetLayout(View $subject, LayoutInterface $layout): array
    {
        if (
            $subject->getOrder()->getPayment()->getMethod() === 'lunixi' &&
            $this->authorization->isAllowed('Lunixi_Payments::payment')
        ) {
            $url = $subject->getUrl('lunixi/payment/view', [
                'order_id' => (int) $subject->getOrder()->getId(),
            ]);
            $subject->addButton('lunixi_payment', [
                'label' => __('Manage Lunixi payment'),
                'onclick' =>
                    'setLocation(' .
                    json_encode(
                        $url,
                        JSON_HEX_TAG |
                            JSON_HEX_APOS |
                            JSON_HEX_AMP |
                            JSON_HEX_QUOT |
                            JSON_THROW_ON_ERROR,
                    ) .
                    ')',
            ]);
        }
        return [$layout];
    }
}
