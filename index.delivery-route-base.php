<?php
declare(strict_types=1);

$route = $_GET['page'] ?? 'home';
$action = $_POST['action'] ?? '';
if (($route === 'checkout') || ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'place_order')) {
    require __DIR__ . '/config.php';
    require_once __DIR__ . '/payments.php';
    require_once __DIR__ . '/payments_flow.php';
    require_once __DIR__ . '/checkout.php';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') payment_create_order_from_checkout();
    render_paystack_checkout();
    exit;
}

ob_start();
require __DIR__ . '/index.legacy.php';
$html = ob_get_clean();
echo str_replace('</body>', '<script src="assets/payment-pricing.js"></script></body>', $html);
