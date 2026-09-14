<?php
declare(strict_types=1);

/*
 * All payment arithmetic is performed in kobo. Prices stored in products remain
 * the recipient's intended naira amount; customer-facing amounts are grossed up.
 */
function payment_decimal_to_kobo(string|int|float|null $value): int {
    $text = trim((string)$value);
    if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $text, $parts)) return 0;
    $naira = (int)$parts[1];
    $fraction = isset($parts[2]) ? str_pad($parts[2], 2, '0') : '00';
    return $naira * 100 + (int)$fraction;
}
function payment_kobo_to_naira(int $kobo): string { return number_format($kobo / 100, 2, '.', ''); }
function payment_money_kobo(int $kobo): string { return (function_exists('setting') ? setting('currency', '₦') : '₦') . number_format($kobo / 100, 2); }
function payment_percent_to_bps(string|int|float|null $value): int {
    $text = str_replace('%', '', trim((string)$value));
    if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $text, $parts)) return 0;
    return ((int)$parts[1] * 100) + (int)str_pad($parts[2] ?? '', 2, '0');
}
function payment_ceil_div(int $numerator, int $denominator): int { return intdiv($numerator + $denominator - 1, $denominator); }
function payment_setting(string $key, string $fallback): string { return function_exists('setting') ? setting($key, $fallback) : $fallback; }
function payment_fee_settings(): array {
    return [
        'service_bps' => payment_percent_to_bps(payment_setting('service_charge_percent', '10')),
        'paystack_bps' => payment_percent_to_bps(payment_setting('paystack_fee_percent', '1.5')),
        'fixed_kobo' => payment_decimal_to_kobo(payment_setting('paystack_fee_fixed', '100')),
        'waiver_threshold_kobo' => payment_decimal_to_kobo(payment_setting('paystack_fee_waiver_threshold', '2500')),
        'cap_kobo' => payment_decimal_to_kobo(payment_setting('paystack_fee_cap', '2000')),
    ];
}
function payment_paystack_fee_kobo(int $grossKobo, array $settings): int {
    $percentage = payment_ceil_div($grossKobo * $settings['paystack_bps'], 10000);
    $fixed = $grossKobo < $settings['waiver_threshold_kobo'] ? 0 : $settings['fixed_kobo'];
    $fee = $percentage + $fixed;
    return $settings['cap_kobo'] > 0 ? min($fee, $settings['cap_kobo']) : $fee;
}
function payment_quote_kobo(int $baseKobo): array {
    if ($baseKobo < 1) throw new InvalidArgumentException('The amount to receive must be greater than zero.');
    $settings = payment_fee_settings();
    if ($settings['service_bps'] < 0 || $settings['paystack_bps'] < 0 || $settings['paystack_bps'] >= 10000) throw new RuntimeException('Payment fee settings are invalid.');
    $serviceKobo = payment_ceil_div($baseKobo * $settings['service_bps'], 10000);
    $settlementTarget = $baseKobo + $serviceKobo;
    $denominator = 10000 - $settings['paystack_bps'];
    $candidates = [
        $settlementTarget,
        payment_ceil_div($settlementTarget * 10000, $denominator),
        payment_ceil_div(($settlementTarget + $settings['fixed_kobo']) * 10000, $denominator),
        $settlementTarget + $settings['cap_kobo'],
    ];
    $grossKobo = PHP_INT_MAX;
    foreach ($candidates as $candidate) {
        $candidate = max($settlementTarget, $candidate);
        /* Only kobo-level adjustments are needed after the closed-form candidate. */
        for ($attempt = 0; $attempt < 25000; $attempt++, $candidate++) {
            $fee = payment_paystack_fee_kobo($candidate, $settings);
            if ($candidate - $fee >= $settlementTarget) { $grossKobo = min($grossKobo, $candidate); break; }
        }
    }
    if ($grossKobo === PHP_INT_MAX) throw new RuntimeException('Could not calculate a payment total from the configured fees.');
    $paystackKobo = payment_paystack_fee_kobo($grossKobo, $settings);
    return [
        'base_kobo' => $baseKobo,
        'service_kobo' => $serviceKobo,
        'paystack_kobo' => $paystackKobo,
        'gross_kobo' => $grossKobo,
        'expected_net_kobo' => $grossKobo - $serviceKobo - $paystackKobo,
        'charge_kobo' => $grossKobo - $baseKobo,
        'settings' => $settings,
    ];
}
function payment_quote_naira(string|int|float $baseNaira): array { return payment_quote_kobo(payment_decimal_to_kobo((string)$baseNaira)); }
function payment_customer_price(string|int|float $baseNaira): float { return (float)payment_kobo_to_naira(payment_quote_naira($baseNaira)['gross_kobo']); }

