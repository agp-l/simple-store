<?php
declare(strict_types=1);

// PageRenderer has already checked the page name against its allowlist.
$bodyView = match ($page) {
    'catalog' => __DIR__ . '/body.php',
    'product-record' => __DIR__ . '/product-record.php',
    'page', 'post' => __DIR__ . '/content-body.php',
    'blog' => __DIR__ . '/blog-list.php',
    'cart', 'shipping', 'payment', 'review', 'complete' => __DIR__ . '/checkout/' . $page . '.php',
    default => __DIR__ . '/message.php',
};
require __DIR__ . '/shell.php';
