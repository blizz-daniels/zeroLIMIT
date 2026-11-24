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
        'store_name' => 'JOAM', 'store_description' => 'Heritage forms, modern confidence.',
        'contact_phone' => '+234 800 000 0000', 'contact_email' => 'hello@joamcollection.com',
        'store_address' => 'Lagos, Nigeria', 'currency' => '&#8358;', 'delivery_fee' => '2500.00',
        'free_delivery_threshold' => '50000.00', 'instagram' => '@joamcollection'
    ];
    $saveSetting = $pdo->prepare('INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    foreach ($settings as $key => $value) $saveSetting->execute([$key, $value]);

    $catalogVersion = (string)($pdo->query("SELECT setting_value FROM settings WHERE setting_key='catalog_version'")->fetchColumn() ?: '');
    if ($catalogVersion === 'joam-2026-11-v2') return;

    $pdo->beginTransaction();
    try {
        $items = [
            ['Native Sets', 'Distinctive two-piece looks informed by heritage tailoring.'],
            ['Heritage Tunics', 'Embroidered tunics with considered finishing.'],
            ['Tracksuits', 'Easy coordinated layers for everyday movement.'],
            ['Hoodies & Lounge', 'Relaxed essentials designed for comfort and repeat wear.'],
            ['Polos', 'Sharp casual staples with a clean finish.'],
            ['Matching Sets', 'Coordinated looks made to be shared.']
        ];
        $saveCategory = $pdo->prepare('INSERT INTO categories (name,description,active) VALUES (?,?,1) ON DUPLICATE KEY UPDATE description=VALUES(description), active=1');
        foreach ($items as $item) $saveCategory->execute($item);
        $categories = array_flip($pdo->query('SELECT id,name FROM categories')->fetchAll(PDO::FETCH_KEY_PAIR));
        $products = [
            ['Royal Blue Asymmetric Set', 'A two-tone native set with a sculptural printed panel and matching trousers.', 'Native Sets', 52500, null, 'Royal blue and ink', 'royal-blue-native-set.jpeg', 'M,L,XL,2XL,3XL,4XL', 'Men', 1, 1],
            ['Olive Ceremony Set', 'A refined olive native set with tonal woven stripes and cap.', 'Native Sets', 49500, null, 'Olive green', 'olive-native-set.jpeg', 'M,L,XL,2XL,3XL,4XL', 'Men', 1, 1],
            ['Crimson Noir Set', 'A black native set enlivened by a rich crimson central panel.', 'Native Sets', 55000, null, 'Crimson and black', 'crimson-noir-native-set.jpeg', 'M,L,XL,2XL,3XL,4XL', 'Men', 1, 1],
            ['Amber Indigo Statement Set', 'A striking amber print and indigo panel set with coordinated trousers.', 'Native Sets', 56000, null, 'Amber and indigo', 'amber-indigo-native-set.jpeg', 'M,L,XL,2XL,3XL,4XL', 'Men', 1, 1],
            ['Ivory Woven Tunic', 'A short-sleeve ivory tunic detailed with a geometric chest embroidery.', 'Heritage Tunics', 32000, null, 'Ivory and charcoal', 'ivory-heritage-tunic.jpeg', 'S,M,L,XL,2XL,3XL,4XL', 'Men', 0, 1],
            ['Midnight Gold Tunic', 'A deep midnight tunic with a gold woven placket and pocket detail.', 'Heritage Tunics', 34000, null, 'Midnight and gold', 'midnight-heritage-tunic.jpeg', 'S,M,L,XL,2XL,3XL,4XL', 'Men', 0, 1],
            ['Sand Stitch Tunic', 'A warm sand tunic finished with dark geometric embroidery.', 'Heritage Tunics', 33000, null, 'Sand and espresso', 'sand-heritage-tunic.jpeg', 'S,M,L,XL,2XL,3XL,4XL', 'Men', 0, 1],
            ['Coral Navy Track Set', 'A colour-block zip jacket and jogger set for an energetic everyday uniform.', 'Tracksuits', 44500, null, 'Coral, navy and white', 'coral-navy-tracksuit.jpeg', 'M,L,XL,2XL,3XL,4XL', 'Unisex', 0, 1],
            ['Navy Grey Training Set', 'A navy hoodie set with light grey contrast panels.', 'Tracksuits', 43500, null, 'Navy and grey', 'navy-grey-tracksuit.jpeg', 'M,L,XL,2XL,3XL,4XL', 'Unisex', 0, 1],
            ['Ice Stripe Zip Set', 'A light zip-neck tracksuit with graphic black and crimson stripes.', 'Tracksuits', 46000, null, 'Ice, black and crimson', 'ice-stripe-tracksuit.jpeg', 'M,L,XL,2XL,3XL,4XL', 'Unisex', 0, 1],
            ['Emerald Varsity Set', 'A rich green hooded set with bright varsity-inspired trim.', 'Tracksuits', 46500, null, 'Emerald and white', 'emerald-varsity-set.jpeg', 'M,L,XL,2XL,3XL,4XL', 'Men', 0, 1],
            ['White Trim Zip Polo', 'A crisp white polo with dark trim, a stand collar, and a neat zip placket.', 'Polos', 24500, null, 'White and black', 'white-zip-polo.jpeg', 'S,M,L,XL,2XL,3XL,4XL,5XL,6XL,7XL,8XL', 'Men', 0, 1],
            ['King & Queen Lounge Set', 'A coordinated sky-blue hoodie and jogger set for two.', 'Matching Sets', 69000, null, 'Sky blue', 'king-queen-lounge-set.jpeg', 'M,L,XL,2XL,3XL,4XL', 'Unisex', 0, 1],
            ['Navy Chevron Hoodie', 'A navy and white chevron hoodie with a clean athletic profile.', 'Hoodies & Lounge', 28500, null, 'Navy and white', 'navy-chevron-hoodie.jpeg', 'M,L,XL,2XL,3XL,4XL', 'Unisex', 0, 1],
            ['Slate Family Lounge Set', 'Matching slate hoodies and joggers for a coordinated family moment.', 'Matching Sets', 74500, null, 'Slate blue', 'slate-family-lounge-set.jpeg', 'M,L,XL,2XL,3XL,4XL', 'Unisex', 0, 1]
        ];
        $sql = 'INSERT INTO products (category_id,name,description,price,discount_price,stock,gender,sizes,colors,image,is_featured,is_new,status) VALUES (?,?,?,?,?,?,?,?,?,?,?, ?,"active")';
        $statement = $pdo->prepare($sql);
        foreach ($products as $index => $product) {
            [$name, $description, $category, $price, $salePrice, $colour, $image, $sizes, $gender, $featured, $isNew] = $product;
            $statement->execute([$categories[$category], $name, $description, $price, $salePrice, 12 + ($index % 9), $gender, $sizes, $colour, 'assets/' . $image, $featured, $isNew]);
        }
        $saveSetting->execute(['catalog_version', 'joam-2026-11-v2']);
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
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#12101c"><title><?=h($title ? "$title | $store" : $store)?></title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;0,700;1,500;1,600&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/style.css"></head><body><div class="announcement">A signature for every entrance <span>&middot;</span> The JOAM royal edit</div><header class="site-header"><a class="wordmark" href="index.php"><img class="brand-crest" src="assets/joam-logo.jpeg" alt=""><span>JOAM<small>collection</small></span></a><button class="menu-toggle" aria-label="Open menu">&#9776;</button><nav class="main-nav"><a href="index.php">Home</a><a href="index.php?page=shop">Collection</a><a href="index.php?page=shop&category=Native+Sets">Native sets</a><a href="index.php?page=shop&category=Tracksuits">Tracksuits</a><a href="index.php?page=size-guide">Size guide</a><a href="index.php?page=about">Story</a></nav><a class="cart-link" href="index.php?page=cart" aria-label="Shopping cart">Bag <b><?=cart_count()?></b></a></header><?php if ($notice): ?><div class="toast"><?=h($notice)?></div><?php endif; ?><main>
<?php }
function render_footer(): void { ?>
</main><footer class="site-footer"><section><a class="wordmark" href="index.php"><img class="brand-crest" src="assets/joam-logo.jpeg" alt=""><span>JOAM<small>collection</small></span></a><p>Heritage forms, modern confidence. Made for every entrance.</p><div class="socials">Instagram &middot; TikTok &middot; Pinterest</div></section><section><h4>Shop</h4><a href="index.php?page=shop&category=Native+Sets">Native sets</a><a href="index.php?page=shop&category=Heritage+Tunics">Heritage tunics</a><a href="index.php?page=shop&category=Tracksuits">Tracksuits</a><a href="index.php?page=shop&category=Matching+Sets">Matching sets</a></section><section><h4>Customer care</h4><a href="index.php?page=size-guide">Size guide</a><a href="index.php?page=contact">Contact us</a><a href="index.php?page=about">Delivery information</a><a href="index.php?page=about">Returns &amp; exchanges</a></section><section><h4>Stay in the loop</h4><p>New edits, private offers, and first access.</p><form class="newsletter" onsubmit="return false"><input type="email" placeholder="Your email address"><button aria-label="Subscribe">&rarr;</button></form></section><small class="copyright">&copy; <?=date('Y')?> JOAM Collection. Heritage forms, modern confidence. <a href="admin.php">Admin</a></small></footer><script src="assets/app.js"></script></body></html>
<?php }