function payment_store_setting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare('INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key, $value]);
}
function payment_column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute([$table, $column]); return (int)$stmt->fetchColumn() > 0;
}
function payment_add_column(PDO $pdo, string $table, string $column, string $definition): void {
    if (!payment_column_exists($pdo, $table, $column)) $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
}
function payment_migrate(): void {
    static $migrated = false;
    if ($migrated) return;
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS payment_recipients (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, subaccount_code VARCHAR(100) NOT NULL UNIQUE, description VARCHAR(255) NULL, active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE IF NOT EXISTS payment_transactions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id INT UNSIGNED NOT NULL, recipient_id INT UNSIGNED NULL, reference_code VARCHAR(100) NOT NULL UNIQUE, provider VARCHAR(30) NOT NULL DEFAULT "paystack", base_amount DECIMAL(12,2) NOT NULL, service_charge DECIMAL(12,2) NOT NULL, expected_paystack_fee DECIMAL(12,2) NOT NULL, gross_amount DECIMAL(12,2) NOT NULL, expected_net_amount DECIMAL(12,2) NOT NULL, actual_paystack_fee DECIMAL(12,2) NULL, status VARCHAR(30) NOT NULL DEFAULT "initialized", raw_response JSON NULL, paid_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, CONSTRAINT fk_payment_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE) ENGINE=InnoDB');
    payment_add_column($pdo, 'products', 'recipient_id', 'INT UNSIGNED NULL');
    payment_add_column($pdo, 'orders', 'base_subtotal', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
    payment_add_column($pdo, 'orders', 'service_charge', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
    payment_add_column($pdo, 'orders', 'paystack_fee', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
    payment_add_column($pdo, 'orders', 'payment_total', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
    payment_add_column($pdo, 'orders', 'recipient_id', 'INT UNSIGNED NULL');
    payment_add_column($pdo, 'orders', 'payment_status', 'VARCHAR(30) NOT NULL DEFAULT "unpaid"');
    payment_add_column($pdo, 'orders', 'paystack_reference', 'VARCHAR(100) NULL');
    foreach ([
        'service_charge_percent' => '10', 'paystack_fee_percent' => '1.5', 'paystack_fee_fixed' => '100',
        'paystack_fee_waiver_threshold' => '2500', 'paystack_fee_cap' => '2000', 'paystack_public_key' => '',
        'paystack_secret_key' => '', 'paystack_callback_url' => ''
    ] as $key => $value) payment_store_setting($pdo, $key, $value);
    $migrated = true;
}
function payment_get_recipient(int $recipientId): ?array {
    $stmt = db()->prepare('SELECT * FROM payment_recipients WHERE id=? AND active=1'); $stmt->execute([$recipientId]); return $stmt->fetch() ?: null;
}
function payment_quote_for_cart(array $lines): array {
    payment_migrate();
    if (!$lines) throw new RuntimeException('Your bag is empty.');
    $baseKobo = 0; $recipientIds = [];
    foreach ($lines as $line) {
        if (empty($line['recipient_id'])) throw new RuntimeException('One or more items are not assigned to a Paystack recipient yet.');
        $recipientIds[(int)$line['recipient_id']] = true;
        $lineBase = $line['discount_price'] !== null ? (string)$line['discount_price'] : (string)$line['price'];
        $baseKobo += payment_decimal_to_kobo($lineBase) * (int)$line['quantity'];
    }
    if (count($recipientIds) !== 1) throw new RuntimeException('Items for different recipients must be checked out separately.');
    $recipientId = (int)array_key_first($recipientIds); $recipient = payment_get_recipient($recipientId);
    if (!$recipient || !preg_match('/^ACCT_[A-Za-z0-9]+$/', $recipient['subaccount_code'])) throw new RuntimeException('The Paystack recipient/subaccount for these items is not configured correctly.');
    $quote = payment_quote_kobo($baseKobo); $quote['recipient'] = $recipient; return $quote;
}
function payment_keys_ready(): bool { return str_starts_with(payment_setting('paystack_secret_key', ''), 'sk_') && payment_setting('paystack_public_key', '') !== ''; }
function payment_callback_url(): string {
    $configured = trim(payment_setting('paystack_callback_url', ''));
    if ($configured !== '') return $configured;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') return '';
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . $path . '/paystack_callback.php';
}
function payment_api(string $method, string $endpoint, ?array $payload = null): array {
    $secret = payment_setting('paystack_secret_key', '');
    if (!str_starts_with($secret, 'sk_')) throw new RuntimeException('Enter a valid Paystack secret key in Payment settings first.');
    if (!function_exists('curl_init')) throw new RuntimeException('The server needs PHP cURL enabled for Paystack payments.');
    $curl = curl_init('https://api.paystack.co' . $endpoint);
    $headers = ['Authorization: Bearer ' . $secret, 'Content-Type: application/json', 'Accept: application/json'];
    curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true]);
    if ($payload !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
    $body = curl_exec($curl); $code = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $error = curl_error($curl); curl_close($curl);
    if ($body === false || $error !== '') throw new RuntimeException('Could not contact Paystack. Please try again.');
    $decoded = json_decode($body, true);
    if ($code < 200 || $code >= 300 || !is_array($decoded) || empty($decoded['status'])) throw new RuntimeException($decoded['message'] ?? 'Paystack could not initialize this payment.');
    return $decoded;
}
function payment_reference(string $orderNumber): string { return 'ZL-' . preg_replace('/[^A-Za-z0-9]/', '', $orderNumber) . '-' . strtoupper(bin2hex(random_bytes(5))); }
function payment_finalize_reference(string $reference): ?int {
    payment_migrate();
    $verified = payment_api('GET', '/transaction/verify/' . rawurlencode($reference));
    $data = $verified['data'] ?? [];
    if (($data['status'] ?? '') !== 'success') return null;
    $pdo = db(); $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT pt.*,o.payment_status,o.id order_id,o.payment_total FROM payment_transactions pt JOIN orders o ON o.id=pt.order_id WHERE pt.reference_code=? FOR UPDATE'); $stmt->execute([$reference]); $transaction = $stmt->fetch();
        if (!$transaction) throw new RuntimeException('Payment transaction not found.');
        if ($transaction['status'] === 'success') { $pdo->commit(); return (int)$transaction['order_id']; }
        if ((int)($data['amount'] ?? 0) !== payment_decimal_to_kobo($transaction['gross_amount']) || ($data['currency'] ?? '') !== 'NGN') throw new RuntimeException('The verified payment amount does not match this order.');
        $items = $pdo->prepare('SELECT product_id,quantity FROM order_items WHERE order_id=? FOR UPDATE'); $items->execute([$transaction['order_id']]);
        foreach ($items->fetchAll() as $item) {
            if (!$item['product_id']) continue;
            $stock = $pdo->prepare('SELECT stock FROM products WHERE id=? FOR UPDATE'); $stock->execute([$item['product_id']]);
            if ((int)$stock->fetchColumn() < (int)$item['quantity']) throw new RuntimeException('An item is no longer available in the requested quantity.');
        }
        $reduce = $pdo->prepare('UPDATE products SET stock=stock-? WHERE id=?');
        $items->execute([$transaction['order_id']]); foreach ($items->fetchAll() as $item) if ($item['product_id']) $reduce->execute([(int)$item['quantity'], (int)$item['product_id']]);
        $raw = json_encode($data, JSON_THROW_ON_ERROR); $actualFee = isset($data['fees']) ? payment_kobo_to_naira((int)$data['fees']) : null;
        $pdo->prepare('UPDATE payment_transactions SET status="success",actual_paystack_fee=?,raw_response=?,paid_at=NOW() WHERE id=?')->execute([$actualFee, $raw, $transaction['id']]);
        $pdo->prepare('UPDATE orders SET payment_status="paid",status="Confirmed" WHERE id=?')->execute([$transaction['order_id']]);
        $pdo->commit(); return (int)$transaction['order_id'];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
