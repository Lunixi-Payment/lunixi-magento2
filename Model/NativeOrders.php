<?php

declare(strict_types=1);

namespace Lunixi\Payments\Model;

use Lunixi\Payments\Vendor\Lunixi\Sdk\Commerce\{Money, OrderSnapshot, PaymentDecision};
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Framework\Notification\NotifierInterface;

/** Native invoices/credit memos are written on the same connection as the SDK inbox. */
class NativeOrders
{
    /** Payment flag: this module put the order in payment_review while funds are on the way. */
    public const AWAITING_FUNDS = 'lunixi_awaiting_funds';
    /** Payment list of merchant review notices already written (idempotency keys). */
    public const REVIEW_NOTICES = 'lunixi_review_notices';

    public function __construct(
        private readonly Ledger $ledger,
        private readonly OrderFactory $orders,
        private readonly OrderResource $resource,
        private readonly InvoiceService $invoices,
        private readonly CreditmemoFactory $creditmemos,
        private readonly CreditmemoManagementInterface $refunds,
        private readonly NotifierInterface $notifier,
    ) {
    }
    public function load(int $id, bool $lock = false): Order
    {
        if ($id < 1) {
            throw new \RuntimeException('Unknown order.');
        }
        if ($lock) {
            if ($this->ledger->db()->getTransactionLevel() < 1) {
                throw new \RuntimeException('Native lock requires a transaction.');
            }
            $this->ledger
                ->db()
                ->fetchOne(
                    'SELECT entity_id FROM ' .
                        $this->ledger->quote('sales_order') .
                        ' WHERE entity_id=? FOR UPDATE',
                    [$id],
                );
        }
        $order = $this->orders->create();
        $this->resource->load($order, $id);
        if (!(int) $order->getId() || $order->getPayment()->getMethod() !== 'lunixi') {
            throw new \RuntimeException('Unknown Lunixi order.');
        }
        return $order;
    }
    public static function key(Order $order): string
    {
        return 'magento:' . (int) $order->getStoreId() . ':' . (int) $order->getId();
    }
    public static function exponent(string $currency): int
    {
        return Money::exponent($currency);
    }
    public static function snapshot(Order $order, string $merchant): OrderSnapshot
    {
        $currency = (string) $order->getBaseCurrencyCode();
        return new OrderSnapshot(
            $merchant,
            self::key($order),
            Money::minor($order->getData('base_grand_total'), self::exponent($currency)),
            $currency,
        );
    }
    public static function decimal(string $minor, string $currency): string
    {
        return Money::decimal($minor, self::exponent($currency));
    }
    public static function assertSnapshot(Order $order, OrderSnapshot $snapshot): void
    {
        if (
            !hash_equals(
                $snapshot->fingerprint(),
                self::snapshot($order, $snapshot->merchantId())->fingerprint(),
            )
        ) {
            throw new \RuntimeException('Native order amount/currency changed.');
        }
    }
    public function apply(int $id, OrderSnapshot $snapshot, PaymentDecision $decision): void
    {
        $order = $this->load($id, true);
        self::assertSnapshot($order, $snapshot);
        $payment = $order->getPayment();
        if (!($payment instanceof \Magento\Sales\Model\Order\Payment)) {
            throw new \RuntimeException('Native payment required.');
        }
        if ($decision->isLinkedPayment()) {
            // Another payment of the same order never replaces the bound attempt or creates an invoice.
            $this->reviewLinkedPayment($order, $payment, $decision, $snapshot->currency());
            $this->resource->save($order);
            return;
        }
        $payment->setAdditionalInformation('lunixi_payment_id', $decision->paymentId);
        $payment->setAdditionalInformation('lunixi_gross_minor', $decision->chargedAmount);
        $payment->setAdditionalInformation('lunixi_refunded_minor', $decision->refundedAmount);
        $awaitingFunds = self::isAwaitingFunds($order, $payment);
        // A fraud/manual hold survives funding. Reconciliation can apply it after the native hold is released.
        $held =
            in_array($order->getState(), [Order::STATE_HOLDED, Order::STATE_CANCELED], true) ||
            ($order->getState() === Order::STATE_PAYMENT_REVIEW && !$awaitingFunds) ||
            $payment->getIsFraudDetected();
        if ($order->isCanceled() && Money::compare($decision->capturedAmount, '0') > 0) {
            // A canceled order is never invoiced automatically; the merchant decides.
            if ($this->claimNotice($payment, 'closed:' . $decision->paymentId)) {
                $this->announce(
                    $order,
                    (string) __(
                        'Lunixi payment %1 (%2 %3) was captured after this order was canceled. '
                            . 'The order was not invoiced. Review it and refund the payment or create a new order.',
                        $decision->paymentId,
                        self::decimal($decision->capturedAmount, $snapshot->currency()),
                        $snapshot->currency(),
                    ),
                );
            }
        }
        if (!$held && $awaitingFunds && !self::keepsAwaitingFunds($decision)) {
            // Return to the original state so invoicing, cancellation and native cleanup run unchanged.
            $this->releaseAwaitingFunds($order, $payment);
        } elseif (!$held && !$awaitingFunds && self::startsAwaitingFunds($decision)) {
            $this->awaitFunds($order, $payment);
        }
        if (
            !$held &&
            in_array($decision->state, ['paid', 'partially_refunded', 'refunded'], true)
        ) {
            if ($decision->capturedAmount !== $decision->chargedAmount) {
                throw new \RuntimeException('Only full captured settlement can create an invoice.');
            }
            if (!$order->hasInvoices()) {
                if (!$order->canInvoice()) {
                    throw new \RuntimeException('Native order cannot be invoiced.');
                }
                $invoice = $this->invoices->prepareInvoice($order);
                $invoice->setTransactionId($decision->paymentId);
                $invoice->setData('requested_capture_case', Invoice::CAPTURE_OFFLINE);
                $invoice->register();
                $invoice->setEmailSent(0);
                $invoice->save();
                // Native total collectors may use floats. Verify their persisted DECIMAL
                // result within this transaction before allowing any native effect to commit.
                if (
                    $this->persistedMinor('sales_invoice', (int) $invoice->getId(), $snapshot->currency()) !==
                    $snapshot->amount()
                ) {
                    throw new \RuntimeException('Native invoice amount differs.');
                }
                $order->setData('is_in_process', true);
                $order->addRelatedObject($invoice);
                $this->queue('invoice', (int) $invoice->getId(), $id);
            }
            $this->resource->save($order);
            $order = $this->load($id, true);
            $nativeInvoices = $order->getInvoiceCollection();
            if ($nativeInvoices->count() !== 1) {
                throw new \RuntimeException('Existing native invoices require financial review.');
            }
            foreach ($nativeInvoices as $nativeInvoice) {
                if (
                    $nativeInvoice->getTransactionId() !== $decision->paymentId ||
                    (int) $nativeInvoice->getState() !== Invoice::STATE_PAID
                ) {
                    throw new \RuntimeException(
                        'Existing invoice does not prove this Lunixi settlement.',
                    );
                }
            }
            if (
                Money::minor(
                    ($order->getData('base_total_paid') ?? '0'),
                    self::exponent($snapshot->currency()),
                ) !== $snapshot->amount()
            ) {
                throw new \RuntimeException(
                    'Native paid balance differs from the reserved basket.',
                );
            }
            $nativeRefunded = Money::minor(
                ($order->getData('base_total_refunded') ?? '0'),
                self::exponent($snapshot->currency()),
            );
            $target = Money::minimum($snapshot->amount(), $decision->refundedAmount);
            if (Money::compare($nativeRefunded, $target) > 0) {
                throw new \RuntimeException(
                    'Native refund exceeds authenticated gateway evidence.',
                );
            }
            if (Money::compare($target, $nativeRefunded) > 0) {
                $delta = Money::subtract($target, $nativeRefunded);
                // Monetary adjustments do not fabricate returned item quantities or restock inventory.
                $qtys = [];
                foreach ($order->getAllItems() as $item) {
                    $qtys[(int) $item->getId()] = 0;
                }
                $creditmemo = $this->creditmemos->createByOrder($order, [
                    'qtys' => $qtys,
                    'shipping_amount' => '0',
                    'adjustment_positive' => self::decimal($delta, $snapshot->currency()),
                ]);
                $creditmemo->setTransactionId(
                    $decision->paymentId . '-refund-' . $decision->refundedAmount,
                );
                $creditmemo->setEmailSent(0);
                // GET already proved the effect; this is native bookkeeping only.
                $this->refunds->refund($creditmemo, true);
                if (
                    $this->persistedMinor('sales_creditmemo', (int) $creditmemo->getId(), $snapshot->currency()) !==
                    $delta
                ) {
                    throw new \RuntimeException('Native credit memo amount differs.');
                }
                $this->queue('creditmemo', (int) $creditmemo->getId(), $id);
            }
        } elseif (!$held && $decision->state === 'authorized') {
            $payment->setAdditionalInformation('lunixi_authorized', true);
            // Remain pending_payment: authorization is not a paid invoice or fulfillment permission.
        } elseif (
            !$held &&
            $decision->state === 'failed' &&
            $decision->capturedAmount === '0' &&
            $decision->gatewayStatus === 'VOIDED'
        ) {
            if ($order->canCancel()) {
                $order->cancel();
            }
        }
        $this->resource->save($order);
    }
    private static function isAwaitingFunds(Order $order, Order\Payment $payment): bool
    {
        return $order->getState() === Order::STATE_PAYMENT_REVIEW &&
            $payment->getAdditionalInformation(self::AWAITING_FUNDS) === true;
    }

