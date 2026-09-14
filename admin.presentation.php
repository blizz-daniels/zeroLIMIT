<?php
/* The original, fully featured panel remains in admin.legacy.php; this layer owns Zero Limit presentation. */
ob_start();
require __DIR__ . '/admin.legacy.php';
$html = ob_get_clean();

echo strtr($html, [
    '<title>Veloura Admin</title>' => '<title>Zero Limit Admin</title>',
    '<title>Veloura Studio</title>' => '<title>Zero Limit Command Centre</title>',
    'Vel<span>o</span>ura<small>administration</small>' => 'ZERO<span>LIMIT</span><small>administration</small>',
    'Vel<span>o</span>ura<small>studio panel</small>' => 'ZERO<span>LIMIT</span><small>command centre</small>',
    'admin@veloura.test' => 'admin@zerolimit.test',
    'veloura studio' => 'zero limit command centre',
]);
