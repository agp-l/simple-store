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
    str_contains($html, 'produkt-topo-terraventure.php') ||
    str_contains($html, 'data-add')) {
    throw new RuntimeException('The empty catalog must not display old sample products or leave the language.');
}

$product = ['slug' => 'bota', 'name' => 'Lehká bota', 'brand' => 'Topo',
    'summary' => 'Na hory', 'details_json' => null, 'category' => 'boty',
    'subcategory' => '', 'price_czk' => 3990, 'image_path' => 'images/batoh.webp',
    'sizes' => '', 'stock_status' => 'in_stock'];
ob_start();
$renderer->render('catalog', ['basePath' => '/shop/', 'language' => 'cs',
    'products' => [$product], 'categoryLabels' => ['boty' => 'Boty'],
    'nextUrl' => '/shop/cs?offset=12']);
$html = ob_get_clean();
if (!str_contains($html, '/shop/cs/produkt/bota') ||
    !str_contains($html, 'data-name="Lehká bota"') ||
    !str_contains($html, 'data-add') ||
    !str_contains($html, 'Načteno 1 produkt') ||
    !str_contains($html, 'data-load-more data-target="catalog" href="/shop/cs?offset=12"')) {
    throw new RuntimeException('Published database rows must render as usable product cards.');
}

$cards = $renderer->cards('product', [$product], [
    'basePath' => '/shop/', 'language' => 'cs', 'categoryLabels' => ['boty' => 'Boty'],
]);
if (!str_contains($cards, '/shop/cs/produkt/bota') || str_contains($cards, '<html')) {
    throw new RuntimeException('The additional catalog batch must render only the reusable product cards.');
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

echo "Catalog rendering tests passed.\n";
