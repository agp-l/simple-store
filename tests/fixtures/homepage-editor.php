<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/src/bootstrap.php';
$products = [];
foreach (['Batoh na víkend', 'Lehký spacák do hor', 'Čelovka na večerní výpravy'] as $i => $name) {
    $products[] = ['product_key' => str_repeat((string) ($i + 1), 32), 'slug' => 'vybaveni-' . $i,
        'name' => $name, 'brand' => 'Dobrodruzi', 'summary' => '', 'description' => '',
        'published' => 1, 'price_czk' => 1500 + $i * 100, 'category' => 'batohy',
        'subcategory' => '', 'image_path' => 'images/batoh.webp', 'sizes' => '', 'stock_status' => 'in_stock'];
}
(new \SimpleStore\Rendering\PageRenderer(dirname(__DIR__, 2) . '/view'))->render('catalog', [
    'basePath' => '/', 'language' => 'cs', 'products' => $products,
    'canManageCatalog' => true, 'homepageEditing' => true, 'homepageReady' => true,
    'homepageConfigured' => true, 'homepageSelectionActive' => true,
    'homepageKeys' => array_column($products, 'product_key'), 'homepageSelected' => $products,
    'homepageCandidates' => $products, 'adminCsrf' => 'fixture-token',
]);
