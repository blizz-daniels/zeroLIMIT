<?php
declare(strict_types=1);

/* InfinityFree MySQL connection for joamstores.freedev.app. */
const DB_HOST = 'sql108.infinityfree.com';
const DB_PORT = 3306;
const DB_NAME = 'if0_43455846_joam';
const DB_USER = 'if0_43455846';
/* The supplied password was redacted; replace this placeholder with the actual value. */
const DB_PASS = 'YOUR_INFINITYFREE_MYSQL_PASSWORD';
const APP_ROOT = __DIR__;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('joam_session');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, $options);
        static $ready = false;
        if (!$ready) {
            $pdo->exec((string)file_get_contents(APP_ROOT . '/database.sql'));
            seed_store($pdo);
            $ready = true;
        }
    } catch (PDOException $e) {
        http_response_code(500);
        exit('Database connection failed. Check the database settings in config.php.');
    }
    return $pdo;
}

function seed_store(PDO $pdo): void {
    if ((int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() === 0) {
        $pdo->prepare('INSERT INTO admins (name,email,password) VALUES (?,?,?)')->execute(['JOAM Admin', 'admin@joamcollection.test', password_hash('Admin@123', PASSWORD_DEFAULT)]);
    }
    $settings = [
        'store_name' => 'JOAM', 'store_description' => 'Modern essentials, made to be lived in.',
        'contact_phone' => '+234 800 000 0000', 'contact_email' => 'hello@joamcollection.com',
        'store_address' => 'Lagos, Nigeria', 'currency' => '&#8358;', 'delivery_fee' => '2500.00',
        'free_delivery_threshold' => '50000.00', 'instagram' => '@joamcollection'
    ];
    $saveSetting = $pdo->prepare('INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    foreach ($settings as $key => $value) $saveSetting->execute([$key, $value]);

    $catalogVersion = (string)($pdo->query("SELECT setting_value FROM settings WHERE setting_key='catalog_version'")->fetchColumn() ?: '');
    if ($catalogVersion === 'joam-2026-11-v1') return;

    $pdo->beginTransaction();
    try {
        /* Preserve historical products and orders, while removing them from the new JOAM storefront. */
        $pdo->exec("UPDATE products SET status='inactive' WHERE status='active'");
        $pdo->exec('UPDATE categories SET active=0');
        $items = [
            ['Long Sleeve', 'Crisp tailoring and quietly confident patterns.'],
            ['Short Sleeve', 'Easy cuts for warm days and late plans.'],
            ['Signature Prints', 'Distinctive patterns, thoughtfully balanced.']
        ];
        $saveCategory = $pdo->prepare('INSERT INTO categories (name,description,active) VALUES (?,?,1) ON DUPLICATE KEY UPDATE description=VALUES(description), active=1');
        foreach ($items as $item) $saveCategory->execute($item);
        $categories = array_flip($pdo->query('SELECT id,name FROM categories')->fetchAll(PDO::FETCH_KEY_PAIR));
        $products = [
            ['Windowpane Olive Shirt', 'An olive windowpane shirt with an easy tailored line.', 'Long Sleeve', 35500, null, 'Olive windowpane', 'WhatsApp Image 2026-11-16 at 1.06.05 PM (1).jpeg', 1, 1],
            ['Play Water Graphic Shirt', 'A relaxed short-sleeve shirt with an expressive all-over type print.', 'Signature Prints', 32500, null, 'Stone graphic', 'WhatsApp Image 2026-11-16 at 1.06.05 PM.jpeg', 1, 1],
            ['Cocoa Micro-Check Shirt', 'A fine cocoa check designed for a polished everyday rotation.', 'Long Sleeve', 37500, null, 'Cocoa micro-check', 'WhatsApp Image 2026-11-16 at 1.06.06 PM (1).jpeg', 1, 1],
            ['Monochrome Checker Shirt', 'Graphic black-and-white checks with a clean, regular fit.', 'Signature Prints', 37500, null, 'Black and ivory', 'WhatsApp Image 2026-11-16 at 1.06.06 PM (2).jpeg', 1, 1],
            ['Teal Camp-Collar Shirt', 'A crisp teal short-sleeve layer with a relaxed camp collar.', 'Short Sleeve', 31500, null, 'Teal', 'WhatsApp Image 2026-11-16 at 1.06.06 PM.jpeg', 0, 1],
            ['Indigo Everyday Check', 'A deep indigo plaid that brings texture to a simple look.', 'Long Sleeve', 36500, 33500, 'Indigo plaid', 'WhatsApp Image 2026-11-16 at 1.06.07 PM (1).jpeg', 0, 1],
            ['Rose Checkmate Shirt', 'Pink and charcoal checks in an easy, short-sleeve cut.', 'Short Sleeve', 32500, null, 'Rose check', 'WhatsApp Image 2026-11-16 at 1.06.07 PM.jpeg', 0, 1],
            ['Mustard Summer Plaid', 'Sun-warmed plaid with a relaxed silhouette for the season.', 'Short Sleeve', 33500, null, 'Mustard plaid', 'WhatsApp Image 2026-11-16 at 1.06.08 PM (1).jpeg', 0, 1],
            ['Red Geometry Camp Shirt', 'A statement camp shirt in red, black, and ivory geometry.', 'Signature Prints', 33500, null, 'Red geometric', 'WhatsApp Image 2026-11-16 at 1.06.08 PM (2).jpeg', 0, 1],
            ['White Map Print Shirt', 'A clean white short sleeve with a subtle illustrated print.', 'Signature Prints', 34500, null, 'White map print', 'WhatsApp Image 2026-11-16 at 1.06.08 PM.jpeg', 0, 1],
            ['Slate Type Print Shirt', 'A soft slate graphic shirt made for artful off-duty dressing.', 'Signature Prints', 32500, null, 'Slate type print', 'WhatsApp Image 2026-11-16 at 1.06.09 PM (1).jpeg', 0, 1],
            ['Dove Geo Short Sleeve', 'A refined dove-grey pattern with a clean short-sleeve profile.', 'Signature Prints', 34500, null, 'Dove geometric', 'WhatsApp Image 2026-11-16 at 1.06.09 PM (2).jpeg', 0, 1],
            ['Green Abstract Camp Shirt', 'A green abstract print for a confident summer layer.', 'Signature Prints', 35500, null, 'Green abstract', 'WhatsApp Image 2026-11-16 at 1.06.09 PM.jpeg', 0, 1],
            ['Mono Motion Long Sleeve', 'A black-and-ivory motion print with a sharp, modern feel.', 'Signature Prints', 38500, null, 'Monochrome print', 'WhatsApp Image 2026-11-16 at 1.06.18 PM (1).jpeg', 0, 1],
            ['Sky Blue Oxford', 'A simple sky blue button-down that always earns its place.', 'Long Sleeve', 35500, null, 'Sky blue', 'WhatsApp Image 2026-11-16 at 1.06.18 PM (2).jpeg', 0, 1],
            ['Navy Fine Stripe Shirt', 'Fine navy stripes in an easy button-down shirt.', 'Long Sleeve', 36500, null, 'Navy stripe', 'WhatsApp Image 2026-11-16 at 1.06.18 PM.jpeg', 0, 1],
            ['Sage Textured Shirt', 'A lightly textured sage shirt in a soft, versatile tone.', 'Long Sleeve', 37500, null, 'Sage', 'WhatsApp Image 2026-11-16 at 1.06.19 PM (1).jpeg', 0, 1],
            ['Heritage Stripe Shirt', 'A blue striped long sleeve with classic proportions.', 'Long Sleeve', 37500, null, 'Blue stripe', 'WhatsApp Image 2026-11-16 at 1.06.19 PM (2).jpeg', 0, 1],
            ['Blue Palm Print Shirt', 'A tailored blue palm print with a bright, composed finish.', 'Signature Prints', 38500, null, 'Blue palm print', 'WhatsApp Image 2026-11-16 at 1.06.19 PM (3).jpeg', 0, 1],
            ['Teal Pinstripe Shirt', 'Fine teal pinstripes for a modern, elevated everyday shirt.', 'Long Sleeve', 38500, null, 'Teal pinstripe', 'WhatsApp Image 2026-11-16 at 1.06.19 PM.jpeg', 0, 1],
            ['Ash Micro-Grid Shirt', 'A quiet ash micro-grid shirt, easy to dress up or down.', 'Long Sleeve', 36500, null, 'Ash micro-grid', 'WhatsApp Image 2026-11-16 at 1.06.20 PM (1).jpeg', 0, 1],
            ['Contour Print Camp Shirt', 'A relaxed camp shirt with a playful contour-line print.', 'Signature Prints', 35500, null, 'Contour print', 'WhatsApp Image 2026-11-16 at 1.06.20 PM (2).jpeg', 0, 1],
            ['Maroon Plaid Essential', 'A maroon plaid short sleeve that grounds a weekend look.', 'Short Sleeve', 34500, null, 'Maroon plaid', 'WhatsApp Image 2026-11-16 at 1.06.20 PM.jpeg', 0, 1],
            ['Blue Fine-Stripe Oxford', 'Fine blue stripes, a sharp collar, and a timeless finish.', 'Long Sleeve', 36500, null, 'Blue fine stripe', 'WhatsApp Image 2026-11-16 at 1.06.21 PM (1).jpeg', 0, 1],
            ['Brown Geometric Shirt', 'A rich brown geometric short sleeve with subtle character.', 'Signature Prints', 35500, null, 'Brown geometric', 'WhatsApp Image 2026-11-16 at 1.06.21 PM (2).jpeg', 0, 1],
            ['Ivory Fine Stripe Shirt', 'A refined ivory stripe shirt for considered occasions.', 'Long Sleeve', 39500, null, 'Ivory stripe', 'WhatsApp Image 2026-11-16 at 1.06.21 PM (3).jpeg', 0, 1],
            ['Navy Vertical Stripe Shirt', 'Clean navy vertical stripes with a modern regular fit.', 'Long Sleeve', 37500, null, 'Navy vertical stripe', 'WhatsApp Image 2026-11-16 at 1.06.21 PM.jpeg', 0, 1]
        ];
        $sql = 'INSERT INTO products (category_id,name,description,price,discount_price,stock,gender,sizes,colors,image,is_featured,is_new,status) VALUES (?,?,?,?,?,?,?,?,?,?,?, ?,"active")';
        $statement = $pdo->prepare($sql);
        foreach ($products as $index => $product) {
            [$name, $description, $category, $price, $salePrice, $colour, $image, $featured, $isNew] = $product;
            $statement->execute([$categories[$category], $name, $description, $price, $salePrice, 12 + ($index % 9), 'Men', 'S,M,L,XL,XXL', $colour, 'assets/' . $image, $featured, $isNew]);
        }
        $saveSetting->execute(['catalog_version', 'joam-2026-11-v1']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function h(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function money(float $amount): string { return site_settings()['currency'] . number_format($amount, 2); }
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_input(): string { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }
function verify_csrf(): void { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(419); exit('Your form expired. Please return and try again.'); } }
function flash(string $key, ?string $message = null): ?string { if ($message !== null) { $_SESSION['flash'][$key] = $message; return null; } $out = $_SESSION['flash'][$key] ?? null; unset($_SESSION['flash'][$key]); return $out; }
function redirect(string $url): never { header('Location: ' . $url); exit; }
function site_settings(): array { static $settings = null; if ($settings !== null) return $settings; $rows = db()->query('SELECT setting_key,setting_value FROM settings')->fetchAll(); $settings = array_column($rows, 'setting_value', 'setting_key'); return $settings; }
function setting(string $key, string $fallback = ''): string { return site_settings()[$key] ?? $fallback; }
function cart_count(): int { return array_sum(array_column($_SESSION['cart'] ?? [], 'quantity')); }
function cart(): array { return $_SESSION['cart'] ?? []; }
function product_price(array $product): float { return $product['discount_price'] !== null ? (float)$product['discount_price'] : (float)$product['price']; }
function cart_lines(): array {
    $cart = cart(); if (!$cart) return [];
    $ids = array_values(array_unique(array_map(fn($row) => (int)$row['product_id'], $cart)));
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT p.*,c.name category FROM products p JOIN categories c ON c.id=p.category_id WHERE p.id IN ($marks)"); $stmt->execute($ids);
    $products = []; foreach ($stmt->fetchAll() as $p) $products[$p['id']] = $p;
    $lines = []; foreach ($cart as $key => $row) if (isset($products[$row['product_id']])) { $p = $products[$row['product_id']]; $p['cart_key']=$key; $p['selected_size']=$row['size']; $p['selected_color']=$row['color']; $p['quantity']=(int)$row['quantity']; $p['unit_price']=product_price($p); $p['line_total']=$p['unit_price']*$p['quantity']; $lines[]=$p; }
    return $lines;
}
function cart_total(): float { return array_sum(array_column(cart_lines(), 'line_total')); }
function order_status_class(string $status): string { return strtolower(str_replace(' ', '-', $status)); }
function admin_required(): void { if (empty($_SESSION['admin_id'])) redirect('admin.php'); }
function render_header(string $title = ''): void { $notice = flash('notice'); $store = setting('store_name', 'JOAM'); ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#596b50"><title><?=h($title ? "$title | $store" : $store)?></title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;0,700;1,500;1,600&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/style.css"></head><body><div class="announcement">Complimentary delivery on orders over &#8358;50,000 <span>&middot;</span> The JOAM edit</div><header class="site-header"><a class="wordmark" href="index.php">JOAM<small>collection</small></a><button class="menu-toggle" aria-label="Open menu">&#9776;</button><nav class="main-nav"><a href="index.php">Home</a><a href="index.php?page=shop">Collection</a><a href="index.php?page=shop#categories">Shirts</a><a href="index.php?page=new">New arrivals</a><a href="index.php?page=about">Story</a><a href="index.php?page=contact">Contact</a></nav><a class="cart-link" href="index.php?page=cart" aria-label="Shopping cart">Bag <b><?=cart_count()?></b></a></header><?php if ($notice): ?><div class="toast"><?=h($notice)?></div><?php endif; ?><main>
<?php }
function render_footer(): void { ?>
</main><footer class="site-footer"><section><a class="wordmark" href="index.php">JOAM<small>collection</small></a><p>Modern shirting and distinct prints for the life you are already living.</p><div class="socials">Instagram &middot; TikTok &middot; Pinterest</div></section><section><h4>Shop</h4><a href="index.php?page=shop&category=Long+Sleeve">Long sleeve</a><a href="index.php?page=shop&category=Short+Sleeve">Short sleeve</a><a href="index.php?page=shop&category=Signature+Prints">Signature prints</a><a href="index.php?page=new">New arrivals</a></section><section><h4>Customer care</h4><a href="index.php?page=contact">Contact us</a><a href="index.php?page=about">Delivery information</a><a href="index.php?page=about">Returns &amp; exchanges</a><a href="index.php?page=about">Privacy policy</a></section><section><h4>Stay in the loop</h4><p>New edits, private offers, and first access.</p><form class="newsletter" onsubmit="return false"><input type="email" placeholder="Your email address"><button aria-label="Subscribe">&rarr;</button></form></section><small class="copyright">&copy; <?=date('Y')?> JOAM Collection. Modern essentials, made to be lived in. <a href="admin.php">Admin</a></small></footer><script src="assets/app.js"></script></body></html>
<?php }