    /** The buyer finished; the money is on the way (e.g. a bank transfer for 1-3 business days). */
    private static function startsAwaitingFunds(PaymentDecision $decision): bool
    {
        return $decision->state === 'pending' &&
            $decision->gatewayStatus === 'PROCESSING' &&
            $decision->capturedAmount === '0';
    }

    /** Unresolved evidence keeps waiting; FAILED and every settled or failed decision leave review. */
    private static function keepsAwaitingFunds(PaymentDecision $decision): bool
    {
        return in_array($decision->state, ['pending', 'review'], true) && $decision->gatewayStatus !== 'FAILED';
    }

    /** payment_review is outside native CleanExpiredOrders, which cancels only pending_payment. */
    private function awaitFunds(Order $order, Order\Payment $payment): void
    {
        if ($order->getState() !== Order::STATE_PENDING_PAYMENT || $order->hasInvoices()) {
            return;
        }
        $payment->setAdditionalInformation(self::AWAITING_FUNDS, true);
        $order->setState(Order::STATE_PAYMENT_REVIEW)->setStatus(Order::STATE_PAYMENT_REVIEW);
        $order->addCommentToStatusHistory(
            (string) __(
                'The Lunixi payment is processing, for example a bank transfer awaiting funds. '
                    . 'The order stays in payment review and is invoiced when the payment completes.',
            ),
        );
    }

