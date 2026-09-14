<?php
declare(strict_types=1);

function payment_cart_base_kobo(array $lines): int {
    $baseKobo = 0;
    foreach ($lines as $line) {
        $base = $line['discount_price'] !== null ? (string)$line['discount_price'] : (string)$line['price'];
        $baseKobo += payment_decimal_to_kobo($base) * (int)$line['quantity'];
    }
    return $baseKobo;
}
function payment_quote_with_target(int $baseKobo, int $serviceKobo, int $deliveryKobo): array {
    $settings = payment_fee_settings();
    if ($settings['paystack_bps'] < 0 || $settings['paystack_bps'] >= 10000) throw new RuntimeException('Payment fee settings are invalid.');
    $target = $baseKobo + $serviceKobo + $deliveryKobo;
    $denominator = 10000 - $settings['paystack_bps'];
    $candidates = [$target, payment_ceil_div($target * 10000, $denominator), payment_ceil_div(($target + $settings['fixed_kobo']) * 10000, $denominator), $target + $settings['cap_kobo']];
    $gross = PHP_INT_MAX;
    foreach ($candidates as $candidate) {
        $candidate = max($target, $candidate);
        for ($attempt = 0; $attempt < 25000; $attempt++, $candidate++) {
            if ($candidate - payment_paystack_fee_kobo($candidate, $settings) >= $target) { $gross = min($gross, $candidate); break; }
        }
    }
    if ($gross === PHP_INT_MAX) throw new RuntimeException('Could not calculate the customer payment total.');
    $fee = payment_paystack_fee_kobo($gross, $settings);
    return ['base_kobo'=>$baseKobo,'service_kobo'=>$serviceKobo,'delivery_kobo'=>$deliveryKobo,'paystack_kobo'=>$fee,'gross_kobo'=>$gross,'expected_net_kobo'=>$gross-$fee-$serviceKobo-$deliveryKobo,'charge_kobo'=>$gross-$baseKobo,'settings'=>$settings];
}
function payment_cart_quote(array $lines): array {
    payment_migrate();
    if (!$lines) throw new RuntimeException('Your bag is empty.');
    $recipientIds = [];
    foreach ($lines as $line) {
        if (empty($line['recipient_id'])) throw new RuntimeException('One or more items are not assigned to a Paystack recipient yet.');
        $recipientIds[(int)$line['recipient_id']] = true;
    }
    if (count($recipientIds) !== 1) throw new RuntimeException('Items for different recipients must be purchased in separate orders.');
    $recipient = payment_get_recipient((int)array_key_first($recipientIds));
    if (!$recipient || !preg_match('/^ACCT_[A-Za-z0-9]+$/', $recipient['subaccount_code'])) throw new RuntimeException('The Paystack recipient/subaccount for these items is not configured correctly.');
    $base = payment_cart_base_kobo($lines);
    $threshold = payment_decimal_to_kobo(setting('free_delivery_threshold', '15000'));
    $delivery = $base >= $threshold ? 0 : payment_decimal_to_kobo(setting('delivery_fee', '0'));
    $service = payment_ceil_div($base * payment_fee_settings()['service_bps'], 10000);
    $quote = payment_quote_with_target($base, $service, $delivery);
    $quote['recipient'] = $recipient;
    return $quote;
}
function payment_required_checkout_field(string $field, string $label): string {
    $value = trim((string)($_POST[$field] ?? ''));
    if ($value === '') throw new RuntimeException($label . ' is required.');
    return $value;
}
function payment_create_order_from_checkout(): never {
    verify_csrf(); payment_migrate();
    try {
        $fullName = payment_required_checkout_field('full_name', 'Full name');
        $phone = payment_required_checkout_field('phone', 'Phone number');
        $email = payment_required_checkout_field('email', 'Email address');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address for Paystack payment updates.');
        $state = payment_required_checkout_field('state', 'State'); $city = payment_required_checkout_field('city', 'City/Town'); $address = payment_required_checkout_field('address', 'Delivery address');
        if (!payment_keys_ready()) throw new RuntimeException('Payments are not ready yet. The administrator must configure Paystack keys first.');
        $lines = cart_lines(); $quote = payment_cart_quote($lines); $pdo = db(); $pdo->beginTransaction();
        foreach ($lines as $line) {
            $stock = $pdo->prepare('SELECT stock,status FROM products WHERE id=? FOR UPDATE'); $stock->execute([$line['id']]); $availability = $stock->fetch();
            if (!$availability || $availability['status'] !== 'active' || (int)$availability['stock'] < (int)$line['quantity']) throw new RuntimeException($line['name'] . ' no longer has enough stock.');
        }
        $customer = $pdo->prepare('INSERT INTO customers (full_name,phone,email) VALUES (?,?,?)'); $customer->execute([$fullName,$phone,$email]);
        $number = 'ZL-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3))); $reference = payment_reference($number);
        $order = $pdo->prepare('INSERT INTO orders (order_number,customer_id,state,city,address,landmark,subtotal,delivery_fee,total,base_subtotal,service_charge,paystack_fee,payment_total,recipient_id,payment_status,paystack_reference) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $order->execute([$number,$pdo->lastInsertId(),$state,$city,$address,trim((string)($_POST['landmark'] ?? '')),payment_kobo_to_naira($quote['base_kobo']),payment_kobo_to_naira($quote['delivery_kobo']),payment_kobo_to_naira($quote['gross_kobo']),payment_kobo_to_naira($quote['base_kobo']),payment_kobo_to_naira($quote['service_kobo']),payment_kobo_to_naira($quote['paystack_kobo']),payment_kobo_to_naira($quote['gross_kobo']),$quote['recipient']['id'],'pending',$reference]);
        $orderId = (int)$pdo->lastInsertId();
        $item = $pdo->prepare('INSERT INTO order_items (order_id,product_id,product_name,product_image,selected_size,selected_color,quantity,unit_price,subtotal) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($lines as $line) {
            $unitBase = $line['discount_price'] !== null ? (string)$line['discount_price'] : (string)$line['price'];
            $item->execute([$orderId,$line['id'],$line['name'],$line['image'],$line['selected_size'],$line['selected_color'],$line['quantity'],$unitBase,payment_kobo_to_naira(payment_decimal_to_kobo($unitBase)*(int)$line['quantity'])]);
        }
        $payment = $pdo->prepare('INSERT INTO payment_transactions (order_id,recipient_id,reference_code,base_amount,service_charge,expected_paystack_fee,gross_amount,expected_net_amount,status) VALUES (?,?,?,?,?,?,?,?,"pending")');
        $payment->execute([$orderId,$quote['recipient']['id'],$reference,payment_kobo_to_naira($quote['base_kobo']),payment_kobo_to_naira($quote['service_kobo']),payment_kobo_to_naira($quote['paystack_kobo']),payment_kobo_to_naira($quote['gross_kobo']),payment_kobo_to_naira($quote['expected_net_kobo'])]);
        $pdo->commit(); $_SESSION['pending_payment_order'] = $orderId; $_SESSION['cart'] = []; redirect('pay.php?order=' . $orderId);
    } catch (Throwable $error) { if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack(); flash('notice', $error->getMessage()); redirect('index.php?page=checkout'); }
}
