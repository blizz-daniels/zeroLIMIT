<?php
require __DIR__ . '/config.php';

$page = $_GET['page'] ?? 'home';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add_cart') {
        $id = (int)($_POST['product_id'] ?? 0);
        $product = db()->prepare('SELECT * FROM products WHERE id=? AND status="active"'); $product->execute([$id]); $product = $product->fetch();
        $size = trim($_POST['size'] ?? ''); $color = trim($_POST['color'] ?? ''); $quantity = max(1, (int)($_POST['quantity'] ?? 1));
        if (!$product || $product['stock'] < 1) flash('notice', 'Sorry, this item is currently unavailable.');
        else {
            $key = $id . '|' . $size . '|' . $color; $existing = $_SESSION['cart'][$key]['quantity'] ?? 0;
            $_SESSION['cart'][$key] = ['product_id'=>$id,'size'=>$size,'color'=>$color,'quantity'=>min($quantity + $existing, (int)$product['stock'])];
            flash('notice', $product['name'] . ' is in your bag.');
        }
        redirect($_POST['return_to'] ?? 'index.php?page=cart');
    }
    if ($action === 'update_cart') {
        foreach ($_POST['quantities'] ?? [] as $key => $quantity) {
            if (!isset($_SESSION['cart'][$key])) continue;
            $quantity = (int)$quantity; if ($quantity < 1) unset($_SESSION['cart'][$key]); else $_SESSION['cart'][$key]['quantity'] = min($quantity, 99);
        }
        foreach ($_POST['remove'] ?? [] as $key => $_) unset($_SESSION['cart'][$key]);
        flash('notice', 'Your bag has been updated.'); redirect('index.php?page=cart');
    }
    if ($action === 'place_order') {
        $required = ['full_name'=>'Full name','phone'=>'Phone number','state'=>'State','city'=>'City/Town','address'=>'Delivery address'];
        foreach ($required as $field=>$label) if (trim($_POST[$field] ?? '') === '') { flash('notice', "$label is required."); redirect('index.php?page=checkout'); }
        $lines = cart_lines(); if (!$lines) { flash('notice','Your bag is empty.'); redirect('index.php?page=shop'); }
        $pdo = db(); $pdo->beginTransaction();
        try {
            $subtotal = 0;
            foreach ($lines as $line) {
                $stock = $pdo->prepare('SELECT stock,status FROM products WHERE id=? FOR UPDATE'); $stock->execute([$line['id']]); $availability=$stock->fetch();
                if (!$availability || $availability['status'] !== 'active' || $availability['stock'] < $line['quantity']) throw new RuntimeException($line['name'] . ' no longer has enough stock.');
                $subtotal += $line['line_total'];
            }
            $fee = $subtotal >= (float)setting('free_delivery_threshold','75') ? 0 : (float)setting('delivery_fee','5');
            $customer = $pdo->prepare('INSERT INTO customers (full_name,phone,email) VALUES (?,?,?)'); $customer->execute([trim($_POST['full_name']),trim($_POST['phone']),trim($_POST['email'] ?? '')]);
            $number = 'VEL-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $order = $pdo->prepare('INSERT INTO orders (order_number,customer_id,state,city,address,landmark,subtotal,delivery_fee,total) VALUES (?,?,?,?,?,?,?,?,?)');
            $order->execute([$number,$pdo->lastInsertId(),trim($_POST['state']),trim($_POST['city']),trim($_POST['address']),trim($_POST['landmark'] ?? ''),$subtotal,$fee,$subtotal+$fee]); $orderId=(int)$pdo->lastInsertId();
            $item = $pdo->prepare('INSERT INTO order_items (order_id,product_id,product_name,product_image,selected_size,selected_color,quantity,unit_price,subtotal) VALUES (?,?,?,?,?,?,?,?,?)');
            $reduce = $pdo->prepare('UPDATE products SET stock=stock-? WHERE id=?');
            foreach ($lines as $line) { $item->execute([$orderId,$line['id'],$line['name'],$line['image'],$line['selected_size'],$line['selected_color'],$line['quantity'],$line['unit_price'],$line['line_total']]); $reduce->execute([$line['quantity'],$line['id']]); }
            $pdo->commit(); $_SESSION['cart']=[]; redirect('index.php?page=confirmation&id='.$orderId);
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash('notice', $e->getMessage()); redirect('index.php?page=checkout'); }
    }
}