    private function releaseAwaitingFunds(Order $order, Order\Payment $payment): void
    {
        $payment->unsAdditionalInformation(self::AWAITING_FUNDS);
        $order->setState(Order::STATE_PENDING_PAYMENT)->setStatus(Order::STATE_PENDING_PAYMENT);
    }

    private function reviewLinkedPayment(
        Order $order,
        Order\Payment $payment,
        PaymentDecision $decision,
        string $currency,
    ): void {
        if (!$this->claimNotice($payment, 'linked:' . $decision->paymentId)) {
            return;
        }
        if (self::isAwaitingFunds($order, $payment)) {
            $this->releaseAwaitingFunds($order, $payment);
        }
        if ($order->canHold()) {
            $order->hold();
        }
        $this->announce(
            $order,
            (string) __(
                'Lunixi accepted another payment for this order: %1 (%2 %3), for example a late bank transfer. '
                    . 'It was not invoiced. Review the order and refund the payment if it is not needed.',
                $decision->paymentId,
                self::decimal($decision->capturedAmount, $currency),
                $currency,
            ),
        );
    }

    /** Idempotent per key under the order lock; apply() runs more than once for the same evidence. */
    private function claimNotice(Order\Payment $payment, string $key): bool
    {
        $notices = $payment->getAdditionalInformation(self::REVIEW_NOTICES);
        $notices = is_array($notices) ? array_values(array_filter($notices, 'is_string')) : [];
        if (in_array($key, $notices, true)) {
            return false;
        }
        $notices[] = $key;
        $payment->setAdditionalInformation(self::REVIEW_NOTICES, $notices);
        return true;
    }

    private function announce(Order $order, string $message): void
    {
        $order->addCommentToStatusHistory($message);
        try {
            $this->notifier->addMajor(
                (string) __('Lunixi payment needs review: order #%1', (string) $order->getIncrementId()),
                $message,
            );
        } catch (\Throwable) {
            /* The order comment is the durable record; an inbox failure must not block settlement. */
        }
    }

    private function persistedMinor(string $table, int $entityId, string $currency): string
    {
        if (
            !in_array($table, ['sales_invoice', 'sales_creditmemo'], true) ||
            $entityId < 1 ||
            $this->ledger->db()->getTransactionLevel() < 1
        ) {
            throw new \RuntimeException('A persisted native money record in the ledger transaction is required.');
        }
        $decimal = $this->ledger->db()->fetchOne(
            'SELECT base_grand_total FROM ' . $this->ledger->quote($table) . ' WHERE entity_id=?',
            [$entityId],
        );
        return Money::minor($decimal, self::exponent($currency));
    }

    private function queue(string $kind, int $entityId, int $orderId): void
    {
        $this->ledger->db()->insertOnDuplicate(
            $this->ledger->table('lunixi_payment_outbox'),
            [
                'message_id' => hash('sha256', $kind . ':' . $entityId),
                'order_id' => $orderId,
                'kind' => $kind,
                'entity_id' => $entityId,
                'state' => 'pending',
                'created_at' => time(),
            ],
            ['message_id'],
        );
    }
}
