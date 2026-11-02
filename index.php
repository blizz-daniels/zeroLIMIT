<?php
declare(strict_types=1);

$route = $_GET['page'] ?? 'home';
$action = $_POST['action'] ?? '';

if ($route === 'checkout' || ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'place_order')) {
    require __DIR__ . '/config.php';
    require_once __DIR__ . '/payments.php';
    require_once __DIR__ . '/payments_flow.php';
    require_once __DIR__ . '/checkout.php';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') payment_create_order_from_checkout();
    render_paystack_checkout();
    exit;
}

if ($route === 'cart') {
    require __DIR__ . '/config.php';
    require_once __DIR__ . '/payments.php';
    require_once __DIR__ . '/payments_flow.php';
    require_once __DIR__ . '/cart.php';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_cart') customer_cart_update();
    customer_cart_page();
    exit;
}

ob_start();
require __DIR__ . '/index.legacy.php';
$html = ob_get_clean();
/* Remove the obsolete delivery-fee discount claim from the information page. */
if ($route === 'about') {
    $html = preg_replace('~<p>We.*?delivered free\.</p>~s', '<p>We’ll confirm each order before dispatching it. Delivery details are confirmed with you after checkout.</p>', $html, 1) ?? $html;
}
echo str_replace('</body>', '<script src="assets/payment-pricing.js"></script></body>', $html);
