<?php

declare(strict_types=1);

namespace Lunixi\Payments\Model;

use Lunixi\Payments\Vendor\Lunixi\Sdk\Commerce\{
    CheckoutService,
    CheckoutSession,
    OrderSnapshot,
    PaymentDecision,
    Money,
};
use Lunixi\Payments\Vendor\Lunixi\Sdk\Webhook\WebhookEvent;
use Magento\Framework\UrlInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Sales\Model\Order;

/** Native ownership/configuration adapter; all gateway evidence and financial transport live in the SDK. */
class Runtime
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly Settings $settings,
        private readonly Profiles $profiles,
        private readonly ClientFactory $clients,
        private readonly NativeOrders $orders,
        private readonly UrlInterface $urls,
        private readonly ResolverInterface $locale,
    ) {
    }
    /** @return array<string,mixed> */
    public function context(int $id): array
    {
        $row = $this->ledger
            ->db()
            ->fetchRow(
                'SELECT order_key,profile_id,payload FROM ' .
                    $this->ledger->quote('lunixi_payment_context') .
                    ' WHERE native_order_id=?',
                [$id],
            );
        if (!is_array($row)) {
            throw new \RuntimeException('No reserved Lunixi context.');
        }
        return json_decode(
            $this->settings->box()->decrypt((string) $row['payload']),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
    }
    public function owned(int $id, int $customerId, int $lastGuestOrder, int $storeId): Order
    {
        $order = $this->orders->load($id);
        if (
            (int) $order->getCustomerId() > 0
                ? (int) $order->getCustomerId() !== $customerId
                : $lastGuestOrder !== $id
        ) {
            throw new \RuntimeException('Order ownership required.');
        }
        if ((int) $order->getStoreId() !== $storeId) {
            throw new \RuntimeException('Order belongs to another store.');
        }
        if ($order->isCanceled()) {
            throw new \RuntimeException('Canceled order.');
        }
        return $order;
    }
    /** @return array<string,mixed> */
    public function reserve(int $id): array
    {
        $order = $this->orders->load($id);
        $key = NativeOrders::key($order);
        try {
            return $this->context($id);
        } catch (\RuntimeException) {
            /* Only an absent context permits reservation below. */
        }
        $store = (int) $order->getStoreId();
        if (!$this->settings->flag('active', $store)) {
            throw new \RuntimeException('Lunixi is disabled.');
        }
        $profile = $this->profiles->current($store);
        $credentials = $this->profiles->get($profile);
        return $this->ledger->transaction($key, function () use (
            $id,
            $key,
            $store,
            $profile,
            $credentials,
        ): array {
            if (
                $this->ledger
                    ->db()
                    ->fetchOne(
                        'SELECT order_key FROM ' .
                            $this->ledger->quote('lunixi_payment_context') .
                            ' WHERE order_key=?',
                        [$key],
                    )
            ) {
                return $this->context($id);
            }
            $order = $this->orders->load($id, true);
            if (
                (int) $order->getStoreId() !== $store ||
                $order->getState() !== Order::STATE_PENDING_PAYMENT || $order->hasInvoices()
            ) {
                throw new \RuntimeException('Order is not awaiting initial payment.');
            }
            $snapshot = NativeOrders::snapshot($order, $credentials['merchantId']);
            $context = [
                'id' => $id,
                'orderKey' => $key,
                'profileId' => $profile,
                'snapshot' => $snapshot->toArray(),
                'hosted' => $this->settings->flag('hosted', $store),
                'preauth' => $this->settings->flag('preauth', $store),
                'locale' => str_starts_with($this->locale->getLocale(), 'tr') ? 'tr' : 'en',
                'callback' => $this->urls->getUrl('lunixi/checkout/callback', [
                    '_secure' => true,
                    '_nosid' => true,
                    '_scope' => $store,
                ]),
            ];
            $this->ledger->db()->insert($this->ledger->table('lunixi_payment_context'), [
                'order_key' => $key,
                'native_order_id' => $id,
                'profile_id' => $profile,
                'payload' => $this->settings
                    ->box()
                    ->encrypt(json_encode($context, JSON_THROW_ON_ERROR)),
            ]);
            return $context;
        });
    }
    /** @param array<string,mixed> $context */
    private function service(array $context): CheckoutService
    {
        return new CheckoutService(
            $this->clients->create($this->profiles->get($context['profileId'])),
            $this->ledger,
            $this->settings->box(),
        );
    }
    public function start(int $id): CheckoutSession
    {
        $context = $this->reserve($id);
        $snapshot = OrderSnapshot::fromArray($context['snapshot']);
        NativeOrders::assertSnapshot($this->orders->load($id), $snapshot);
        return $this->service($context)->start(
            $context['orderKey'],
            $snapshot,
            $context['callback'],
            $context['hosted'],
            $context['preauth'],
            $context['locale'],
        );
    }
    public function browser(int $id): CheckoutSession
    {
        $context = $this->context($id);
        return $this->ledger->transaction($context['orderKey'], function () use (
            $id,
            $context,
        ): CheckoutSession {
            NativeOrders::assertSnapshot(
                $this->orders->load($id, true),
                OrderSnapshot::fromArray($context['snapshot']),
            );
            $record = $this->ledger->load($context['orderKey']);
            if (
                !is_array($record) ||
                !in_array($record['state'], ['reserved', 'pending', 'review'], true) ||
                $record['paymentId'] === '' ||
                $record['token'] === '' ||
                time() - $record['createdAt'] >= 840
            ) {
                throw new \RuntimeException(
                    'Reconcile the existing attempt before another checkout.',
                );
            }
            return new CheckoutSession(
                $record['paymentId'],
                $this->settings->box()->decrypt($record['token']),
                !empty($record['paymentPageUrl']) ? $this->settings->box()->decrypt($record['paymentPageUrl']) : '',
            );
        });
    }
    public function reconcile(int $id, ?WebhookEvent $event = null): PaymentDecision
    {
        $context = $this->context($id);
        $snapshot = OrderSnapshot::fromArray($context['snapshot']);
        $decision = $this->service($context)->reconcile(
            $context['orderKey'],
            fn(PaymentDecision $d) => $this->orders->apply($id, $snapshot, $d),
            $event,
        );
        $this->ledger->transaction($context['orderKey'], function () use (
            $id,
            $context,
            $snapshot,
            $decision,
        ): void {
            // Also recover a native hold released after SDK settlement was already recorded.
            $this->orders->apply($id, $snapshot, $decision);
            $this->confirmOperation($context['orderKey']);
        });
        return $decision;
    }
    /** @param array<string,string> $headers */
    public function receive(string $profileId, string $raw, array $headers): void
    {
        $credentials = $this->profiles->get($profileId);
        $service = new CheckoutService(
            $this->clients->create($credentials),
            $this->ledger,
            $this->settings->box(),
        );
        $event = $service->verifyWebhook($raw, $headers, $credentials['webhookSecret']);
        $payment = CheckoutService::eventPaymentId($event);
        $rows = $this->ledger
            ->db()
            ->fetchCol(
                'SELECT c.native_order_id FROM ' .
                    $this->ledger->quote('lunixi_payment_attempt') .
                    ' a JOIN ' .
                    $this->ledger->quote('lunixi_payment_context') .
                    ' c ON c.order_key=a.order_key WHERE a.merchant_id=? AND a.payment_id=?',
                [$credentials['merchantId'], $payment],
            );
        if ($rows === []) {
            $data = $event->data();
            $key = $data['orderId'] ?? null;
            if (!is_string($key) || !preg_match('/^magento:[1-9][0-9]*:[1-9][0-9]*$/D', $key)) {
                throw new \RuntimeException('Unknown reserved payment.');
            }
            $rows = $this->ledger
                ->db()
                ->fetchCol(
                    'SELECT c.native_order_id FROM ' .
                        $this->ledger->quote('lunixi_payment_context') .
                        ' c JOIN ' .
                        $this->ledger->quote('lunixi_payment_attempt') .
                        ' a ON c.order_key=a.order_key WHERE c.order_key=?' .
                        ' AND (a.merchant_id=? OR a.merchant_id IS NULL)',
                    [$key, $credentials['merchantId']],
                );
        }
        if (count($rows) !== 1) {
            throw new \RuntimeException('Unknown or ambiguous reserved payment.');
        }
        $context = $this->context((int) $rows[0]);
        if ($context['snapshot']['merchantId'] !== $credentials['merchantId']) {
            throw new \RuntimeException('Merchant mismatch.');
        }
        $this->reconcile((int) $rows[0], $event);
    }
    public function operationId(int $id): string
    {
        $context = $this->context($id);
        return $this->ledger->transaction($context['orderKey'], function () use ($context): string {
            $table = $this->ledger->quote('lunixi_payment_operation');
            $rows = $this->ledger
                ->db()
                ->fetchCol(
                    "SELECT operation_id FROM $table WHERE order_key=? AND state<>'confirmed'",
                    [$context['orderKey']],
                );
            if (count($rows) > 1) {
                throw new \RuntimeException('Ambiguous operation.');
            }
            if (count($rows) === 1) {
                return (string) $rows[0];
            }
            $id = bin2hex(random_bytes(16));
            $this->ledger
                ->db()
                ->insert($this->ledger->table('lunixi_payment_operation'), [
                    'operation_id' => $id,
                    'order_key' => $context['orderKey'],
                    'state' => 'reserved',
                    'created_at' => time(),
                ]);
            return $id;
        });
    }
    public function mutate(
        int $id,
        string $operationId,
        string $action,
        ?string $amount,
    ): PaymentDecision {
        if (
            !preg_match('/^[a-f0-9]{32}$/D', $operationId) ||
            !in_array($action, ['capture', 'refund', 'void'], true)
        ) {
            throw new \RuntimeException('A previously reserved operation is required.');
        }
        $this->reconcile($id);
        $context = $this->context($id);
        $snapshot = OrderSnapshot::fromArray($context['snapshot']);
        $amount = $this->ledger->transaction($context['orderKey'], function () use (
            $context,
            $operationId,
            $action,
            $amount,
        ): ?string {
            $op = $this->ledger
                ->db()
                ->fetchRow(
                    'SELECT * FROM ' .
                        $this->ledger->quote('lunixi_payment_operation') .
                        ' WHERE operation_id=? AND order_key=? FOR UPDATE',
                    [$operationId, $context['orderKey']],
                );
            if (!is_array($op)) {
                throw new \RuntimeException('Unknown operation.');
            }
            if ($op['action'] !== null) {
                if (
                    $op['action'] !== $action ||
                    ($op['requested_minor'] === null ? null : (string) $op['requested_minor']) !==
                        $amount
                ) {
                    throw new \RuntimeException(
                        'Operation already has different parameters; reconcile it.',
                    );
                }
                return $op['amount'] === null ? null : (string) $op['amount'];
            }
            $record = $this->ledger->load($context['orderKey']);
            if (!is_array($record)) {
                throw new \RuntimeException('Unknown attempt.');
            }
            $effective = $amount;
            if ($action === 'void') {
                $effective = null;
            } elseif ($action === 'capture') {
                $effective = Money::subtract($record['chargedAmount'], $record['capturedAmount']);
            } elseif ($action === 'refund') {
                if (
                    $amount === null ||
                    $amount === '0' ||
                    Money::compare(
                        $amount,
                        Money::subtract(
                            $record['snapshot']['amount'],
                            Money::minimum($record['snapshot']['amount'], $record['refundedAmount'] ?? '0')
                        )
                    ) > 0
                ) {
                    throw new \RuntimeException('Invalid native refund amount.');
                }
                if (
                    $amount ===
                    Money::subtract(
                        $record['snapshot']['amount'],
                        Money::minimum($record['snapshot']['amount'], $record['refundedAmount'] ?? '0')
                    )
                ) {
                    $effective = Money::subtract($record['chargedAmount'], $record['refundedAmount']);
                }
            }
            $this->ledger
                ->db()
                ->update(
                    $this->ledger->table('lunixi_payment_operation'),
                    [
                        'action' => $action,
                        'amount' => $effective,
                        'requested_minor' => $amount,
                        'state' => 'pending',
                    ],
                    ['operation_id = ?' => $operationId],
                );
            return $effective;
        });
        $service = $this->service($context);
        $decision = $service->mutate(
            $context['orderKey'],
            $operationId,
            $action,
            $amount,
            fn(PaymentDecision $d) => $this->orders->apply($id, $snapshot, $d),
        );
        if (
            ($service->operationStatus($context['orderKey'], $operationId)['state'] ?? '') !==
            'confirmed'
        ) {
            throw new \RuntimeException('Financial outcome is pending; reconcile this operation.');
        }
        $this->ledger->transaction(
            $context['orderKey'],
            fn() => $this->confirmOperation($context['orderKey']),
        );
        return $decision;
    }
    private function confirmOperation(string $key): void
    {
        $record = $this->ledger->load($key);
        $rows = $this->ledger
            ->db()
            ->fetchCol(
                'SELECT operation_id FROM ' .
                    $this->ledger->quote('lunixi_payment_operation') .
                    " WHERE order_key=? AND state<>'confirmed'",
                [$key],
            );
        foreach ($rows as $id) {
            if (
                ($record['operations'][hash('sha256', (string) $id)]['state'] ?? '') ===
                'confirmed'
            ) {
                $this->ledger
                    ->db()
                    ->update(
                        $this->ledger->table('lunixi_payment_operation'),
                        ['state' => 'confirmed'],
                        ['operation_id = ?' => $id],
                    );
            }
        }
    }
}
