<?php
declare(strict_types=1);

function payment_cart_display_quote(array $lines): array
{
    $base = payment_cart_base_kobo($lines);
    $service = payment_ceil_div($base * payment_fee_settings()['service_bps'], 10000);
    return payment_quote_with_target($base, $service);
}

function customer_cart_page(): void
{
    $lines = cart_lines();
    render_header('Your Bag'); ?>
    <section class="simple-heading"><p class="eyebrow">your selection</p><h1>Your <i>bag.</i></h1></section>
    <?php if (!$lines): ?>
        <section class="empty-state"><p>Your bag is waiting for something lovely.</p><a class="button" href="index.php?page=shop">Explore the shop <span>&rarr;</span></a></section>
    <?php else: $quote = payment_cart_display_quote($lines); ?>
        <section class="cart-layout"><form method="post" class="cart-items"><?=csrf_input()?><input type="hidden" name="action" value="update_cart">
        <?php foreach ($lines as $line): ?>
            <article class="cart-line"><img src="<?=h($line['image'])?>" alt="<?=h($line['name'])?>"><div><p><?=h($line['category'])?></p><h3><?=h($line['name'])?></h3><?php if($line['selected_size']):?><small>Size: <?=h($line['selected_size'])?></small><?php endif;?><?php if($line['selected_color']):?><small>Colour: <?=h($line['selected_color'])?></small><?php endif;?></div><label class="inline-qty">Qty <input type="number" min="1" name="quantities[<?=h($line['cart_key'])?>]" value="<?=$line['quantity']?>"></label><strong><?=payment_money_kobo(payment_decimal_to_kobo((string)$line['unit_price']) * (int)$line['quantity'])?></strong><button name="remove[<?=h($line['cart_key'])?>]" value="1" class="remove" aria-label="Remove <?=h($line['name'])?>">&times;</button></article>
        <?php endforeach; ?>
        <button class="text-link update">Update bag &rarr;</button></form>
        <aside class="order-summary checkout-summary" data-payment-cart-summary><p class="eyebrow">payment summary</p><h2>A few steps <i>away.</i></h2><div><span>Items / recipient amount</span><b><?=payment_money_kobo($quote['base_kobo'])?></b></div><div class="payment-line service"><span>Service charge</span><b><?=payment_money_kobo($quote['service_kobo'])?></b></div><div class="payment-line service"><span>Payment processing</span><b><?=payment_money_kobo($quote['paystack_kobo'])?></b></div><hr><div class="grand"><span>Total to pay</span><b><?=payment_money_kobo($quote['gross_kobo'])?></b></div><p class="payment-note">The total includes payment processing and service charges. This is the final amount charged by Paystack.</p><a class="button wide" href="index.php?page=checkout">Proceed to checkout <span>&rarr;</span></a><a class="text-link centered" href="index.php?page=shop">Continue shopping</a></aside></section>
    <?php endif; render_footer();
}

function customer_cart_update(): never
{
    verify_csrf();
    foreach ($_POST['quantities'] ?? [] as $key => $quantity) {
        if (!isset($_SESSION['cart'][$key])) continue;
        $quantity = (int)$quantity;
        if ($quantity < 1) unset($_SESSION['cart'][$key]);
        else $_SESSION['cart'][$key]['quantity'] = min($quantity, 99);
    }
    foreach ($_POST['remove'] ?? [] as $key => $_) unset($_SESSION['cart'][$key]);
    flash('notice', 'Your bag has been updated.');
    redirect('index.php?page=cart');
}
