<?php
declare(strict_types=1);

use SimpleStore\Rendering\PageRenderer;

require dirname(__DIR__) . '/src/bootstrap.php';

$renderer = new PageRenderer(dirname(__DIR__) . '/view');
ob_start();
$renderer->render('catalog', ['basePath' => '/shop/', 'language' => 'cs']);
$html = ob_get_clean();

if (!str_contains($html, 'Načteno 0 produktů') ||
    !str_contains($html, 'Zatím tu nejsou zveřejněné produkty') ||
    !str_contains($html, 'href="/shop/cs"') ||
    str_contains($html, 'menu-admin-shortcut') ||
    str_contains($html, 'produkt-topo-terraventure.php') ||
    str_contains($html, 'data-add')) {
    throw new RuntimeException('The empty catalog must not display old sample products or leave the language.');
}

$product = ['slug' => 'bota', 'product_key' => str_repeat('b', 32),
    'name' => 'Lehká bota', 'brand' => 'Topo',
    'summary' => 'Na hory', 'details_json' => null, 'category' => 'boty',
    'subcategory' => '', 'price_czk' => 3990, 'image_path' => 'images/batoh.webp',
    'sizes' => '', 'stock_status' => 'in_stock'];
ob_start();
$renderer->render('catalog', ['basePath' => '/shop/', 'language' => 'cs',
    'products' => [$product], 'categoryLabels' => ['boty' => 'Boty'],
    'cartToken' => 'test-cart-token',
    'nextUrl' => '/shop/cs?offset=12']);
$html = ob_get_clean();
if (!str_contains($html, '/shop/cs/produkt/bota') ||
    !str_contains($html, 'data-name="Lehká bota"') ||
    !str_contains($html, 'action="/shop/cs/kosik"') ||
    !str_contains($html, 'name="product_key" value="' . str_repeat('b', 32) . '"') ||
    !str_contains($html, 'name="csrf" value="test-cart-token"') ||
    !str_contains($html, 'Načteno 1 produkt') ||
    !str_contains($html, 'data-load-more data-target="catalog" href="/shop/cs?offset=12"')) {
    throw new RuntimeException('Published database rows must render as usable product cards.');
}

$cards = $renderer->cards('product', [$product], [
    'basePath' => '/shop/', 'language' => 'cs', 'categoryLabels' => ['boty' => 'Boty'],
    'cartToken' => 'test-cart-token',
]);
if (!str_contains($cards, '/shop/cs/produkt/bota') || str_contains($cards, '<html')) {
    throw new RuntimeException('The additional catalog batch must render only the reusable product cards.');
}

$display = new \SimpleStore\Pricing\BitcoinPriceDisplay([
    'rate' => 2000000.0, 'updated_at' => '2026-10-01 12:00:00',
]);
$btcCards = $renderer->cards('product', [$product], [
    'basePath' => '/shop/', 'language' => 'cs', 'categoryLabels' => ['boty' => 'Boty'],
    'cartToken' => 'test-cart-token', 'priceDisplay' => $display,
]);
if (!str_contains($btcCards, '3 990 Kč') || !str_contains($btcCards, '≈ 0.00199500 BTC') ||
    str_contains($cards, ' BTC')) {
    throw new RuntimeException('BTC must accompany CZK in later product batches only when enabled.');
}

$draft = $product + ['published' => 0, 'product_key' => str_repeat('a', 32),
    'revision_number' => 3];
ob_start();
$renderer->render('catalog', ['basePath' => '/shop/', 'language' => 'cs',
    'products' => [$draft], 'managingCatalog' => true, 'canManageCatalog' => true,
    'privatePage' => true, 'catalogVisibility' => 'draft',
    'searchAction' => '/shop/cs', 'nextUrl' => '/shop/cs?manage=1&offset=12']);
$management = ob_get_clean();
if (!str_contains($management, '/shop/cs/produkt/bota?edit=1') ||
    !str_contains($management, 'Skrytý koncept') ||
    !str_contains($management, 'name="robots" content="noindex, nofollow"') ||
    str_contains($management, 'name="action" value="add"') || str_contains($management, 'id="sort"')) {
    throw new RuntimeException('Management must keep draft cards private and link to editing without cart controls.');
}

$posts = [['slug' => 'stezka', 'title' => 'Na stezce', 'summary' => 'Vyrazili jsme',
    'saved_at' => '2026-01-02 12:00:00']];
ob_start();
$renderer->render('blog', ['basePath' => '/shop/', 'language' => 'cs',
    'posts' => $posts, 'nextUrl' => '/shop/cs/blog?offset=6']);
$html = ob_get_clean();
if (!str_contains($html, '/shop/cs/blog/stezka') ||
    !str_contains($html, 'data-load-more data-target="cms-post-list"')) {
    throw new RuntimeException('Blog must link to articles and the next batch.');
}

$manual = [[
    'label' => 'Výpravy', 'href' => '/shop/cs/blog', 'active' => false,
    'children' => [[
        'label' => 'První trek', 'href' => '/shop/cs/blog/prvni-trek', 'active' => false,
        'children' => [],
    ]],
]];
ob_start();
$renderer->render('catalog', ['basePath' => '/shop/', 'language' => 'cs',
    'primaryMenu' => $manual, 'manualPrimaryMenu' => true,
    'footerMenu' => $manual, 'manualFooterMenu' => true]);
$html = ob_get_clean();
if (!str_contains($html, '<details class="nav-dropdown') ||
    !str_contains($html, 'href="/shop/cs/blog/prvni-trek"') ||
    !str_contains($html, 'class="footer-submenu"')) {
    throw new RuntimeException('Nested manual menus must remain reachable on the public site.');
}

ob_start();
$renderer->render('catalog', ['basePath' => '/shop/', 'language' => 'cs',
    'currentCategory' => ['path' => 'spani', 'title' => 'Spaní'],
    'categoryMenuRoot' => ['path' => 'spani', 'title' => 'Spaní'],
    'categoryMenu' => $manual, 'manualCategoryMenu' => true,
    'primaryMenu' => $manual, 'manualPrimaryMenu' => true,
    'canManageMenu' => true, 'menuAdminUrl' => '/shop/admin.php?section=menus']);
$html = ob_get_clean();
if (!str_contains($html, 'aria-label="Podkategorie Spaní"') ||
    !str_contains($html, 'href="/shop/cs/blog/prvni-trek"') ||
    !str_contains($html, 'menu-admin-shortcut') ||
    str_contains($html, 'href="/shop/cs/blog#produkty"')) {
    throw new RuntimeException('Manual category tabs and editorial links must not assume category paths.');
}

echo "Catalog rendering tests passed.\n";
