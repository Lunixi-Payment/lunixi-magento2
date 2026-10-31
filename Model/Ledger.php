<?php

declare(strict_types=1);

namespace Lunixi\Payments\Model;

use Lunixi\Payments\Vendor\Lunixi\Sdk\Commerce\LedgerStore;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/** Same native sales connection, exclusive row reservations and encrypted durable state. */
class Ledger implements LedgerStore
{
    private string $active = '';
    private bool $verified = false;
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Settings $settings,
    ) {
    }
    public function db(): AdapterInterface
    {
        return $this->resource->getConnection('sales');
    }
    public function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }
    public function quote(string $name): string
    {
        return $this->db()->quoteIdentifier($this->table($name));
    }
    public function verify(): void
    {
        if ($this->verified) {
            return;
        }
        if ($this->resource->getConnection() !== $this->db()) {
            throw new \RuntimeException(
                'One native sales/default database connection is required.',
            );
        }
        $tables = [
                'sales_order',
                'sales_order_item',
                'sales_order_payment',
                'sales_order_status_history',
                'sales_invoice',
                'sales_invoice_item',
                'sales_creditmemo',
                'sales_creditmemo_item',
                'sales_payment_transaction',
                'lunixi_payment_attempt',
                'lunixi_payment_context',
                'lunixi_payment_profile',
                'lunixi_payment_event',
                'lunixi_payment_operation',
                'lunixi_payment_outbox',
            ];
        foreach ($tables as $name) {
            $engine = $this->db()->fetchOne(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
                [$this->table($name)],
            );
            if (!is_string($engine) || strcasecmp($engine, 'InnoDB') !== 0) {
                throw new \RuntimeException('Transactional native financial storage is required.');
            }
        }
        $this->verified = true;
    }
    public function transaction(string $orderKey, callable $operation)
    {
        if (
            $orderKey === '' ||
            strlen($orderKey) > 191 ||
            $this->active !== '' ||
            $this->db()->getTransactionLevel() !== 0
        ) {
            throw new \RuntimeException('A standalone serialized native transaction is required.');
        }
        $this->verify();
        $db = $this->db();
        $db->beginTransaction();
        $this->active = $orderKey;
        try {
            $table = $this->quote('lunixi_payment_attempt');
            $db->query(
                "INSERT INTO $table (order_key,payload) VALUES (?,NULL)" .
                    " ON DUPLICATE KEY UPDATE order_key=VALUES(order_key)",
                [$orderKey],
            );
            $db->fetchOne("SELECT order_key FROM $table WHERE order_key=? FOR UPDATE", [$orderKey]);
            $result = $operation();
            if ($db->getTransactionLevel() !== 1) {
                throw new \RuntimeException(
                    'A native collaborator left an incomplete transaction.',
                );
            }
            $db->commit();
            return $result;
        } catch (\Throwable $error) {
            while ($db->getTransactionLevel() > 0) {
                $db->rollBack();
            }
            throw $error;
        } finally {
            $this->active = '';
        }
    }
    public function load(string $orderKey): ?array
    {
        $this->check($orderKey);
        $cipher = $this->db()->fetchOne(
            'SELECT payload FROM ' . $this->quote('lunixi_payment_attempt') . ' WHERE order_key=?',
            [$orderKey],
        );
        return is_string($cipher) && $cipher !== ''
            ? json_decode($this->settings->box()->decrypt($cipher), true, 64, JSON_THROW_ON_ERROR)
            : null;
    }
    public function save(string $orderKey, array $record): void
    {
        $this->check($orderKey);
        $this->db()->update(
            $this->table('lunixi_payment_attempt'),
            [
                'payload' => $this->settings
                    ->box()
                    ->encrypt(json_encode($record, JSON_THROW_ON_ERROR)),
                'merchant_id' => $record['snapshot']['merchantId'],
                'payment_id' => $record['paymentId'],
                'state' => $record['state'],
            ],
            ['order_key = ?' => $orderKey],
        );
    }
    public function claimEvent(string $merchantId, string $eventId, string $payloadHash): bool
    {
        $this->check($this->active);
        if (
            $merchantId === '' ||
            $eventId === '' ||
            !preg_match('/^[a-f0-9]{64}$/D', $payloadHash)
        ) {
            throw new \InvalidArgumentException('Verified replay identity is required.');
        }
        $hash = hash('sha256', $merchantId . "\0" . $eventId);
        $table = $this->quote('lunixi_payment_event');
        $statement = $this->db()->query(
            "INSERT IGNORE INTO $table (event_hash,body_hash,received_at) VALUES (?,?,?)",
            [$hash, $payloadHash, time()],
        );
        $inserted = $statement->rowCount() === 1;
        $stored = $this->db()->fetchRow(
            "SELECT body_hash,received_at FROM $table WHERE event_hash=? FOR UPDATE",
            [$hash],
        );
        if (!is_array($stored) || !hash_equals((string) $stored['body_hash'], $payloadHash)) {
            throw new \RuntimeException('Conflicting authenticated replay.');
        }
        return $inserted;
    }
    private function check(string $key): void
    {
        if (
            $this->active === '' ||
            $key !== $this->active ||
            $this->db()->getTransactionLevel() < 1
        ) {
            throw new \RuntimeException('Financial state requires its order transaction.');
        }
    }
}
