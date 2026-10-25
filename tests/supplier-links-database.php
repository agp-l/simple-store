<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\SchemaUpdater;
use SimpleStore\Product\ProductSupplierLinkRepository;

$db = ConnectionFactory::create(['host' => '127.0.0.1', 'user' => 'root',
    'password' => (string) getenv('MYSQL_TEST_PASSWORD'), 'database' => 'simple_store', 'port' => 3306]);
(new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
(new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
$suppliers = new ProductSupplierLinkRepository($db);
if (!$suppliers->installed()) throw new RuntimeException('Supplier link table is missing.');

$key = bin2hex(random_bytes(16));
$otherKey = bin2hex(random_bytes(16));
$suppliers->save($key, 0, 'První dodavatel', 'https://example.test/shoes?size=42&color=blue');
$suppliers->save($key, 0, 'Druhý dodavatel', 'https://other.example.test/shoes');
$saved = $suppliers->forProduct($key);
if (count($saved) !== 2 || count($suppliers->forOrderItems([
    ['product_key' => $key], ['product_key' => $key], ['product_key' => $otherKey],
])[$key] ?? []) !== 2) {
    throw new RuntimeException('One ordered product must have both supplier links without duplicate rows.');
}

$suppliers->save($key, (int) $saved[0]['id'], 'Opravený dodavatel', 'http://example.test/boty');
if ($suppliers->forProduct($key)[0]['label'] !== 'Opravený dodavatel' ||
    $suppliers->forOrderItems([['product_key' => $key]])[$key][0]['url'] !== 'http://example.test/boty') {
    throw new RuntimeException('Supplier link changes must be visible in the historical order view.');
}
try {
    $suppliers->remove($otherKey, (int) $saved[0]['id']);
    throw new RuntimeException('A link belonging to another product was removable.');
} catch (InvalidArgumentException $expected) {
    if (!str_contains($expected->getMessage(), 'neexistuje')) throw $expected;
}
foreach (['javascript:alert(1)', 'https://user:secret@example.test/', 'file:///etc/passwd'] as $url) {
    try {
        $suppliers->save($key, 0, 'Neplatný odkaz', $url);
        throw new RuntimeException('Unsafe supplier URL was accepted: ' . $url);
    } catch (InvalidArgumentException $expected) {
        // Only browser-safe external web addresses are allowed.
    }
}
$suppliers->remove($key, (int) $saved[0]['id']);
if (count($suppliers->forProduct($key)) !== 1 || $suppliers->forProduct($otherKey) !== []) {
    throw new RuntimeException('Removing a link must leave other links and products untouched.');
}
echo "Product supplier links OK\n";

// Real identities, revision locks and additive migration; stock/sale data must be untouched.
$products = new \SimpleStore\Product\ProductRepository($db, ['cs', 'en'], new \SimpleStore\Category\CategoryRepository($db), 50, new \SimpleStore\Product\ProductStockRepository($db));
$document = \SimpleStore\Product\ProductImport::decode(file_get_contents(dirname(__DIR__) . '/src/Product/examples/flextail.json'));
$document['product']['slug'] = 'variant-links-' . bin2hex(random_bytes(6));
$productKey = $products->saveRevision(\SimpleStore\Product\ProductImport::fields($document))['product_key'];
$suppliers->save($productKey, 0, 'Partner A', 'https://example.test/item?size=42');
$suppliers->save($productKey, 0, 'Partner B', 'https://another.test/item?v_id=one');
$variantRepo = new \SimpleStore\Product\ProductVariantRepository($db);
$variantRows = \SimpleStore\Product\ProductVariants::generate('Velikost: 42 | 43');
$variantRows[0]['quantity'] = 7; $variantRows[1]['quantity'] = 11;
$variantRepo->save($productKey, ['enabled' => true, 'expected' => $variantRepo->snapshot($productKey)['expected'], 'items' => $variantRows]);
$links = new \SimpleStore\Product\ProductVariantSupplierRepository($db);
$check = static function(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$reject = static function(callable $action): void { try { $action(); } catch (InvalidArgumentException $expected) { return; } throw new RuntimeException('Unsafe partner-link update accepted.'); };
$before = $variantRepo->snapshot($productKey);
$inventory = $db->query('SELECT * FROM shop_product_inventory WHERE product_key=%s', $productKey);
$revisions = $db->query('SELECT * FROM shop_product_revisions WHERE product_key=%s ORDER BY id', $productKey);
$check(count($links->forProduct($productKey)) === 4 && $links->inherit($productKey) === 0, 'New variant inheritance missing or not idempotent.');
$original = $links->forProduct($productKey)[0];
$check($original['verification'] === 'unverified' && $original['origin'] === 'inherited', 'Copied URL falsely claims an exact match.');
$precise = 'https://example.test/item?variant=blue&size=43';
$links->save($productKey, $original['variant_key'], $original['link_key'], $original['version'], '', 'Přesná varianta', $precise);
$check($links->inherit($productKey) === 0, 'Inheritance overwrote or duplicated an edited source.');
$reject(fn() => $links->save($productKey, $original['variant_key'], $original['link_key'], $original['version'], '', 'Stará verze', $precise));
$reject(fn() => $links->save($productKey, str_repeat('f', 32), '', 0, '', 'Cizí varianta', 'https://example.test/other'));
$reject(fn() => $links->save($productKey, $original['variant_key'], '', 0, '', 'Duplicitní URL', $precise));
$reject(fn() => $links->save($productKey, $original['variant_key'], '', 0, '', 'Unsafe', 'javascript:alert(1)'));
// Numeric parent-link IDs can disappear during common product imports.
$common = $suppliers->forProduct($productKey);
$db->startTransaction(); $suppliers->replace($productKey, $common); $db->commit();
$check($links->inherit($productKey) === 0, 'Parent-link replacement duplicated variant links.');
$deleted = $links->forProduct($productKey)[1];
$links->remove($productKey, $deleted['variant_key'], $deleted['link_key'], $deleted['version']);
$check($links->inherit($productKey) === 0 && count($links->forProduct($productKey)) === 3, 'Inheritance resurrected a removed link.');
$reject(fn() => $links->remove($productKey, $deleted['variant_key'], $deleted['link_key'], $deleted['version']));
$transfer = new \SimpleStore\Product\ProductVariantSupplierTransfer($db, $products, ['cs', 'en']);
$export = $transfer->export([$products->current($productKey, 'cs')]);
$bulk = new \SimpleStore\Product\ProductBulkTransfer($db, $products, ['cs', 'en']);
$plan = $bulk->prepare($export, false, false);
$check($plan[0]['status'] === 'pending' && $plan[0]['incoming'] === [], 'Round-trip export must be a no-op.');
$bulk->import($plan[0]);
$doc = json_decode($export);
$doc->products[0]->variants[0]->links[] = (object) ['label' => 'Třetí partner', 'url' => 'https://third.test/product?v_id=42'];
$changed = json_encode($doc, JSON_THROW_ON_ERROR);
$plan = $bulk->prepare($changed, false, false); $bulk->import($plan[0]);
$check(count($links->forProduct($productKey)) === 4, 'JSON did not add a variant partner.');
$reject(fn() => $bulk->import($plan[0]));
$check($bulk->prepare($changed, false, false)[0]['incoming'] === [], 'Repeated additive import duplicates links.');
$check($variantRepo->snapshot($productKey) === $before && $inventory === $db->query('SELECT * FROM shop_product_inventory WHERE product_key=%s', $productKey) && $revisions === $db->query('SELECT * FROM shop_product_revisions WHERE product_key=%s ORDER BY id', $productKey), 'Link migration modified stock, variants, prices or revisions.');
$reject(fn() => $bulk->prepare($export, false, true));
echo "Private variant links: inheritance, tombstones, stable identity, version conflicts and additive JSON passed.\n";
