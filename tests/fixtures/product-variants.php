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
$saleFixture = in_array('--sale', $argv, true);
$partnerFixture = in_array('--partners', $argv, true);
if (in_array('--empty', $argv, true)) {
    $product['variant_inventory'] = \SimpleStore\Product\ProductVariantRepository::empty();
    $product['price_varies'] = false;
}
if (in_array('--prepared', $argv, true)) $product['variant_inventory']['enabled'] = false;
$partners = [
    ['id'=>1, 'partner_key'=>str_repeat('c',32), 'name'=>'Horská výbava s.r.o.', 'ico'=>'12345678', 'address'=>'Ukázková 12, Praha', 'email'=>'info@example.test', 'terms_url'=>'https://example.test/podminky', 'confirmed'=>1],
    ['id'=>2, 'partner_key'=>str_repeat('d',32), 'name'=>'Rozpracovaný dodavatel', 'ico'=>'', 'address'=>'', 'email'=>'', 'terms_url'=>'', 'confirmed'=>0],
];
if ($saleFixture) { $product['price_czk'] = 1800; $product['offer_price_czk'] = 1800; }

$commonLinks = [['id'=>1,'label'=>'Partnerský obchod','url'=>'https://example.test/jacket']];
$exact = ['link_key'=>str_repeat('e',32),'variant_key'=>$items[2]['id'],'partner_key'=>str_repeat('c',32),'label'=>'Modrá M','url'=>'https://example.test/jacket?color=blue&size=M','origin'=>'discovered','verification'=>'verified','version'=>4,'checked_at'=>time(),'result'=>['status'=>'exact','expires_at'=>time()+3600]];
$old = array_replace($exact,['link_key'=>str_repeat('f',32),'label'=>'Původní stránka','url'=>'https://example.test/jacket','origin'=>'inherited','verification'=>'unverified','result'=>['status'=>'duplicate','url'=>$exact['url']]]);
$manual = array_replace($exact,['link_key'=>str_repeat('9',32),'variant_key'=>$items[0]['id'],'label'=>'Ruční odkaz','origin'=>'manual','verification'=>'unverified','result'=>null]);

(new \SimpleStore\Rendering\PageRenderer(dirname(__DIR__, 2) . '/view'))->render('product-record', [
    'basePath' => '/', 'language' => 'cs', 'product' => $product, 'canEditProduct' => $admin,
    'selectedVariant' => in_array('--selected', $argv, true) ? $items[2] : (in_array('--unavailable', $argv, true) ? $items[0] : null),
    'editMode' => $admin, 'editToken' => 'test-token', 'stockReady' => true,
    'variantError' => in_array('--generate-error', $argv, true) ? 'Varianty nebo sklad se mezitím změnily. Obnov stránku nebo export.' : '',
    'variantForm' => in_array('--generate-error', $argv, true) ? ['operation' => 'generate', 'groups' => 'Velikost: XL', 'confirm' => '1'] : [],
    'variantModeBlocked' => in_array('--reserved', $argv, true),
    'productSalesReady' => $saleFixture, 'purchasePartners' => ($saleFixture || $partnerFixture) ? $partners : [],
    'supplierLinksReady' => $partnerFixture, 'supplierLinks' => $partnerFixture ? $commonLinks : [],
    'variantSupplierReady' => $partnerFixture, 'variantSupplierLinks' => $partnerFixture ? [$exact,$old,$manual] : [],
    'productSale' => ['mode' => in_array('--own', $argv, true) ? 'own' : 'assisted', 'version'=>1,
        'partner_id'=>1, 'supplier_price_czk'=>1678, 'delivery_note'=>'Do 5 pracovních dnů', 'variant_prices'=>[]],
    'productSaleError' => in_array('--error', $argv, true) ? 'Zkontroluj cenu dodavatele.' : '',
    'productSaleForm' => in_array('--error', $argv, true) ? ['mode'=>'own', 'partner_id'=>'0', 'partner_name'=>'Nový rozpracovaný partner', 'supplier_price_czk'=>'1801'] : [],
]);
