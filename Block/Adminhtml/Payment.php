<?php

declare(strict_types=1);

namespace Lunixi\Payments\Block\Adminhtml;

use Lunixi\Payments\Model\Runtime;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class Payment extends Template
{
    /** @param array<string,mixed> $data */
    public function __construct(
        Context $context,
        private readonly Runtime $runtime,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }
    public function operationId(): ?string
    {
        try {
            return $this->runtime->operationId((int) $this->getData('order_id'));
        } catch (\Throwable) {
            return null;
        }
    }
    public function currency(): string
    {
        try {
            return $this->runtime->context((int) $this->getData('order_id'))['snapshot'][
                'currency'
            ];
        } catch (\Throwable) {
            return '';
        }
    }
}