if ($page === 'home') home_page();
elseif ($page === 'shop' || $page === 'new') shop_page($page === 'new');
elseif ($page === 'product') product_page();
elseif ($page === 'cart') cart_page();
elseif ($page === 'checkout') checkout_page();
elseif ($page === 'confirmation') confirmation_page();
elseif ($page === 'about' || $page === 'contact') information_page($page);
else { http_response_code(404); render_header('Not found'); echo '<section class="empty-state"><p class="eyebrow">404</p><h1>That page slipped away.</h1><a class="button" href="index.php">Back home</a></section>'; render_footer(); }

function home_page(): void {
    $pdo = db(); $categories=$pdo->query('SELECT * FROM categories WHERE active=1 ORDER BY id LIMIT 7')->fetchAll();
    $featured=$pdo->query('SELECT p.*,c.name category FROM products p JOIN categories c ON c.id=p.category_id WHERE p.status="active" AND p.is_featured=1 ORDER BY p.created_at DESC LIMIT 4')->fetchAll();
    $new=$pdo->query('SELECT p.*,c.name category FROM products p JOIN categories c ON c.id=p.category_id WHERE p.status="active" AND p.is_new=1 ORDER BY p.created_at DESC LIMIT 4')->fetchAll();
    render_header(); ?>
    <section class="hero"><div class="hero-copy"><p class="eyebrow">the veloura edit · est. 2026</p><h1>Beauty. Style.<br><i>All in one place.</i></h1><p>Beautiful things for your closet, your ritual, and your every day.</p><div class="button-row"><a class="button" href="index.php?page=shop">Shop now <span>→</span></a><a class="text-link" href="#collections">Explore collection <span>↓</span></a></div></div><div class="hero-image"><div class="hero-image-caption">Curated beauty for <em>your</em> rhythm.</div></div><div class="hero-orbit">NEW<br>SEASON<br><b>✦</b></div></section>
    <section class="section category-section" id="collections"><div class="section-heading"><div><p class="eyebrow">shop by mood</p><h2>Find your little <i>luxury.</i></h2></div><a class="text-link" href="index.php?page=shop">View everything →</a></div><div class="category-grid"><?php foreach($categories as $index=>$c): ?><a href="index.php?page=shop&category=<?=urlencode($c['name'])?>" class="category-card cat-<?=$index%5?>"><span><?=h($c['name'])?></span><small><?=h($c['description'])?></small><b>↗</b></a><?php endforeach; ?></div></section>
    <section class="section products-section"><div class="section-heading"><div><p class="eyebrow">our current loves</p><h2>Featured <i>finds.</i></h2></div><a class="text-link" href="index.php?page=shop">Shop the edit →</a></div><div class="product-grid"><?php foreach($featured as $product) product_card($product); ?></div></section>
    <section class="editorial"><div class="editorial-photo"></div><div class="editorial-copy"><p class="eyebrow">a soft reminder</p><h2>Take a little more time <i>for yourself.</i></h2><p>Care is not an indulgence. It lives in the things that make an ordinary Tuesday feel more like you.</p><a class="button light" href="index.php?page=shop&category=Skincare">Explore skincare <span>→</span></a></div></section>
    <section class="section products-section"><div class="section-heading"><div><p class="eyebrow">just landed</p><h2>New on the <i>scene.</i></h2></div><a class="text-link" href="index.php?page=new">See new arrivals →</a></div><div class="product-grid"><?php foreach($new as $product) product_card($product); ?></div></section>
    <section class="values"><article><span>✦</span><h3>Thoughtfully chosen</h3><p>Every item earns a place in the edit.</p></article><article><span>◌</span><h3>Easy ordering</h3><p>A seamless little journey from cart to door.</p></article><article><span>♡</span><h3>Made to delight</h3><p>Small details, lovely packages, happy you.</p></article><article><span>↗</span><h3>Reliable delivery</h3><p>Your newest favourite is on its way.</p></article></section>
    <section class="cta"><p class="eyebrow">your everyday, elevated</p><h2>The things you love,<br><i>all in one beautiful place.</i></h2><a class="button" href="index.php?page=shop">Begin your edit <span>→</span></a></section>
    <?php render_footer();
}

