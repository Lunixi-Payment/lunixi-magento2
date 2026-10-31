<?php

declare(strict_types=1);

namespace Lunixi\Payments\Model;

/** Magento cron: independent rotating budgets for active payments and settled refund discovery. */
class Reconcile
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly Runtime $runtime,
        private readonly Outbox $outbox,
    ) {
    }
    public function execute(): void
    {
        $start = time();
        $batches = [
                ['pending', 'reserved', 'review', 'authorized', 'partially_captured'],
                ['paid', 'partially_refunded'],
            ];
        foreach ($batches as $index => $states) {
            $db = $this->ledger->db();
            $select = $db
                ->select()
                ->from(['a' => $this->ledger->table('lunixi_payment_attempt')], ['order_key'])
                ->join(
                    ['c' => $this->ledger->table('lunixi_payment_context')],
                    'a.order_key=c.order_key',
                    ['native_order_id'],
                )
                ->where('a.state IN (?)', $states)
                ->order(['a.checked_at ASC', 'a.order_key ASC'])
                ->limit($index === 0 ? 80 : 20);
            foreach ($db->fetchAll($select) as $row) {
                if (time() - $start > 100) {
                    break 2;
                }
                try {
                    $this->runtime->reconcile((int) $row['native_order_id']);
                } catch (\Throwable) {
                    /* Keep durable state. Never log credentials or claim a successful charge. */
                } finally {
                    $db->update(
                        $this->ledger->table('lunixi_payment_attempt'),
                        ['checked_at' => time()],
                        ['order_key = ?' => $row['order_key']],
                    );
                }
            }
        }
        $this->outbox->execute();
    }
}
