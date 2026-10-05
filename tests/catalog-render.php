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
    str_contains($html, 'menu-admin-shortcut') || str_contains($html, 'product-admin-nav') ||
    str_contains($html, 'produkt-topo-terraventure.php') ||
    str_contains($html, 'data-add')) {
    throw new RuntimeException('The empty catalog must not display old sample products or leave the language.');
}

$product = ['slug' => 'bota', 'product_key' => str_repeat('b', 32),
    'name' => 'Lehká bota', 'brand' => 'Topo',
    'summary' => 'Na hory', 'description' => '', 'published' => 1,
    'details_json' => null, 'category' => 'boty',
    'subcategory' => '', 'price_czk' => 3990, 'image_path' => 'images/batoh.webp',
    'sizes' => '', 'stock_status' => 'in_stock'];
ob_start();
$renderer->render('catalog', ['basePath' => '/shop/', 'language' => 'cs',
    'products' => [$product], 'categoryLabels' => ['boty' => 'Boty'],
    'cartToken' => 'test-cart-token',
    'nextUrl' => '/shop/cs?offset=12']);
$html = ob_get_clean();
if (!str_contains($html, '/shop/cs/produkt/bota') ||
    !str_contains($html, 'href="/shop/cs/kategorie-produktu/boty" aria-label="Prohlédnout kategorii Boty"') ||
    !str_contains($html, 'class="product-summary"') ||
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

$nestedCategory = $renderer->cards('product', [array_replace($product, [
    'category' => 'vybaveni', 'subcategory' => 'powerbanky',
])], ['basePath' => '/shop/', 'language' => 'cs',
    'categoryLabels' => ['vybaveni/powerbanky' => 'Powerbanky']]);
if (!str_contains($nestedCategory, 'href="/shop/cs/kategorie-produktu/vybaveni/powerbanky"') ||
    !str_contains($nestedCategory, 'Prohlédnout kategorii Powerbanky')) {
    throw new RuntimeException('A product label must lead to its exact nested category.');
}

$withOptions = array_replace($product, ['details_json' => json_encode([
    'options' => [['name' => 'Velikost', 'values' => ['42', '43']]],
    'specifications' => [], 'sections' => [], 'gallery' => [],
], JSON_THROW_ON_ERROR)]);
$optionCards = $renderer->cards('product', [$withOptions], [
    'basePath' => '/shop/', 'language' => 'cs', 'categoryLabels' => ['boty' => 'Boty'],
]);
if (!str_contains($optionCards, '>Přejít na detail</a>') || str_contains($optionCards, 'Vybrat možnosti')) {
    throw new RuntimeException('Option products need a clear link to their detail.');
}

$privateSupplier = [['id' => 7, 'label' => 'Sklad bot',
    'url' => 'https://supplier.example.test/bota?size=42&source=store']];
$detailData = ['basePath' => '/shop/', 'language' => 'cs', 'product' => $product,
    'categoryLabels' => ['boty' => 'Boty'], 'supplierLinks' => $privateSupplier,
    'supplierLinksReady' => true, 'editToken' => 'private-token'];
ob_start();
$renderer->render('product-record', $detailData);
$publicDetail = ob_get_clean();
if (str_contains($publicDetail, 'supplier.example.test') || str_contains($publicDetail, 'supplier-links') ||
    str_contains($publicDetail, 'private-token')) {
    throw new RuntimeException('Supplier links or their edit token leaked to a public product page.');
}
ob_start();
$renderer->render('product-record', $detailData + ['canEditProduct' => true]);
$privateDetail = ob_get_clean();
if (!str_contains($privateDetail, 'supplier.example.test/bota?size=42&amp;source=store') ||
    !str_contains($privateDetail, 'aria-label="Správa produktů"') ||
    !str_contains($privateDetail, 'href="/shop/cs?manage=1#produkty"') ||
    !str_contains($privateDetail, '✎ Upravit produkt') ||
    !str_contains($privateDetail, 'name="action" value="product-supplier-link"') ||
    !str_contains($privateDetail, 'name="csrf" value="private-token"') ||
    !str_contains($privateDetail, 'Jen pro správce')) {
    throw new RuntimeException('Administrators must see and edit the private supplier links on product pages.');
}

ob_start();
$renderer->render('catalog', ['basePath' => '/shop/', 'language' => 'cs',
    'products' => [$product], 'homepageSelectionActive' => true, 'homepageConfigured' => true]);
$selectedHome = ob_get_clean();
if (!str_contains($selectedHome, '<h2 id="section-title">Náš výběr vybavení</h2>') ||
    !str_contains($selectedHome, '/shop/cs/produkt/bota') ||
    !str_contains($selectedHome, '?all=1#produkty') ||
    str_contains($selectedHome, 'product-admin-nav') ||
    str_contains($selectedHome, 'name="action" value="homepage-product"') ||
    str_contains($selectedHome, 'id="homepage-editor"') || str_contains($selectedHome, 'id="sort"')) {
    throw new RuntimeException('Public homepage must show the chosen products without management controls.');
}
ob_start();
$renderer->render('catalog', ['basePath' => '/shop/', 'language' => 'cs',
    'products' => [$product], 'homepageEditing' => true, 'homepageReady' => true,
    'productAdminMode' => 'selection',
    'homepageSelectionActive' => true, 'homepageConfigured' => true,
    'homepageKeys' => [$product['product_key']],
    'homepageSelected' => [$product + ['published' => 1]],
    'homepageCandidates' => [$product], 'adminCsrf' => 'home-csrf',
    'canManageCatalog' => true]);
$editableHome = ob_get_clean();
if (!str_contains($editableHome, 'name="action" value="homepage-product"') ||
    !str_contains($editableHome, 'href="/shop/cs?homepage_edit=1#homepage-editor" aria-current="page"') ||
    !str_contains($editableHome, 'href="/shop/cs#produkty"') ||
    !str_contains($editableHome, 'name="csrf" value="home-csrf"') ||
    !str_contains($editableHome, 'Vrátit automatický výpis katalogu')) {
    throw new RuntimeException('Homepage administration needs working server-side selection controls.');
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

$draft = array_replace($product, ['published' => 0, 'product_key' => str_repeat('a', 32),
    'revision_number' => 3]);
ob_start();
$renderer->render('catalog', ['basePath' => '/shop/', 'language' => 'cs',
    'products' => [$draft], 'managingCatalog' => true, 'canManageCatalog' => true,
    'productAdminMode' => 'manage', 'adminCreate' => ['csrf' => 'catalog-token', 'language' => 'cs'],
    'privatePage' => true, 'catalogVisibility' => 'draft',
    'searchAction' => '/shop/cs', 'nextUrl' => '/shop/cs?manage=1&offset=12']);
$management = ob_get_clean();
if (!str_contains($management, '/shop/cs/produkt/bota?edit=1') ||
    !str_contains($management, 'href="/shop/cs?manage=1#produkty" aria-current="page"') ||
    !str_contains($management, 'name="action" value="create-product"') ||
    !str_contains($management, 'href="/shop/admin.php?section=categories&amp;language=cs"') ||
    !str_contains($management, 'href="/shop/admin.php?section=menus&amp;language=cs&amp;slot=primary"') ||
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
