<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Database\{ConnectionFactory, SchemaUpdater};
use SimpleStore\Product\{ProductRepository, ProductSalesRepository, ProductSupplierLinkRepository,
    ProductVariantSupplierRepository, PurchasePartnerRepository, PurchasePartnerUsage};

$config = ['host'=>'127.0.0.1','user'=>'root','password'=>(string)getenv('MYSQL_TEST_PASSWORD'),'database'=>'simple_store','port'=>3306];
$admin = ConnectionFactory::create($config); $database = 'simple_store_partner_catalog_' . bin2hex(random_bytes(5));
$admin->query('CREATE DATABASE %b CHARACTER SET utf8mb4', $database);
$db = ConnectionFactory::create(array_replace($config, ['database'=>$database]));
$check = static function(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); };
$reject = static function(callable $run): void {
    try { $run(); } catch (InvalidArgumentException $expected) { return; }
    throw new RuntimeException('Unsafe/stale partner operation accepted.');
};
try {
    (new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
    $partners = new PurchasePartnerRepository($db); $catalog = new ProductRepository($db, ['cs','en']);
    $sales = new ProductSalesRepository($db); $links = new ProductSupplierLinkRepository($db);
    $variants = new ProductVariantSupplierRepository($db); $usage = new PurchasePartnerUsage($db);
    $a = $partners->save(0, ['name'=>'Alza test','website_url'=>'https://WWW.alza.test/']);
    $b = $partners->save(0, ['name'=>'Other test','website_url'=>'https://other.test/']);
    $unused = $partners->save(0, ['name'=>'Unused test']);
    $partnerKey = $partners->find($a)['partner_key'];
    $make = static function(string $name, string $lang='cs', ?string $key=null, int $published=0) use ($db): string {
        $key ??= bin2hex(random_bytes(16));
        $db->insert('shop_product_revisions', ['product_key'=>$key,'active_product_key'=>$key,'language'=>$lang,
            'revision_number'=>1,'slug'=>'filter-'.$key,'active_slug'=>'filter-'.$key,'name'=>$name,'category'=>'batohy',
            'price_czk'=>2000,'description'=>'Keep','image_path'=>'','stock_status'=>'in_stock','published'=>$published]);
        $db->query('INSERT IGNORE INTO shop_product_inventory (product_key,available_quantity) VALUES (%s,7)', $key);
        return $key;
    };
    $selected = $make('Selected own');
    $sales->save($selected, 'cs', ['mode'=>'own','version'=>'0','partner_id'=>(string)$a]);
    $common = $make('Common source'); $make('Common EN', 'en', $common);
    $links->save($common, 0, 'A', 'https://www.alza.test/item');
    $links->save($common, 0, 'A duplicate', 'https://ALZA.test./second');
    $sales->save($common, 'cs', ['mode'=>'assisted','version'=>'0','partner_id'=>(string)$b]);
    $exact = $make('Exact variant', 'cs', null, 1);
    $domain = $make('Variant without identity'); $removed = $make('Removed variant');
    $foreign = $make('Foreign domain'); $deleted = $make('Deleted product');
    $addVariant = static function(string $key, ?string $partner, string $url, int $removed=0) use ($db): string {
        $variant = bin2hex(random_bytes(16));
        $db->insert('shop_product_variants', ['product_key'=>$key,'variant_key'=>$variant,'choices_json'=>'{"Barva":"Modrá"}',
            'available_quantity'=>5,'enabled'=>1]);
        $db->insert('shop_product_variant_supplier_links', ['link_key'=>bin2hex(random_bytes(16)),
            'product_key'=>$key,'variant_key'=>$variant,'partner_key'=>$partner,'label'=>'Source','url'=>$url,
            'url_hash'=>hash('sha256',$url),'result_json'=>'{}','removed'=>$removed]);
        return $variant;
    };
    // Explicit keys remain discoverable after a partner website is changed/mismatched.
    $variant = $addVariant($exact, $partnerKey, 'https://old-site.test/p?v=42');
    $addVariant($domain, null, 'https://alza.test/p?v=17');
    $addVariant($removed, $partnerKey, 'https://alza.test/p?v=19', 1);
    $links->save($foreign, 0, 'Lookalike', 'https://alza.test.attacker.invalid/item');
    $links->save($foreign, 0, 'Subdomain', 'https://shop.alza.test/item');
    $links->save($deleted, 0, 'A', 'https://alza.test/deleted');
    $catalog->deleteProduct($deleted, 'cs', 1);
    $keys = static fn(int $partner, int $offset=0, int $limit=48): array => array_column(
        $catalog->managementPage('cs', null, '', 'all', $offset, $limit, 'all', $partner)['items'], 'product_key');
    $expected = [$domain, $exact, $common, $selected];
    $check($keys($a) === $expected, 'Filter lost selected, common, exact or keyless variant; or included a removed/foreign source.');
    $check(array_merge($keys($a,0,2),$keys($a,2,2)) === $expected, 'Partner filtering happened after pagination.');
    $check($keys($b) === [$common] && $keys($unused) === [], 'Empty or alternate partner filtering failed.');
    $check(count($usage->products($partners->all())[$a]) === 4, 'Usage duplicated translations/links or included deleted products.');
    $check(count($catalog->managementPage('cs','batohy','Exact','published',0,12,'no_partner',$a)['items']) === 1,
        'Partner filter does not combine with category, text, visibility and missing-seller filters.');
    $missing = array_column($catalog->managementPage('cs',null,'','all',0,48,'no_links')['items'],'product_key');
    $check(!in_array($exact,$missing,true) && !in_array($domain,$missing,true) && in_array($removed,$missing,true),
        'Missing-links filter ignored variant links or counted tombstones.');
    $row = $catalog->managementPage('cs',null,'Exact','all',0,12,'all',$a)['items'][0];
    $check((int)$row['admin_supplier_count'] === 1, 'Card did not count a variant-only link.');
    $reject(static fn()=> $catalog->managementPage('cs',null,'','all',0,12,'all',-1));
    $reject(static fn()=> $catalog->managementPage('cs',null,'','all',0,12,'all',999999));
    foreach ($catalog->publishedPage('cs')['items'] as $row) $check(!isset($row['admin_partner_id'],$row['admin_supplier_count']), 'Private catalog data leaked.');

    $db->insert('shop_orders', ['id'=>1,'order_number'=>'PARTNER-DELETE-1','total_czk'=>2000,
        'items_json'=>json_encode([['seller'=>$partners->find($unused)]]),'shipping_json'=>'{}','status'=>'pending',
        'payment_method'=>'bank_transfer','payment_status'=>'pending','variable_symbol'=>'1']);
    $db->insert('shop_order_stock_reservations', ['order_id'=>1,'product_key'=>$selected,'quantity'=>2,'state'=>'reserved']);
    $db->insert('shop_supplier_price_jobs', ['product_key'=>$selected,'product_name'=>'Historical source','status'=>'applied',
        'created_at'=>time(),'data_json'=>json_encode(['partner'=>$unused]),'result_json'=>json_encode(['partner_id'=>$unused,'price'=>123])]);
    $protected = [];
    foreach (['shop_product_revisions','shop_product_sales','shop_product_inventory','shop_product_variants',
        'shop_product_supplier_links','shop_product_variant_supplier_links','shop_orders','shop_order_stock_reservations','shop_supplier_price_jobs'] as $table) {
        $protected[$table] = $db->query('SELECT * FROM '.$table);
    }
    $reject(static fn()=> $partners->delete($a,1,true));
    $reject(static fn()=> $partners->delete($b,1,true));
    $reject(static fn()=> $partners->delete($unused,1,false));
    $partners->save($unused, ['name'=>'Unused edited','version'=>'1']);
    $reject(static fn()=> $partners->delete($unused,1,true));
    $partners->delete($unused,2,true);
    $check($partners->find($unused) === null, 'Unused partner was not deleted.');
    $reject(static fn()=> $partners->delete($unused,2,true));
    $reject(static fn()=> $sales->save($selected,'cs',['mode'=>'own','version'=>'1','partner_id'=>(string)$unused]));
    foreach ($protected as $table=>$rows) $check($db->query('SELECT * FROM '.$table) === $rows, 'Partner deletion changed '.$table);

    // Sources alone must block deletion, even when no product selects that seller.
    $sourceOnly = $partners->save(0, ['name'=>'Source only','website_url'=>'https://source.test']);
    $sourceKey = $partners->find($sourceOnly)['partner_key'];
    $linkId = $variants->save($exact,$variant,'',0,$sourceKey,'Only source','https://source.test/item');
    $reject(static fn()=> $partners->delete($sourceOnly,1,true));
    $variants->remove($exact,$variant,$linkId,1);
    $partners->delete($sourceOnly,1,true);
    $reject(static fn()=> $variants->save($exact,$variant,'',0,$sourceKey,'Stale profile','https://source.test/new'));
    $check($db->queryFirstField('SELECT removed FROM shop_product_variant_supplier_links WHERE link_key=%s',$linkId)==1,
        'Deletion erased the tombstone and would allow source resurrection.');
    echo "Partner catalog: variant-aware filters, pagination, usage, safe versioned deletion and protected history passed.\n";
} finally { $db->disconnect(); $admin->query('DROP DATABASE %b',$database); }
