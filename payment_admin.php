<?php
declare(strict_types=1);

require_once __DIR__ . '/payment_recipients.php';

function payment_admin_head(string $title): void { ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($title)?> | Zero Limit</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Mono:wght@400;500&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/admin.css"></head><body class="admin-body"><aside class="admin-sidebar"><a class="admin-logo" href="admin.php">ZERO<span>LIMIT</span><small>command centre</small></a><nav><a href="admin.php">&#9672; Overview</a><a href="admin.php?page=products">&#9633; Products</a><a class="active" href="admin.php?page=payments">&#8358; Payments</a><a href="admin.php?page=orders">&#9673; Orders</a><a href="admin.php?page=settings">&#9881; Settings</a></nav><div class="admin-user"><span><?=h($_SESSION['admin_name'] ?? 'Admin')?></span><form method="post" action="admin.php"><?=csrf_input()?><button name="action" value="logout">Sign out &rarr;</button></form></div></aside><main class="admin-main"><header class="admin-top"><div><p class="eyebrow">zero limit command centre</p><h1><?=h($title)?></h1></div><div><a class="visit-store" href="index.php" target="_blank">View storefront &nearr;</a><span class="avatar"><?=h(strtoupper(substr($_SESSION['admin_name'] ?? 'A', 0, 1)))?></span></div></header><?php if($notice=flash('admin_notice')):?><div class="admin-notice"><?=h($notice)?></div><?php endif;?>
<?php }
function payment_admin_footer(): void { ?></main></body></html><?php }

function payment_admin_is_number(string $value): bool { return preg_match('/^\d+(?:\.\d{1,2})?$/', trim($value)) === 1; }

function payment_admin_handle(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $pdo = db();
    try {
        if ($action === 'save_payment_settings') {
            $values = [
                'service_charge_percent' => trim((string)($_POST['service_charge_percent'] ?? '10')),
                'paystack_fee_percent' => trim((string)($_POST['paystack_fee_percent'] ?? '1.5')),
                'paystack_fee_fixed' => trim((string)($_POST['paystack_fee_fixed'] ?? '100')),
                'paystack_fee_waiver_threshold' => trim((string)($_POST['paystack_fee_waiver_threshold'] ?? '2500')),
                'paystack_fee_cap' => trim((string)($_POST['paystack_fee_cap'] ?? '2000')),
                'paystack_public_key' => trim((string)($_POST['paystack_public_key'] ?? '')),
                'paystack_callback_url' => trim((string)($_POST['paystack_callback_url'] ?? '')),
            ];
            foreach (['service_charge_percent', 'paystack_fee_percent'] as $field) {
                if (!payment_admin_is_number($values[$field]) || payment_percent_to_bps($values[$field]) >= 10000) throw new RuntimeException('Enter valid fee percentages below 100%.');
            }
            foreach (['paystack_fee_fixed', 'paystack_fee_waiver_threshold', 'paystack_fee_cap'] as $field) {
                if (!payment_admin_is_number($values[$field])) throw new RuntimeException('Fee values must be valid non-negative amounts.');
            }
            if ($values['paystack_public_key'] !== '' && !str_starts_with($values['paystack_public_key'], 'pk_')) throw new RuntimeException('The Paystack public key must start with pk_.');
            if ($values['paystack_callback_url'] !== '' && !filter_var($values['paystack_callback_url'], FILTER_VALIDATE_URL)) throw new RuntimeException('Enter a valid callback URL.');
            foreach ($values as $key => $value) payment_store_setting($pdo, $key, $value);
            $secret = trim((string)($_POST['paystack_secret_key'] ?? ''));
            if ($secret !== '') {
                if (!str_starts_with($secret, 'sk_')) throw new RuntimeException('The Paystack secret key must start with sk_.');
                payment_store_setting($pdo, 'paystack_secret_key', $secret);
                unset($_SESSION['paystack_nigerian_banks']);
            }
            flash('admin_notice', 'Payment fee and Paystack settings saved.');
        } elseif ($action === 'create_bank_recipient') {
            if (!str_starts_with(payment_setting('paystack_secret_key', ''), 'sk_')) throw new RuntimeException('Save a Paystack secret key before adding a recipient bank account.');
            $recipient = payment_create_bank_recipient(
                (string)($_POST['name'] ?? ''),
                (string)($_POST['account_number'] ?? ''),
                (string)($_POST['bank_code'] ?? ''),
                trim((string)($_POST['description'] ?? '')),
                isset($_POST['active'])
            );
            flash('admin_notice', 'Recipient saved. Paystack subaccount ' . $recipient['subaccount_code'] . ' was created for ' . $recipient['account_name'] . '.');
        } elseif ($action === 'assign_recipient') {
            $productId = (int)($_POST['product_id'] ?? 0);
            $recipientId = (int)($_POST['recipient_id'] ?? 0);
            if (!$productId || !payment_get_recipient($recipientId)) throw new RuntimeException('Choose an active recipient before assigning a product.');
            $pdo->prepare('UPDATE products SET recipient_id=? WHERE id=?')->execute([$recipientId, $productId]);
            flash('admin_notice', 'Product recipient assigned.');
        }
    } catch (Throwable $error) {
        flash('admin_notice', $error->getMessage());
    }
    redirect('admin.php?page=payments');
}