function product_card(array $p): void { $price=product_price($p); $discount=$p['discount_price'] !== null ? round((1-$price/(float)$p['price'])*100) : 0; ?>
    <article class="product-card"><a class="product-image" href="index.php?page=product&id=<?=$p['id']?>"><img src="<?=h($p['image'])?>" alt="<?=h($p['name'])?>" loading="lazy"><?php if($p['stock'] < 1): ?><span class="product-badge sold">Sold out</span><?php elseif($p['is_new']): ?><span class="product-badge">New</span><?php endif; ?><button type="button" class="wishlist" aria-label="Save item">♡</button></a><div class="product-details"><p><?=h($p['category'])?></p><h3><a href="index.php?page=product&id=<?=$p['id']?>"><?=h($p['name'])?></a></h3><div class="price"><?php if($discount): ?><del><?=money((float)$p['price'])?></del><span><?=money($price)?></span><small>-<?=$discount?>%</small><?php else: ?><span><?=money($price)?></span><?php endif; ?></div><form method="post" class="card-cart"><?=csrf_input()?><input type="hidden" name="action" value="add_cart"><input type="hidden" name="product_id" value="<?=$p['id']?>"><input type="hidden" name="return_to" value="index.php?page=shop"><button <?=$p['stock'] < 1 ? 'disabled' : ''?>><?=$p['stock'] < 1 ? 'Unavailable' : 'Add to bag +'?></button><a href="index.php?page=product&id=<?=$p['id']?>">Quick view</a></form></div></article>
<?php }

function shop_page(bool $onlyNew): void {
    $pdo=db(); $categories=$pdo->query('SELECT name FROM categories WHERE active=1 ORDER BY name')->fetchAll(); $where=['p.status="active"']; $params=[];
    if ($onlyNew) $where[]='p.is_new=1';
    $query=trim($_GET['q'] ?? ''); if($query !== '') { $where[]='(p.name LIKE ? OR p.description LIKE ? OR c.name LIKE ?)'; $params=array_merge($params, ["%$query%","%$query%","%$query%"]); }
    $category=trim($_GET['category'] ?? ''); if($category !== '') { $where[]='c.name=?'; $params[]=$category; }
    $gender=trim($_GET['gender'] ?? ''); if($gender !== '') { $where[]='p.gender=?'; $params[]=$gender; }
    $sort=$_GET['sort'] ?? 'newest'; $ordering=['low'=>'COALESCE(p.discount_price,p.price) ASC','high'=>'COALESCE(p.discount_price,p.price) DESC','popular'=>'p.views DESC','newest'=>'p.created_at DESC'];
    $stmt=$pdo->prepare('SELECT p.*,c.name category FROM products p JOIN categories c ON c.id=p.category_id WHERE '.implode(' AND ',$where).' ORDER BY '.($ordering[$sort]??$ordering['newest'])); $stmt->execute($params); $products=$stmt->fetchAll();
    render_header($onlyNew ? 'New Arrivals' : 'Shop'); ?>
    <section class="shop-intro"><p class="eyebrow"><?=$onlyNew ? 'freshly added' : 'shop the veloura edit'?></p><h1><?=$onlyNew ? 'New <i>arrivals.</i>' : 'Everything, <i>beautifully chosen.</i>'?></h1><p><?=$onlyNew ? 'A new reason to make space in your routine.' : 'Beauty, style, and the little essentials that make your day feel more yours.'?></p></section>
    <section class="shop-layout"><aside class="filters"><form method="get"><input type="hidden" name="page" value="<?=$onlyNew?'new':'shop'?>"><label>Search<input name="q" value="<?=h($query)?>" placeholder="Lip oil, knit, tote..."></label><label>Category<select name="category"><option value="">All categories</option><?php foreach($categories as $c): ?><option <?=$category===$c['name']?'selected':''?>><?=h($c['name'])?></option><?php endforeach;?></select></label><label>For<select name="gender"><option value="">Everyone</option><?php foreach(['Women','Men','Unisex'] as $g): ?><option <?=$gender===$g?'selected':''?>><?=$g?></option><?php endforeach;?></select></label><label>Sort by<select name="sort"><option value="newest" <?=$sort==='newest'?'selected':''?>>Newest</option><option value="low" <?=$sort==='low'?'selected':''?>>Price: low to high</option><option value="high" <?=$sort==='high'?'selected':''?>>Price: high to low</option><option value="popular" <?=$sort==='popular'?'selected':''?>>Most loved</option></select></label><button class="button">Apply filters <span>→</span></button></form></aside><div class="shop-products"><div class="results-bar"><span><?=count($products)?> beautiful <?=count($products)===1?'find':'finds'?></span><button class="filter-toggle">Filter +</button></div><?php if($products): ?><div class="product-grid"><?php foreach($products as $product) product_card($product); ?></div><?php else: ?><div class="empty-state"><p class="eyebrow">no matches yet</p><h2>Try a different <i>search.</i></h2><a class="button" href="index.php?page=shop">See all products</a></div><?php endif;?></div></section>
    <?php render_footer();
}

