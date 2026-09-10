<?php
declare(strict_types=1);

/* Edit these four values if your local MySQL setup differs. */
const DB_HOST = '127.0.0.1';
const DB_NAME = 'veloura_store';
const DB_USER = 'root';
const DB_PASS = '';
const APP_ROOT = __DIR__;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('veloura_session');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    try {
        $server = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, $options);
        $server->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, $options);
        static $ready = false;
        if (!$ready) {
            $pdo->exec((string) file_get_contents(APP_ROOT . '/database.sql'));
            seed_store($pdo);
            $ready = true;
        }
    } catch (PDOException $e) {
        http_response_code(500);
        exit('Database connection failed. Start MySQL in Laragon and check the credentials in config.php.');
    }
    return $pdo;
}

function seed_store(PDO $pdo): void {
    if ((int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() === 0) {
        $pdo->prepare('INSERT INTO admins (name,email,password) VALUES (?,?,?)')->execute(['Veloura Admin', 'admin@veloura.test', password_hash('Admin@123', PASSWORD_DEFAULT)]);
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() === 0) {
        $items = [['Cosmetics','Velvet colour for every mood'],['Clothing','Easy pieces with a polished finish'],['Skincare','Soft rituals for luminous skin'],['Hair & Beauty','Your daily glow-up essentials'],['Accessories','The final beautiful detail'],['Essentials','Little luxuries for every day'],['Unisex','Made for every kind of style']];
        $statement = $pdo->prepare('INSERT INTO categories (name,description) VALUES (?,?)');
        foreach ($items as $item) $statement->execute($item);
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === 0) {
        $categories = $pdo->query('SELECT id,name FROM categories')->fetchAll(PDO::FETCH_KEY_PAIR);
        $products = [
            ['Rose Veil Lip Oil','A nourishing, glassy lip oil with a sheer rose tint.', 'Cosmetics', 18.00, 14.00, 24, 'Women', '', 'Rose,Clear', 'https://images.unsplash.com/photo-1586495777744-4413f21062fa?auto=format&fit=crop&w=900&q=85',1,1],
            ['Satin Slip Midi','A fluid champagne satin dress cut to fall beautifully.', 'Clothing', 68.00, null, 12, 'Women', 'XS,S,M,L,XL', 'Champagne,Blush,Black', 'https://images.unsplash.com/photo-1595777457583-95e059d581b8?auto=format&fit=crop&w=900&q=85',1,1],
            ['Cloud Cream Cleanser','A creamy daily cleanser that leaves skin comfortable and fresh.', 'Skincare', 22.00, null, 30, 'Women', '', '', 'https://images.unsplash.com/photo-1556229010-6c3f2c9ca5f8?auto=format&fit=crop&w=900&q=85',1,1],
            ['Golden Hour Hoops','Sculptural gold-tone hoops that lift every outfit.', 'Accessories', 24.00, 19.00, 18, 'Women', '', 'Gold,Rose gold', 'https://images.unsplash.com/photo-1535632066927-ab7c9ab60908?auto=format&fit=crop&w=900&q=85',1,0],
            ['Daily Ritual Tote','A structured everyday carryall in warm oat.', 'Essentials', 42.00, null, 15, 'Unisex', '', 'Oat,Espresso', 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=900&q=85',0,1],
            ['Soft Focus Set','A modern, understated self-care duo for any routine.', 'Unisex', 32.00, null, 20, 'Unisex', '', '', 'https://images.unsplash.com/photo-1612817288484-6f916006741a?auto=format&fit=crop&w=900&q=85',1,0],
        ];
        $sql = 'INSERT INTO products (category_id,name,description,price,discount_price,stock,gender,sizes,colors,image,is_featured,is_new,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,"active")';
        $statement = $pdo->prepare($sql);
        foreach ($products as $p) $statement->execute([$categories[$p[2]], $p[0], $p[1], $p[3], $p[4], $p[5], $p[6], $p[7], $p[8], $p[9], $p[10], $p[11]]);
    }
}

function h(?string $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
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
    $ids = array_unique(array_map(fn($row) => (int)$row['product_id'], $cart));
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT p.*,c.name category FROM products p JOIN categories c ON c.id=p.category_id WHERE p.id IN ($marks)"); $stmt->execute($ids);
    $products = []; foreach ($stmt->fetchAll() as $p) $products[$p['id']] = $p;
    $lines = []; foreach ($cart as $key => $row) if (isset($products[$row['product_id']])) { $p = $products[$row['product_id']]; $p['cart_key']=$key; $p['selected_size']=$row['size']; $p['selected_color']=$row['color']; $p['quantity']=(int)$row['quantity']; $p['unit_price']=product_price($p); $p['line_total']=$p['unit_price']*$p['quantity']; $lines[]=$p; }
    return $lines;
}
function cart_total(): float { return array_sum(array_column(cart_lines(), 'line_total')); }
function order_status_class(string $status): string { return strtolower(str_replace(' ', '-', $status)); }
function admin_required(): void { if (empty($_SESSION['admin_id'])) redirect('admin.php'); }
function render_header(string $title = ''): void { $notice = flash('notice'); $store = setting('store_name', 'Veloura'); ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#c76d88"><title><?=h($title ? "$title · $store" : $store)?></title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/style.css"></head><body><div class="announcement">Free delivery on orders over $75 <span>·</span> Curated with love</div><header class="site-header"><a class="wordmark" href="index.php">Vel<span>o</span>ura<small>the everyday edit</small></a><button class="menu-toggle" aria-label="Open menu">☰</button><nav class="main-nav"><a href="index.php">Home</a><a href="index.php?page=shop">Shop</a><a href="index.php?page=shop#categories">Categories</a><a href="index.php?page=new">New Arrivals</a><a href="index.php?page=about">About</a><a href="index.php?page=contact">Contact</a></nav><a class="cart-link" href="index.php?page=cart" aria-label="Shopping cart">Bag <b><?=cart_count()?></b></a></header><?php if ($notice): ?><div class="toast"><?=h($notice)?></div><?php endif; ?><main>
<?php }
function render_footer(): void { ?>
</main><footer class="site-footer"><section><a class="wordmark" href="index.php">Vel<span>o</span>ura<small>the everyday edit</small></a><p>A carefully chosen world of beauty, style and small everyday pleasures.</p><div class="socials">instagram · tiktok · pinterest</div></section><section><h4>Shop</h4><a href="index.php?page=shop&category=Cosmetics">Cosmetics</a><a href="index.php?page=shop&category=Clothing">Clothing</a><a href="index.php?page=shop&category=Skincare">Skincare</a><a href="index.php?page=new">New arrivals</a></section><section><h4>Customer care</h4><a href="index.php?page=contact">Contact us</a><a href="index.php?page=about">Delivery information</a><a href="index.php?page=about">Returns & exchanges</a><a href="index.php?page=about">Privacy policy</a></section><section><h4>Stay in the know</h4><p>Little notes, new arrivals, and private offers.</p><form class="newsletter" onsubmit="return false"><input type="email" placeholder="Your email address"><button aria-label="Subscribe">→</button></form></section><small class="copyright">© <?=date('Y')?> Veloura. Designed for beautiful everyday rituals. <a href="admin.php">Admin</a></small></footer><script src="assets/app.js"></script></body></html>
<?php }
