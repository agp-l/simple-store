<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\SchemaUpdater;
use SimpleStore\Category\CategoryRepository;
use SimpleStore\Product\ProductImport;
use SimpleStore\Product\ProductBulkTransfer;
use SimpleStore\Product\ProductImportJob;
use SimpleStore\Product\ProductRepository;
use SimpleStore\Product\ProductStockRepository;
use SimpleStore\Product\ProductSupplierLinkRepository;

$db = ConnectionFactory::create(['host' => '127.0.0.1', 'user' => 'root', 'password' => (string) getenv('MYSQL_TEST_PASSWORD'), 'database' => 'simple_store', 'port' => 3306]);
(new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
$categories = new CategoryRepository($db);
$stock = new ProductStockRepository($db);
$products = new ProductRepository($db, ['cs'], $categories, 50, $stock);
$transfer = new ProductBulkTransfer($db, $products, ['cs']);
$prefix = 'bulk-test-' . bin2hex(random_bytes(6));
$keys = []; $jobs = [];
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$bundle = static fn (array $entries): string => json_encode(['format' => ProductBulkTransfer::FORMAT, 'products' => $entries], JSON_THROW_ON_ERROR);
$run = static function (array $items) use (&$jobs, $transfer): array {
    $job = new ProductImportJob(ProductImportJob::fromItems($items)); $jobs[] = $job;
    do { $state = $job->stepJson($transfer); } while (in_array('pending', array_column($state['items'], 'status'), true));
    return $state;
};
try {
    $template = ProductImport::decode(file_get_contents(dirname(__DIR__) . '/src/Product/examples/flextail.json'));
    $template['product']['category_path'] = 'batohy';
    $template['product']['suppliers'] = [['label' => 'Source', 'url' => 'https://example.com/product']];
    foreach (['one', 'two'] as $slug) {
        $template['product']['slug'] = $prefix . '-' . $slug;
        $saved = $products->saveRevision(ProductImport::fields($template), null, null, ProductImport::suppliers($template));
        $keys[] = $saved['product_key'];
    }
    $stock->setAvailable($keys[0], 0, 7);
    $rows = $transfer->rows('cs', 'batohy', $prefix);
    $assert(count($rows) === 2, 'Filtered export omitted a draft.');
    $selected = ProductBulkTransfer::select($rows, json_encode([ProductBulkTransfer::identity($rows[0])]));
    $assert(count($selected) === 1, 'Selection did not restrict export.');
    $assert(str_contains(ProductBulkTransfer::names($rows, 'https://example.com/shop'), 'https://example.com/shop/cs/produkt/' . $prefix), 'AI list has no absolute product URL.');
    $backup = $transfer->export($rows);
    $exported = json_decode($backup, true, 40, JSON_THROW_ON_ERROR)['products'];
    $first = array_values(array_filter($exported, static fn (array $d): bool => $d['product_key'] === $keys[0]))[0];
    $assert($first['stock_quantity'] === 7 && count($first['product']['suppliers']) === 1, 'Backup lost stock or suppliers.');
    $patch = ['format' => ProductImport::FORMAT, 'product_key' => $keys[0], 'revision' => 1, 'language' => 'cs', 'product' => ['price_czk' => 2999]];
    $fresh = $template; $fresh['product']['slug'] = $prefix . '-new';
    $state = $run($transfer->prepare($bundle([['format' => 'broken'], $patch, $fresh]), false, false));
    $assert(array_column($state['items'], 'status') === ['error', 'success', 'success'], 'One bad product stopped the batch.');
    $keys[] = $state['items'][2]['saved']['product_key'];
    $current = $products->current($keys[0], 'cs');
    $assert((int) $current['price_czk'] === 2999 && (int) $current['stock_quantity'] === 7, 'Partial update changed inventory or failed.');
    $assert(count((new ProductSupplierLinkRepository($db))->forProduct($keys[0])) === 1, 'Partial update cleared suppliers.');
    $stale = $transfer->prepare($bundle([$patch]), false, false);
    $assert($stale[0]['status'] === 'error', 'Stale AI export was accepted.');
    $restore = $transfer->prepare($bundle([$first]), true, true);
    $stock->setAvailable($keys[0], 7, 6);
    $state = $run($restore);
    $assert($state['items'][0]['status'] === 'error' && (int) $products->current($keys[0], 'cs')['revision_number'] === 2, 'Stock conflict did not roll back product revision.');
    $state = $run($transfer->prepare($bundle([$first]), true, true));
    $assert($state['items'][0]['status'] === 'success' && (int) $products->current($keys[0], 'cs')['stock_quantity'] === 7, 'Explicit restore did not restore stock.');
    $assert((int) $products->current($keys[0], 'cs')['price_czk'] === $first['product']['price_czk'], 'Restore did not restore the price.');
    $job = $jobs[count($jobs) - 1];
    $job->stepJson($transfer);
    $assert((int) $products->current($keys[0], 'cs')['revision_number'] === 3, 'Completed job replayed a write.');
    $second = array_values(array_filter($exported, static fn (array $d): bool => $d['product_key'] === $keys[1]))[0];
    $products->deleteProduct($keys[1], 'cs', 1);
    $state = $run($transfer->prepare($bundle([$second]), true, false));
    $assert($state['items'][0]['status'] === 'success' && $products->current($keys[1], 'cs') !== null, 'Deleted product identity was not restored.');
    $assert($transfer->prepare($bundle([$second, $second]), true, false)[1]['status'] === 'error', 'Duplicate keys in batch were accepted.');
    $collision = $template; $collision['product']['slug'] = $prefix . '-new';
    $state = $run($transfer->prepare($bundle([$collision]), false, false));
    $assert($state['items'][0]['status'] === 'error', 'Duplicate slug created a second product.');
} finally {
    foreach ($jobs as $job) $job->remove();
    foreach ($keys as $key) {
        $db->query('DELETE FROM shop_product_supplier_links WHERE product_key=%s', $key);
        $db->query('DELETE FROM shop_product_revisions WHERE product_key=%s', $key);
        $db->query('DELETE FROM shop_product_inventory WHERE product_key=%s', $key);
    }
}
echo "Bulk products: filtered selection, backup, partial updates, mixed batches, stock rollback, restore, replay and conflicts passed.\n";