function product_page(): void {
    $id=(int)($_GET['id']??0); $stmt=db()->prepare('SELECT p.*,c.name category FROM products p JOIN categories c ON c.id=p.category_id WHERE p.id=? AND p.status="active"'); $stmt->execute([$id]); $p=$stmt->fetch(); if(!$p){redirect('index.php?page=shop');} db()->prepare('UPDATE products SET views=views+1 WHERE id=?')->execute([$id]);
    $images=db()->prepare('SELECT path FROM product_images WHERE product_id=?');$images->execute([$id]);$images=$images->fetchAll(); $sizes=array_filter(array_map('trim',explode(',',$p['sizes']??'')));$colors=array_filter(array_map('trim',explode(',',$p['colors']??'')));$price=product_price($p); $discount=$p['discount_price']!==null?round((1-$price/(float)$p['price'])*100):0;
    render_header($p['name']); ?>
    <section class="crumbs"><a href="index.php">Home</a> / <a href="index.php?page=shop">Shop</a> / <?=h($p['category'])?></section><section class="product-page"><div class="product-gallery"><div class="main-product-photo"><img src="<?=h($p['image'])?>" alt="<?=h($p['name'])?>"><?php if($p['stock']<1):?><span class="product-badge sold">Out of stock</span><?php endif;?></div><?php if($images):?><div class="thumbs"><img src="<?=h($p['image'])?>" alt=""><!-- Additional uploaded product images --><?php foreach($images as $image):?><img src="<?=h($image['path'])?>" alt="<?=h($p['name'])?> detail"><?php endforeach;?></div><?php endif;?></div><div class="product-info"><p class="eyebrow"><?=h($p['category'])?> · <?=h($p['gender'])?></p><h1><?=h($p['name'])?></h1><div class="price large"><?php if($discount):?><del><?=money((float)$p['price'])?></del><?php endif;?><span><?=money($price)?></span><?php if($discount):?><small>Save <?=$discount?>%</small><?php endif;?></div><p class="product-description"><?=nl2br(h($p['description']))?></p><div class="stock <?=$p['stock']<1?'none':''?>"><?=$p['stock']<1?'Out of stock':$p['stock'].' currently available'?></div><form method="post" class="product-form"><?=csrf_input()?><input type="hidden" name="action" value="add_cart"><input type="hidden" name="product_id" value="<?=$p['id']?>"><input type="hidden" name="return_to" value="index.php?page=product&id=<?=$p['id']?>"><?php if($sizes):?><fieldset><legend>Choose size</legend><div class="option-pills"><?php foreach($sizes as $i=>$size):?><label><input type="radio" name="size" value="<?=h($size)?>" <?=$i===0?'checked':''?>><span><?=h($size)?></span></label><?php endforeach;?></div></fieldset><?php endif;?><?php if($colors):?><fieldset><legend>Choose colour</legend><div class="option-pills colors"><?php foreach($colors as $i=>$color):?><label><input type="radio" name="color" value="<?=h($color)?>" <?=$i===0?'checked':''?>><span><?=h($color)?></span></label><?php endforeach;?></div></fieldset><?php endif;?><div class="purchase-row"><label class="quantity-control"><button type="button" data-qty="minus">−</button><input name="quantity" value="1" min="1" max="<?=$p['stock']?>" type="number"><button type="button" data-qty="plus">+</button></label><button class="button" <?=$p['stock']<1?'disabled':''?>><?=$p['stock']<1?'Currently unavailable':'Add to bag'?> <span>→</span></button></div></form><div class="product-promises"><span>✦ Secure, simple checkout</span><span>↗ Delivery tailored to you</span></div></div></section>
    <?php render_footer();
}

