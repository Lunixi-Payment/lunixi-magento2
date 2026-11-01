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

/** Native invoices/credit memos are written on the same connection as the SDK inbox. */
class NativeOrders
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly OrderFactory $orders,
        private readonly OrderResource $resource,
        private readonly InvoiceService $invoices,
        private readonly CreditmemoFactory $creditmemos,
        private readonly CreditmemoManagementInterface $refunds,
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
        $payment->setAdditionalInformation('lunixi_payment_id', $decision->paymentId);
        $payment->setAdditionalInformation('lunixi_gross_minor', $decision->chargedAmount);
        $payment->setAdditionalInformation('lunixi_refunded_minor', $decision->refundedAmount);
        // A fraud/manual hold survives funding. Reconciliation can apply it after the native hold is released.
        $held =
            in_array(
                $order->getState(),
                [Order::STATE_HOLDED, Order::STATE_PAYMENT_REVIEW, Order::STATE_CANCELED],
                true,
            ) || $payment->getIsFraudDetected();
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
