<?php
declare(strict_types=1);

function payment_transactions_page(): void
{
    payment_migrate();
    $transactions = db()->query(
        'SELECT pt.*, o.order_number, c.full_name, r.name AS recipient_name, r.subaccount_code
         FROM payment_transactions pt
         JOIN orders o ON o.id = pt.order_id
         JOIN customers c ON c.id = o.customer_id
         LEFT JOIN payment_recipients r ON r.id = pt.recipient_id
         ORDER BY pt.created_at DESC, pt.id DESC
         LIMIT 100'
    )->fetchAll();

    payment_admin_head('Payment transactions'); ?>
    <section class="panel">
        <div class="panel-heading"><div><p class="eyebrow">auditable settlement records</p><h2>Payment history</h2></div><a class="admin-button" href="admin.php?page=payments">Payment settings &rarr;</a></div>
        <p class="admin-empty">Every row preserves the calculation used when checkout began. Actual Paystack fees are populated after Paystack verifies a successful payment.</p>
        <?php if (!$transactions): ?>
            <div class="admin-empty">No Paystack payment attempts have been recorded yet.</div>
        <?php else: ?>
            <div class="admin-table-wrap"><table><thead><tr><th>Order / reference</th><th>Recipient</th><th>Base net</th><th>Service</th><th>Paystack fee</th><th>Customer paid</th><th>Expected net</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($transactions as $transaction): ?>
                <tr>
                    <td><b><?=h($transaction['order_number'])?></b><small class="payment-reference"><?=h($transaction['reference_code'])?></small><small><?=h($transaction['full_name'])?> &middot; <?=h((string)$transaction['created_at'])?></small></td>
                    <td><?=h($transaction['recipient_name'] ?: 'Unassigned')?><small><?=h((string)$transaction['subaccount_code'])?></small></td>
                    <td><?=money((float)$transaction['base_amount'])?></td>
                    <td><?=money((float)$transaction['service_charge'])?></td>
                    <td><?=money((float)($transaction['actual_paystack_fee'] ?? $transaction['expected_paystack_fee']))?><small><?= $transaction['actual_paystack_fee'] === null ? 'estimated' : 'actual' ?></small></td>
                    <td><b><?=money((float)$transaction['gross_amount'])?></b></td>
                    <td><?=money((float)$transaction['expected_net_amount'])?></td>
                    <td><span class="admin-status <?=h($transaction['status'])?>"><?=h(ucfirst($transaction['status']))?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>
    <?php payment_admin_footer();
}