function payment_admin_page(): void
{
    payment_migrate();
    payment_recipient_details_migrate();
    payment_admin_handle();
    $pdo = db();
    $settings = site_settings();
    $recipients = $pdo->query('SELECT * FROM payment_recipients ORDER BY active DESC,name')->fetchAll();
    $products = $pdo->query('SELECT p.*,r.name recipient_name,r.subaccount_code FROM products p LEFT JOIN payment_recipients r ON r.id=p.recipient_id ORDER BY p.name')->fetchAll();
    $banks = [];
    $bankError = null;
    if (str_starts_with(payment_setting('paystack_secret_key', ''), 'sk_')) {
        try { $banks = payment_nigerian_banks(); } catch (Throwable $error) { $bankError = 'The Paystack bank list could not be loaded. Check your secret key and try again.'; }
    } else {
        $bankError = 'Save a Paystack secret key in Payment configuration to load supported Nigerian banks.';
    }
    payment_admin_head('Payments'); ?>
    <section class="panel"><p class="eyebrow">gross-up preview</p><h2>What the customer pays</h2><p class="admin-empty">Prices are calculated in kobo. The recipient net is protected from the service charge and Paystack fee.</p><div class="admin-table-wrap"><table><thead><tr><th>Recipient amount</th><th>Service charge</th><th>Paystack fee</th><th>Customer pays</th><th>Expected recipient net</th></tr></thead><tbody><?php foreach([1000,2500,10000,50000] as $amount):$quote=payment_quote_naira($amount);?><tr><td><?=money((float)$amount)?></td><td><?=payment_money_kobo($quote['service_kobo'])?></td><td><?=payment_money_kobo($quote['paystack_kobo'])?></td><td><b><?=payment_money_kobo($quote['gross_kobo'])?></b></td><td><?=payment_money_kobo($quote['expected_net_kobo'])?></td></tr><?php endforeach;?></tbody></table></div></section>
    <section class="admin-two-col"><form method="post" class="settings-form panel"><?=csrf_input()?><input type="hidden" name="action" value="save_payment_settings"><p class="eyebrow">Paystack & fee rules</p><h2>Payment configuration</h2><div class="editor-grid"><label>Service charge %<input required name="service_charge_percent" type="number" min="0" max="99.99" step="0.01" value="<?=h($settings['service_charge_percent'] ?? '10')?>"></label><label>Paystack fee %<input required name="paystack_fee_percent" type="number" min="0" max="99.99" step="0.01" value="<?=h($settings['paystack_fee_percent'] ?? '1.5')?>"></label><label>Paystack fixed fee (&#8358;)<input required name="paystack_fee_fixed" type="number" min="0" step="0.01" value="<?=h($settings['paystack_fee_fixed'] ?? '100')?>"></label><label>Fixed fee waived below (&#8358;)<input required name="paystack_fee_waiver_threshold" type="number" min="0" step="0.01" value="<?=h($settings['paystack_fee_waiver_threshold'] ?? '2500')?>"></label><label>Paystack fee cap (&#8358;)<input required name="paystack_fee_cap" type="number" min="0" step="0.01" value="<?=h($settings['paystack_fee_cap'] ?? '2000')?>"></label><label>Paystack public key<input name="paystack_public_key" autocomplete="off" placeholder="pk_live_..." value="<?=h($settings['paystack_public_key'] ?? '')?>"></label><label class="wide-field">Paystack secret key <small>Leave blank to keep the current key.</small><input name="paystack_secret_key" autocomplete="new-password" type="password" placeholder="sk_live_..."></label><label class="wide-field">Callback URL <small>Optional; leave blank to auto-detect.</small><input name="paystack_callback_url" type="url" placeholder="https://your-domain.com/paystack_callback.php" value="<?=h($settings['paystack_callback_url'] ?? '')?>"></label></div><button class="admin-button">Save payment settings &rarr;</button></form>
    <form method="post" class="settings-form panel recipient-form"><?=csrf_input()?><input type="hidden" name="action" value="create_bank_recipient"><p class="eyebrow">recipient bank account</p><h2>Add recipient</h2><label>Recipient / business name<input required name="name" autocomplete="organization" placeholder="e.g. Zero Limit Store"></label><label>Account number<input required name="account_number" inputmode="numeric" autocomplete="off" pattern="[0-9]{10}" minlength="10" maxlength="10" placeholder="10-digit account number"></label><label>Bank <span class="bank-code-badge" data-bank-code>—</span><div class="bank-select-row"><select required name="bank_code" data-bank-select <?=!$banks?'disabled':''?>><option value=""><?= $banks ? 'Select a bank' : 'Bank list unavailable' ?></option><?php foreach($banks as $bank):?><option value="<?=h($bank['code'])?>" data-bank-code="<?=h($bank['code'])?>"><?=h($bank['name'])?></option><?php endforeach;?></select></div></label><label>Description <small>(optional)</small><input name="description" placeholder="Settlement account description"></label><label class="switch-label"><input type="checkbox" name="active" checked> Available for product assignment</label><?php if($bankError):?><p class="payment-alert"><?=h($bankError)?></p><?php else:?><p class="payment-note">We verify the account, then Paystack creates and saves its subaccount code automatically.</p><?php endif;?><button class="admin-button" <?=!$banks?'disabled':''?>>Create recipient &rarr;</button></form></section>
    <section class="panel"><div class="panel-heading"><div><p class="eyebrow">payment destination</p><h2>Recipients</h2></div></div><?php if($recipients):?><div class="admin-table-wrap"><table><thead><tr><th>Recipient</th><th>Bank / code</th><th>Account number</th><th>Paystack subaccount</th><th>Status</th></tr></thead><tbody><?php foreach($recipients as $recipient):?><tr><td><b><?=h($recipient['name'])?></b><small><?=h((string)$recipient['description'])?></small></td><td><?=h($recipient['bank_name'] ?: 'Legacy recipient')?><small><?=h($recipient['bank_code'] ?: '—')?></small></td><td><?=h(payment_mask_account($recipient['account_number'] ?? null))?><small><?=h((string)($recipient['account_name'] ?? ''))?></small></td><td><code><?=h($recipient['subaccount_code'])?></code></td><td><?= $recipient['active'] ? 'Active' : 'Inactive' ?></td></tr><?php endforeach;?></tbody></table></div><?php else:?><div class="admin-empty">Add and verify a recipient bank account before sending customers to checkout.</div><?php endif;?></section>
    <section class="panel"><div class="panel-heading"><div><p class="eyebrow">product settlement</p><h2>Assign recipients and review pricing</h2></div></div><div class="admin-table-wrap"><table><thead><tr><th>Product</th><th>Net amount to receive</th><th>Service</th><th>Paystack</th><th>Customer pays</th><th>Recipient</th></tr></thead><tbody><?php foreach($products as $product):$base=$product['discount_price']!==null?(string)$product['discount_price']:(string)$product['price'];$quote=payment_quote_naira($base);?><tr><td><b><?=h($product['name'])?></b></td><td><?=money((float)$base)?></td><td><?=payment_money_kobo($quote['service_kobo'])?></td><td><?=payment_money_kobo($quote['paystack_kobo'])?></td><td><b><?=payment_money_kobo($quote['gross_kobo'])?></b></td><td><form method="post" class="inline-form"><?=csrf_input()?><input type="hidden" name="action" value="assign_recipient"><input type="hidden" name="product_id" value="<?=$product['id']?>"><select name="recipient_id" onchange="this.form.submit()"><option value="">Not assigned</option><?php foreach($recipients as $recipient):if(!$recipient['active'])continue;?><option value="<?=$recipient['id']?>" <?=$product['recipient_id']==$recipient['id']?'selected':''?>><?=h($recipient['name'])?></option><?php endforeach;?></select></form></td></tr><?php endforeach;?></tbody></table></div></section>
    <script>document.querySelectorAll('[data-bank-select]').forEach(function(select){var badge=select.closest('label').querySelector('[data-bank-code]');var update=function(){badge.textContent=select.options[select.selectedIndex].dataset.bankCode||'—';};select.addEventListener('change',update);update();});</script>
    <?php payment_admin_footer();
}
