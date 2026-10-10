<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\SchemaUpdater;
use SimpleStore\Category\CategoryRepository;
use SimpleStore\Product\ProductImport;
use SimpleStore\Product\ProductRepository;
use SimpleStore\Product\ProductStockRepository;

$db = ConnectionFactory::create(['host' => '127.0.0.1', 'user' => 'root',
    'password' => (string) getenv('MYSQL_TEST_PASSWORD'), 'database' => 'simple_store', 'port' => 3306]);
(new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
$categories = new CategoryRepository($db);
$stock = new ProductStockRepository($db);
$products = new ProductRepository($db, ['cs'], $categories, 50, $stock);
$data = ProductImport::decode(file_get_contents(dirname(__DIR__) . '/src/Product/examples/flextail.json'));
$data['product']['category_path'] = 'batohy';
$data['product']['slug'] = 'import-test-' . bin2hex(random_bytes(8));
$fields = ProductImport::fields($data);
$saved = $products->saveRevision($fields);
$key = $saved['product_key'];
try {
    $row = $products->current($key, 'cs');
    if ($row === null || (int) $row['published'] !== 0 || (int) $row['stock_quantity'] !== 0 ||
        $row['name'] !== 'Flextail ZERO Power 10000' || $products->findPublishedByKey($key, 'cs') !== null) {
        throw new RuntimeException('Imported draft is missing or leaked into the public catalog.');
    }
    $update = ProductImport::decode(ProductImport::export($row));
    $update['product'] = ['price_czk' => 1990];
    $products->saveRevision(ProductImport::fields($update, $row), $key, $update['revision']);
    $updated = $products->current($key, 'cs');
    if ((int) $updated['price_czk'] !== 1990 || (int) $updated['revision_number'] !== 2 || $updated['details_json'] !== $row['details_json']) {
        throw new RuntimeException('Partial import did not preserve the snapshot and revision.');
    }
    $rejected = false;
    try { $products->saveRevision($fields); } catch (InvalidArgumentException $expected) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Repeated create duplicated a product.');
    $rejected = false;
    try { $products->saveRevision(ProductImport::fields($update, $row), $key, 1); } catch (RuntimeException $expected) { $rejected = true; }
    if (!$rejected || (int) $products->current($key, 'cs')['revision_number'] !== 2) throw new RuntimeException('Concurrent update overwrote a newer revision.');
} finally {
    $db->query('DELETE FROM shop_product_revisions WHERE product_key=%s', $key);
    $db->query('DELETE FROM shop_product_inventory WHERE product_key=%s', $key);
}
echo "Product import database: draft creation, update, duplicate and concurrent edit passed.\n";
