<?php
declare(strict_types=1);

/* The old presentation layer is retained for configuration compatibility.
   Remove the obsolete delivery-promotion bar from every customer response. */
ob_start(static function (string $html): string {
    return preg_replace('~<div class="announcement">.*?</div>~s', '', $html, 1) ?? $html;
});

require __DIR__ . '/config.base-render.php';
