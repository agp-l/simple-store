<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Database\{ConnectionFactory, SchemaUpdater};
use SimpleStore\Category\CategoryRepository;
use SimpleStore\Product\{ProductRepository, ProductStockRepository, ProductImport, ProductBulkTransfer,
    ProductVariantSupplierRepository, PurchasePartnerRepository, ProductZipConflictPolicy};

$config = ['host'=>'127.0.0.1', 'user'=>'root', 'password'=>(string)getenv('MYSQL_TEST_PASSWORD'), 'database'=>'simple_store', 'port'=>3306];
$admin = ConnectionFactory::create($config);
$database = 'simple_store_import_offers_' . bin2hex(random_bytes(5));
$admin->query('CREATE DATABASE %b CHARACTER SET utf8mb4', $database);
$db = ConnectionFactory::create(array_replace($config, ['database'=>$database]));
$check = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$reject = static function(callable $call): void { try { $call(); } catch (InvalidArgumentException $expected) { return; } throw new RuntimeException('Unsafe offer import accepted.'); };
try {
    (new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
    $products = new ProductRepository($db, ['cs'], new CategoryRepository($db), 50, new ProductStockRepository($db));
    $links = new ProductVariantSupplierRepository($db);
    $partners = new PurchasePartnerRepository($db);
    $partner = $partners->find($partners->save(0, ['name'=>'Test partner', 'website_url'=>'https://partner.test']));
    $data = ['format'=>ProductImport::FORMAT, 'language'=>'cs', 'product'=>[
        'name'=>'Import offers test', 'slug'=>'import-offers-test', 'category_path'=>'batohy', 'price_czk'=>1000,
        'variants'=>['enabled'=>true, 'items'=>[
            ['choices'=>['Barva'=>'Blue', 'Velikost'=>'S'], 'suppliers'=>[
                ['label'=>'Partner S', 'url'=>'https://partner.test/model?size=S#blue'],
                ['label'=>'Other partner', 'url'=>'https://other.test/model?variant=blue-s']]],
            ['choices'=>['Barva'=>'Blue', 'Velikost'=>'M'], 'suppliers'=>[
                ['label'=>'Partner M', 'url'=>'https://partner.test/model?size=M#blue']]],
        ]]]];
    $key = $products->saveRevision(ProductImport::fields($data))['product_key'];
    $current = $products->current($key, 'cs');
    $offers = $links->forProduct($key);
    $check(count($offers)===3 && count($current['variant_inventory']['items'])===2, 'One-step creation lost variants or offers.');
    $check(array_unique(array_column($offers, 'verification'))===['unverified'], 'Import trusted unverified offers.');
    $check(count(array_filter($offers, fn(array $l): bool => $l['partner_key']===$partner['partner_key']))===2, 'Exact host did not resolve the known partner.');
    $check(array_column($current['variant_inventory']['items'],'quantity')===[0,0] && (int)$current['published']===0, 'New draft became available.');
    $check($db->queryFirstField('SELECT COUNT(*) FROM shop_product_sales WHERE product_key=%s',$key)==0, 'Import changed the seller or sales mode.');
    $check($db->queryFirstField('SELECT COUNT(*) FROM shop_partner_refresh_requests WHERE product_key=%s',$key)==1, 'Offer change was not queued.');
    $transfer = new ProductBulkTransfer($db, $products, ['cs']);
    $export = json_decode($transfer->export([$current]), true, 40, JSON_THROW_ON_ERROR);
    $document = $export['products'][0];
    $check(count($document['product']['variants']['items'][0]['suppliers'])===2, 'Full export omitted exact offers.');
    $single = ProductImport::decode(ProductImport::export($current, [], $offers));
    $check($single===$document, 'Single and bulk product exports differ.');
    $plan = $transfer->prepare(json_encode($export), false, false);
    $check($plan[0]['status']==='pending', 'Round-trip preparation failed: '.$plan[0]['message']);
    $transfer->import($plan[0]);
    $check($links->forProduct($key)===$offers, 'Unchanged round trip duplicated, reverified or versioned offers.');

    $current = $products->current($key, 'cs');
    $stale = ProductImport::decode(ProductImport::export($current, [], $offers));
    $stale['product']['name']='Must roll back';
    $stale['product']['variants']['items'][0]['quantity']=9;
    $offer=$offers[0];
    $links->save($key,$offer['variant_key'],$offer['link_key'],$offer['version'],$offer['partner_key']??'', $offer['label'], $offer['url'].'&new=1');
    $afterEdit=$links->forProduct($key);
    $reject(fn()=>$products->saveRevision(ProductImport::fields($stale,$current),$key,$stale['revision']));
    $check($products->current($key,'cs')===$current && $links->forProduct($key)===$afterEdit, 'Stale link failed to roll back product, variants and stock.');

    $fresh = ProductImport::decode(ProductImport::export($current, [], $afterEdit));
    $fresh['product']['variants']['items'][0]['quantity']=99;
    $plan=$transfer->prepare(json_encode(['format'=>ProductBulkTransfer::FORMAT,'products'=>[$fresh]]),false,false);
    $transfer->import($plan[0]);
    $check(array_column($products->current($key,'cs')['variant_inventory']['items'],'quantity')===[0,0], 'Ordinary bulk import overwrote stock.');

    $copyKey=bin2hex(random_bytes(16));
    $copy=ProductZipConflictPolicy::resolve($products,$fresh,$copyKey,'copy');
    $products->saveRevision(ProductImport::fields($copy['data']),null,null,null,$copyKey);
    $copied=$links->forProduct($copyKey);
    $check(count($copied)===3 && array_intersect(array_column($copied,'link_key'),array_column($afterEdit,'link_key'))===[], 'Copy retained foreign link identities.');
    $check($links->forProduct($key)===$afterEdit, 'Copy modified original offers.');

    $invalid=$data; $invalid['product']['slug']='must-not-exist';
    $invalid['product']['variants']['items'][1]['suppliers'][0]['partner_key']=$partner['partner_key'];
    $invalid['product']['variants']['items'][1]['suppliers'][0]['url']='https://wrong.test/product';
    $reject(fn()=>$products->saveRevision(ProductImport::fields($invalid)));
    $check($products->findCurrentBySlug('must-not-exist','cs')===null, 'Bad partner left a partial product.');

    // Explicit restore rebases offers during preview; a subsequent link edit still conflicts.
    $restore=$transfer->prepare(json_encode($export),true,false);
    $check($restore[0]['status']==='pending', 'Restore failed to rebase link versions.');
    $transfer->import($restore[0]);
    $restored=$links->forProduct($key);
    $check(count($restored)===3 && array_column($restored,'url')===array_column($offers,'url'), 'Restore lost original URLs.');
    // Single-variant-free products retain their source identities and reject stale full exports.
    $plain=$data; unset($plain['product']['variants']); $plain['product']['slug']='plain-offers-test';
    $plain['product']['suppliers']=[['label'=>'Plain partner','url'=>'https://partner.test/plain']];
    $plainKey=$products->saveRevision(ProductImport::fields($plain),null,null,ProductImport::suppliers($plain))['product_key'];
    $sources=new \SimpleStore\Product\ProductSupplierLinkRepository($db);
    $before=$sources->forProduct($plainKey);
    $plainRow=$products->current($plainKey,'cs');
    $plainDoc=ProductImport::decode(ProductImport::export($plainRow,$before));
    $products->saveRevision(ProductImport::fields($plainDoc,$plainRow),$plainKey,$plainDoc['revision'],ProductImport::suppliers($plainDoc));
    $check($sources->forProduct($plainKey)===$before, 'Unchanged default-product export replaced source identities.');
    $plainRow=$products->current($plainKey,'cs');
    $plainDoc=ProductImport::decode(ProductImport::export($plainRow,$before));
    $sources->save($plainKey,(int)$before[0]['id'],'Changed','https://partner.test/new-plain');
    $reject(fn()=>$products->saveRevision(ProductImport::fields($plainDoc,$plainRow),$plainKey,$plainDoc['revision'],ProductImport::suppliers($plainDoc)));
    $check($sources->forProduct($plainKey)[0]['url']==='https://partner.test/new-plain' && $products->current($plainKey,'cs')===$plainRow, 'Stale full export resurrected old default-product source.');
    echo "Product JSON offers: atomic create, multi-partner round trip, stale rollback, stock protection, copy, host validation and restore passed.\n";
} finally {
    $db->disconnect();
    $admin->query('DROP DATABASE %b', $database);
}
