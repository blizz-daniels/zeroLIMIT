<?php
declare(strict_types=1);

if (($_GET['page'] ?? '') === 'payments') {
    require __DIR__ . '/config.php';
    require_once __DIR__ . '/payments.php';
    require_once __DIR__ . '/payment_admin.php';
    admin_required();
    payment_admin_page();
    exit;
}

ob_start();
require __DIR__ . '/admin.presentation.php';
$html = ob_get_clean();
require_once __DIR__ . '/payments.php';
try {
    payment_migrate();
    $html = str_replace('⚙ Settings</a>', '⚙ Settings</a><a href="admin.php?page=payments">₦ Payments</a>', $html);
    $html = str_replace('>Price<input', '>Recipient amount (net)<input', $html);
    $html = str_replace('>Discount price <small>(optional)</small><input', '>Sale recipient amount <small>(optional)</small><input', $html);
    if (($_GET['page'] ?? '') === 'product' && ($id = (int)($_GET['id'] ?? 0))) {
        $stmt = db()->prepare('SELECT p.*,r.name recipient_name,r.subaccount_code FROM products p LEFT JOIN payment_recipients r ON r.id=p.recipient_id WHERE p.id=?'); $stmt->execute([$id]); $product = $stmt->fetch();
        if ($product) {
            $base = $product['discount_price'] !== null ? (string)$product['discount_price'] : (string)$product['price'];
            $quote = payment_quote_naira($base);
            $recipient = $product['recipient_name'] ? h($product['recipient_name'] . ' (' . $product['subaccount_code'] . ')') : 'Not assigned — configure in Payments';
            $panel = '<section class="panel payment-product-panel"><p class="eyebrow">payment pricing</p><h2>Gross-up breakdown</h2><div class="payment-admin-breakdown"><span>Base / amount to receive</span><b>' . payment_money_kobo($quote['base_kobo']) . '</b><span>10% service charge</span><b>' . payment_money_kobo($quote['service_kobo']) . '</b><span>Estimated Paystack fee</span><b>' . payment_money_kobo($quote['paystack_kobo']) . '</b><span>Final customer price</span><b>' . payment_money_kobo($quote['gross_kobo']) . '</b><span>Recipient / subaccount</span><b>' . $recipient . '</b><span>Expected recipient net</span><b>' . payment_money_kobo($quote['expected_net_kobo']) . '</b></div><a class="admin-button" href="admin.php?page=payments">Manage payment recipients →</a></section>';
            $html = str_replace('</main></body>', $panel . '</main></body>', $html);
        }
    }
} catch (Throwable $ignored) { /* Existing admin pages stay available if payment migration is temporarily unavailable. */ }
echo $html;
