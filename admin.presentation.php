<?php
/* The original, fully featured panel remains in admin.legacy.php; this layer owns JOAM presentation. */
ob_start();
require __DIR__ . '/admin.legacy.php';
$html = ob_get_clean();

echo strtr($html, [
    '<title>Veloura Admin</title>' => '<title>JOAM Admin</title>',
    '<title>Veloura Studio</title>' => '<title>JOAM Collection Admin</title>',
    'Vel<span>o</span>ura<small>administration</small>' => 'JOAM<small>administration</small>',
    'Vel<span>o</span>ura<small>studio panel</small>' => 'JOAM<small>collection admin</small>',
    'admin@veloura.test' => 'admin@joamcollection.test',
    'veloura studio' => 'joam collection admin',
]);
