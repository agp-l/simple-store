<?php
declare(strict_types=1);

// Run last on a disposable CI database. This intentionally removes all test sales.
require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Database\CommerceTestReset;
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\SchemaUpdater;
use SimpleStore\Product\ProductStockRepository;

$db = ConnectionFactory::create([
    'host' => '127.0.0.1', 'user' => 'root', 'password' => (string) getenv('MYSQL_TEST_PASSWORD'),
    'database' => 'simple_store', 'port' => 3306,
]);
(new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
$key = bin2hex(random_bytes(16));
$slug = 'reset-' . substr($key, 0, 12);
$db->insert('shop_product_revisions', [
    'product_key' => $key, 'active_product_key' => $key, 'language' => 'cs',
    'revision_number' => 1, 'slug' => $slug, 'active_slug' => $slug,
    'name' => 'Produkt chráněný při úklidu', 'category' => 'batohy', 'price_czk' => 100,
    'description' => '', 'image_path' => '', 'stock_status' => 'in_stock', 'published' => 1,
]);
$stock = new ProductStockRepository($db);
$stock->ensure($key);
$stock->setAvailable($key, 0, 4);
$number = 'RESET-' . strtoupper(substr($key, 0, 12));
$db->insert('shop_orders', [
    'order_number' => $number, 'status' => 'new', 'customer_email' => 'reset@example.test',
    'subtotal_czk' => 200, 'shipping_czk' => 80, 'total_czk' => 280,
    'items_json' => '[]', 'shipping_json' => '{}',
    'payment_method' => 'bank_transfer', 'payment_status' => 'pending',
]);
$id = (int) $db->queryFirstField('SELECT id FROM shop_orders WHERE order_number=%s', $number);
$db->insert('shop_order_stock_reservations', [
    'order_id' => $id, 'product_key' => $key, 'quantity' => 2, 'state' => 'reserved',
]);
$db->query('UPDATE shop_product_inventory SET available_quantity=%i WHERE product_key=%s', 2, $key);
$reset = new CommerceTestReset($db);
$report = $reset->report();
if ($report['database'] !== 'simple_store' || $report['tables']['shop_orders'] < 1 ||
    ($report['reservations']['reserved']['pieces'] ?? 0) < 2) {
    throw new RuntimeException('Test commerce preview omitted orders or reserved pieces.');
}
try {
    $reset->apply('different_database');
    throw new RuntimeException('Reset accepted a different database name.');
} catch (RuntimeException $expected) {}
$result = $reset->apply('simple_store');
if ($result['before']['shop_product_revisions'] !== $result['after']['shop_product_revisions'] ||
    $result['after']['tables']['shop_orders'] !== 0 ||
    (int) $db->queryFirstField('SELECT available_quantity FROM shop_product_inventory
        WHERE product_key=%s', $key) !== 4 ||
    (int) $db->queryFirstField('SELECT COUNT(*) FROM shop_product_revisions
        WHERE product_key=%s', $key) !== 1 ||
    $result['before']['stock_movements_preserved'] !== $result['after']['stock_movements_preserved']) {
    throw new RuntimeException('Commerce reset altered the catalog or local inventory incorrectly.');
}
echo "Test commerce reset preserved the catalog and released reservations.\n";
