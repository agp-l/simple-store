<?php
declare(strict_types=1);
// Generate inert visual fixtures; this file is never a public endpoint or deployment asset.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/src/bootstrap.php';
$items = \SimpleStore\Product\ProductVariants::generate("Velikost: S | M | L\nBarva: Modrá | Červená");
foreach ($items as $i => &$variant) {
    $variant['quantity'] = $i % 3;
    $variant['price_czk'] = $i % 2 ? 2890 : null;
    $variant['sku'] = 'BUNDA-' . $i;
    if ($i === 2) $variant['image_path'] = 'images/batoh.webp';
}
unset($variant);
$product = ['product_key' => str_repeat('a', 32), 'language' => 'cs', 'revision_number' => 1,
    'slug' => 'bunda', 'name' => 'Lehká bunda do hor', 'brand' => 'Dobrodruzi',
    'summary' => 'Odolná ochrana před větrem na lehké cesty.', 'description' => 'Společný popis všech velikostí a barev.',
    'price_czk' => 2490, 'offer_price_czk' => 2490, 'price_varies' => true, 'image_path' => 'images/product-placeholder.png',
    'stock_status' => 'in_stock', 'availability_status' => 'in_stock', 'stock_quantity' => 6, 'category' => 'batohy',
    'subcategory' => '', 'published' => true, 'details_json' => null, 'sizes' => '',
    'variant_inventory' => ['enabled' => true, 'version' => 1, 'expected' => str_repeat('b', 64), 'items' => $items]];
$admin = in_array('--admin', $argv, true);
(new \SimpleStore\Rendering\PageRenderer(dirname(__DIR__, 2) . '/view'))->render('product-record', [
    'basePath' => '/', 'language' => 'cs', 'product' => $product, 'canEditProduct' => $admin,
    'editMode' => $admin, 'stockReady' => true]);
