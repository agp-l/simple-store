<?php
declare(strict_types=1);

use SimpleStore\Rendering\PageRenderer;

require dirname(__DIR__) . '/src/bootstrap.php';

$renderer = new PageRenderer(dirname(__DIR__) . '/view');
ob_start();
$renderer->render('catalog', ['basePath' => '/shop/', 'language' => 'cs']);
$html = ob_get_clean();

if (!str_contains($html, 'Zobrazeno 0 produktů') ||
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
    'products' => [$product], 'categoryLabels' => ['boty' => 'Boty']]);
$html = ob_get_clean();
if (!str_contains($html, '/shop/cs/produkt/bota') ||
    !str_contains($html, 'data-name="Lehká bota"') ||
    !str_contains($html, 'data-add') ||
    !str_contains($html, 'Zobrazeno 1 produkt')) {
    throw new RuntimeException('Published database rows must render as usable product cards.');
}

echo "Catalog rendering tests passed.\n";
