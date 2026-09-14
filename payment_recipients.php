<?php
declare(strict_types=1);

function payment_recipient_details_migrate(): void
{
    payment_add_column(db(), 'payment_recipients', 'bank_name', 'VARCHAR(150) NULL');
    payment_add_column(db(), 'payment_recipients', 'bank_code', 'VARCHAR(30) NULL');
    payment_add_column(db(), 'payment_recipients', 'account_number', 'VARCHAR(30) NULL');
    payment_add_column(db(), 'payment_recipients', 'account_name', 'VARCHAR(150) NULL');
}

function payment_nigerian_banks(): array
{
    $cached = $_SESSION['paystack_nigerian_banks'] ?? null;
    if (is_array($cached) && ($cached['expires_at'] ?? 0) > time() && is_array($cached['items'] ?? null)) {
        return $cached['items'];
    }

    $result = payment_api('GET', '/bank?country=nigeria&perPage=100');
    $banks = [];
    foreach (($result['data'] ?? []) as $bank) {
        $code = trim((string)($bank['code'] ?? ''));
        $name = trim((string)($bank['name'] ?? ''));
        if ($code !== '' && $name !== '') $banks[] = ['code' => $code, 'name' => $name];
    }
    usort($banks, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    $_SESSION['paystack_nigerian_banks'] = ['expires_at' => time() + 900, 'items' => $banks];
    return $banks;
}

function payment_bank_by_code(string $bankCode): ?array
{
    foreach (payment_nigerian_banks() as $bank) {
        if (hash_equals($bank['code'], $bankCode)) return $bank;
    }
    return null;
}

function payment_create_bank_recipient(string $name, string $accountNumber, string $bankCode, string $description, bool $active): array
{
    $name = trim($name);
    $accountNumber = preg_replace('/\D+/', '', $accountNumber) ?? '';
    $bankCode = trim($bankCode);
    if ($name === '') throw new RuntimeException('Recipient name is required.');
    if (!preg_match('/^\d{10}$/', $accountNumber)) throw new RuntimeException('Enter a valid 10-digit Nigerian account number.');
    $bank = payment_bank_by_code($bankCode);
    if (!$bank) throw new RuntimeException('Choose a bank from the current Paystack bank list.');

    $resolved = payment_api('GET', '/bank/resolve?account_number=' . rawurlencode($accountNumber) . '&bank_code=' . rawurlencode($bankCode));
    $accountName = trim((string)($resolved['data']['account_name'] ?? ''));
    if ($accountName === '') throw new RuntimeException('Paystack could not confirm this account number.');

    $created = payment_api('POST', '/subaccount', [
        'business_name' => $name,
        'settlement_bank' => $bankCode,
        'account_number' => $accountNumber,
        'percentage_charge' => 0,
        'description' => $description !== '' ? $description : $name,
        'primary_contact_name' => $name,
    ]);
    $subaccount = $created['data'] ?? [];
    $subaccountCode = trim((string)($subaccount['subaccount_code'] ?? ''));
    if (!preg_match('/^ACCT_[A-Za-z0-9]+$/', $subaccountCode)) throw new RuntimeException('Paystack did not return a valid subaccount code.');

    $pdo = db();
    $pdo->prepare('INSERT INTO payment_recipients (name,subaccount_code,description,active,bank_name,bank_code,account_number,account_name) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$name, $subaccountCode, $description, $active ? 1 : 0, $bank['name'], $bankCode, $accountNumber, $accountName]);

    return ['subaccount_code' => $subaccountCode, 'account_name' => $accountName];
}

function payment_mask_account(?string $accountNumber): string
{
    $digits = preg_replace('/\D+/', '', (string)$accountNumber) ?? '';
    return $digits === '' ? '—' : str_repeat('•', max(0, strlen($digits) - 4)) . substr($digits, -4);
}
