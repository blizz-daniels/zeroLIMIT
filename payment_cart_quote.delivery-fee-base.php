<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/payments_flow.php';

header('Content-Type: application/json; charset=utf-8');

try {
    payment_migrate();
    $lines = cart_lines();
    if (!$lines) throw new RuntimeException('Cart is empty.');

    $base = payment_cart_base_kobo($lines);
    $delivery = $base >= payment_decimal_to_kobo(setting('free_delivery_threshold', '15000'))
        ? 0
        : payment_decimal_to_kobo(setting('delivery_fee', '0'));
    $service = payment_ceil_div($base * payment_fee_settings()['service_bps'], 10000);
    $quote = payment_quote_with_target($base, $service, $delivery);

    echo json_encode(['status' => true, 'quote' => [
        'base' => payment_money_kobo($quote['base_kobo']),
        'delivery' => $quote['delivery_kobo'] ? payment_money_kobo($quote['delivery_kobo']) : 'Free',
        'service' => payment_money_kobo($quote['service_kobo']),
        'paystack' => payment_money_kobo($quote['paystack_kobo']),
        'total' => payment_money_kobo($quote['gross_kobo']),
    ]], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(422);
    echo json_encode(['status' => false, 'message' => 'Pricing is unavailable.'], JSON_THROW_ON_ERROR);
}