function cart_page(): void { $lines=cart_lines(); render_header('Your Bag'); ?>
    <section class="simple-heading"><p class="eyebrow">your selection</p><h1>Your <i>bag.</i></h1></section><?php if(!$lines):?><section class="empty-state"><p>Your bag is waiting for something lovely.</p><a class="button" href="index.php?page=shop">Explore the shop <span>→</span></a></section><?php else: ?><section class="cart-layout"><form method="post" class="cart-items"><?=csrf_input()?><input type="hidden" name="action" value="update_cart"><?php foreach($lines as $line):?><article class="cart-line"><img src="<?=h($line['image'])?>" alt="<?=h($line['name'])?>"><div><p><?=h($line['category'])?></p><h3><?=h($line['name'])?></h3><?php if($line['selected_size']):?><small>Size: <?=h($line['selected_size'])?></small><?php endif;?><?php if($line['selected_color']):?><small>Colour: <?=h($line['selected_color'])?></small><?php endif;?></div><label class="inline-qty">Qty <input type="number" min="1" name="quantities[<?=h($line['cart_key'])?>]" value="<?=$line['quantity']?>"></label><strong><?=money($line['line_total'])?></strong><button name="remove[<?=h($line['cart_key'])?>]" value="1" class="remove" aria-label="Remove <?=h($line['name'])?>">×</button></article><?php endforeach;?><button class="text-link update">Update bag →</button></form><aside class="order-summary"><p class="eyebrow">order summary</p><h2>A few steps <i>away.</i></h2><div><span>Subtotal</span><b><?=money(cart_total())?></b></div><div><span>Delivery</span><b><?=cart_total()>=(float)setting('free_delivery_threshold','75')?'Free':'At checkout'?></b></div><hr><div class="grand"><span>Total</span><b><?=money(cart_total())?></b></div><a class="button wide" href="index.php?page=checkout">Proceed to checkout <span>→</span></a><a class="text-link centered" href="index.php?page=shop">Continue shopping</a></aside></section><?php endif; render_footer(); }

