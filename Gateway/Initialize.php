<?php

declare(strict_types=1);

namespace Lunixi\Payments\Gateway;

use Magento\Framework\DataObject;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Sales\Model\Order;

/** Order placement is pending; intent creation happens after the native order has committed. */
class Initialize implements CommandInterface
{
    /** @param array<string,mixed> $commandSubject */
    public function execute(array $commandSubject)
    {
        $state = $commandSubject['stateObject'] ?? null;
        if (!($state instanceof DataObject)) {
            throw new \DomainException('Native order state is required.');
        }
        $state->setData('state', Order::STATE_PENDING_PAYMENT);
        $state->setData('status', Order::STATE_PENDING_PAYMENT);
        $state->setData('is_notified', false);
        return null;
    }
}
