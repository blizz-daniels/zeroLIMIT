<?php
declare(strict_types=1);

require __DIR__ . '/payments.php';

$cases = [
    ['amount' => '1000.00', 'expect_flat' => false],
    ['amount' => '10000.00', 'expect_flat' => true],
    ['amount' => '200000.00', 'expect_cap' => true],
];

foreach ($cases as $case) {
    $quote = payment_quote_naira($case['amount']);
    if ($quote['expected_net_kobo'] !== $quote['base_kobo']) {
        throw new RuntimeException('Gross-up did not preserve the recipient net amount.');
    }
    if ($quote['gross_kobo'] - $quote['paystack_kobo'] < $quote['base_kobo'] + $quote['service_kobo']) {
        throw new RuntimeException('Gross amount cannot cover the configured target.');
    }
    if (($case['expect_flat'] ?? false) && $quote['paystack_kobo'] <= 10000) {
        throw new RuntimeException('Expected the configured fixed Paystack fee.');
    }
    if (($case['expect_cap'] ?? false) && $quote['paystack_kobo'] !== 200000) {
        throw new RuntimeException('Expected the configured Paystack fee cap.');
    }
}

echo "Payment gross-up checks passed.\n";