function checkout_page(): void { $lines=cart_lines(); if(!$lines){flash('notice','Your bag is empty.');redirect('index.php?page=shop');}$subtotal=cart_total();$fee=$subtotal>=(float)setting('free_delivery_threshold','75')?0:(float)setting('delivery_fee','5');render_header('Checkout'); ?>
    <section class="checkout-header"><a href="index.php?page=cart">← Back to bag</a><h1>Delivery <i>details.</i></h1><p>Almost there. Tell us where your new favourites are heading.</p></section><section class="checkout-layout"><form method="post" class="checkout-form"><?=csrf_input()?><input type="hidden" name="action" value="place_order"><h2>Customer information</h2><div class="form-grid"><label>Full name<input required name="full_name" autocomplete="name"></label><label>Phone number<input required name="phone" autocomplete="tel" type="tel"></label><label class="full">Email address <small>(for order updates)</small><input name="email" autocomplete="email" type="email"></label></div><h2>Delivery information</h2><div class="form-grid"><label>State<input required name="state" autocomplete="address-level1"></label><label>City / Town<input required name="city" autocomplete="address-level2"></label><label class="full">Full delivery address<textarea required name="address" autocomplete="street-address" rows="3"></textarea></label><label class="full">Landmark or additional instructions <small>(optional)</small><input name="landmark"></label></div><button class="button wide">Place order <span>→</span></button><p class="payment-note">No account required. We’ll contact you to confirm delivery.</p></form><aside class="order-summary checkout-summary"><p class="eyebrow">your order</p><?php foreach($lines as $line):?><div class="summary-product"><img src="<?=h($line['image'])?>" alt=""><span><?=h($line['name'])?><small>× <?=$line['quantity']?><?= $line['selected_size']?' · '.h($line['selected_size']):'' ?></small></span><b><?=money($line['line_total'])?></b></div><?php endforeach;?><hr><div><span>Subtotal</span><b><?=money($subtotal)?></b></div><div><span>Delivery</span><b><?=$fee?money($fee):'Free'?></b></div><hr><div class="grand"><span>Total</span><b><?=money($subtotal+$fee)?></b></div></aside></section>
    <?php render_footer(); }

function confirmation_page(): void { $id=(int)($_GET['id']??0);$stmt=db()->prepare('SELECT o.*,c.full_name,c.phone,c.email FROM orders o JOIN customers c ON c.id=o.customer_id WHERE o.id=?');$stmt->execute([$id]);$order=$stmt->fetch();if(!$order)redirect('index.php');$items=db()->prepare('SELECT * FROM order_items WHERE order_id=?');$items->execute([$id]);render_header('Order Received'); ?>
    <section class="confirmation"><div class="checkmark">✓</div><p class="eyebrow">order received</p><h1>It’s on its <i>way.</i></h1><p>Thank you, <?=h(explode(' ',$order['full_name'])[0])?>. We’ve received your order and will contact you shortly to confirm delivery.</p><div class="confirmation-number">Order <b><?=h($order['order_number'])?></b><span class="status <?=order_status_class($order['status'])?>"><?=h($order['status'])?></span></div><div class="confirmation-grid"><section><h3>Order details</h3><?php foreach($items->fetchAll() as $item):?><div><span><?=h($item['product_name'])?> × <?=$item['quantity']?></span><b><?=money((float)$item['subtotal'])?></b></div><?php endforeach;?><div class="total"><span>Total</span><b><?=money((float)$order['total'])?></b></div></section><section><h3>Delivery to</h3><p><?=h($order['full_name'])?><br><?=h($order['address'])?><br><?=h($order['city'])?>, <?=h($order['state'])?><br><?=h($order['phone'])?></p><small>Ordered <?=date('F j, Y',strtotime($order['created_at']))?></small></section></div><a class="button" href="index.php?page=shop">Keep exploring <span>→</span></a></section>
    <?php render_footer(); }

function information_page(string $page): void { render_header(ucfirst($page)); $isContact=$page==='contact'; ?>
    <section class="info-page"><p class="eyebrow"><?=$isContact?'say hello':'the veloura story'?></p><h1><?=$isContact?'We would <i>love to hear from you.</i>':'A softer way to <i>shop your every day.</i>'?></h1><div class="info-columns"><p><?= $isContact ? 'Have a question about an order or one of our finds? Our small team is here for it.' : 'Veloura is an evolving edit of beauty, clothes, accessories and everyday essentials. We choose pieces with an eye for comfort, quality and a small touch of delight.' ?></p><div><h3><?=$isContact?'Contact':'Delivery & returns'?></h3><?php if($isContact):?><p><?=h(setting('contact_email'))?><br><?=h(setting('contact_phone'))?><br><?=h(setting('store_address'))?></p><?php else:?><p>We’ll confirm each order before dispatching it. Delivery fees are calculated at checkout, and orders above <?=money((float)setting('free_delivery_threshold','75'))?> are delivered free.</p><?php endif;?></div></div></section>
    <?php render_footer(); }
