<?php

declare(strict_types=1);

namespace Lunixi\Payments\Model;

use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\CreditmemoSender;

/** Commit before external mail. An uncertain send is retained for operator review, not blindly resent. */
class Outbox
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly InvoiceRepositoryInterface $invoices,
        private readonly CreditmemoRepositoryInterface $creditmemos,
        private readonly InvoiceSender $invoiceSender,
        private readonly CreditmemoSender $creditmemoSender,
    ) {
    }
    public function execute(): void
    {
        $db = $this->ledger->db();
        $table = $this->ledger->table('lunixi_payment_outbox');
        $rows = $db->fetchAll(
            $db
                ->select()
                ->from($table)
                ->where('state=?', 'pending')
                ->order('created_at ASC')
                ->limit(20),
        );
        foreach ($rows as $row) {
            if (
                $db->update(
                    $table,
                    ['state' => 'sending'],
                    ['message_id = ?' => $row['message_id'], 'state = ?' => 'pending'],
                ) !== 1
            ) {
                continue;
            }
            try {
                if ($row['kind'] === 'invoice') {
                    $invoice = $this->invoices->get((int) $row['entity_id']);
                    if (!($invoice instanceof \Magento\Sales\Model\Order\Invoice)) {
                        throw new \RuntimeException('Native invoice required.');
                    }
                    $sent = $invoice->getEmailSent() || $this->invoiceSender->send($invoice, true);
                } elseif ($row['kind'] === 'creditmemo') {
                    $memo = $this->creditmemos->get((int) $row['entity_id']);
                    if (!($memo instanceof \Magento\Sales\Model\Order\Creditmemo)) {
                        throw new \RuntimeException('Native credit memo required.');
                    }
                    $sent = $memo->getEmailSent() || $this->creditmemoSender->send($memo, true);
                } else {
                    throw new \RuntimeException('Unknown notification kind.');
                }
                $db->update(
                    $table,
                    ['state' => $sent ? 'sent' : 'review'],
                    ['message_id = ?' => $row['message_id']],
                );
            } catch (\Throwable) {
                $db->update(
                    $table,
                    ['state' => 'review'],
                    ['message_id = ?' => $row['message_id']],
                );
            }
        }
    }
}
