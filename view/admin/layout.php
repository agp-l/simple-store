<?php
declare(strict_types=1);

// Authenticated administration has its own workspace; login keeps the store shell.
$adminWorkspace = in_array($screen, ['purchase-partners', 'supplier-prices', 'content-import', 'product-import', 'editor', 'categories', 'menus', 'media', 'orders', 'returns', 'accounting', 'settings', 'users', 'database'], true);
$panelTitle = 'Administrace · dobrodruzi';
$panelDescription = 'Správa obchodu Dobrodruzi';
$panelHeroTitle = in_array($screen, ['purchase-partners', 'supplier-prices', 'content-import', 'product-import', 'editor', 'products', 'categories', 'menus', 'media', 'orders', 'returns', 'accounting', 'settings', 'users', 'database'], true)
    ? 'Správa obchodu' : 'Vstup do administrace';
$panelHeroSubtitle = 'Obsah, produkty a navigace na jednom místě.';
$adminCreate = in_array($screen, ['purchase-partners', 'supplier-prices', 'content-import', 'product-import', 'editor', 'products', 'categories', 'menus', 'media', 'orders', 'returns', 'accounting', 'settings', 'users', 'database'], true) && ($csrf ?? '') !== ''
    ? ['csrf' => $csrf, 'language' => $site['default_language']] : null;
$panelPreviewControl = $adminWorkspace && $csrf !== '' ? [
    'active' => (bool) ($adminPreviewActive ?? false),
    'csrf' => $csrf,
    'return_to' => $basePath . $site['default_language'],
    'action' => $adminUrl,
] : null;
$bodyView = __DIR__ . '/panel.php';
$mediaManager = $screen === 'media';
require __DIR__ . '/../panel/layout.php';
