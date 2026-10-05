<?php
declare(strict_types=1);

// CI integration: real MySQL row locks and transaction boundaries for inventory.
require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Admin\OrderControlRepository;
use SimpleStore\Admin\OrderProductLinks;
use SimpleStore\Checkout\BankTransferPayment;
use SimpleStore\Checkout\OrderRepository;
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\SchemaUpdater;
use SimpleStore\Product\ProductRepository;
use SimpleStore\Product\ProductStockRepository;

function expectStock(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$db = ConnectionFactory::create([
    'host' => '127.0.0.1', 'user' => 'root', 'password' => (string) getenv('MYSQL_TEST_PASSWORD'),
    'database' => 'simple_store', 'port' => 3306,
]);
(new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
$stock = new ProductStockRepository($db);
expectStock($stock->installed(), 'Inventory tables were not installed.');
$key = bin2hex(random_bytes(16));
$slug = 'stock-' . substr($key, 0, 12);
$db->insert('product_revisions', [
    'product_key' => $key, 'active_product_key' => $key, 'language' => 'cs',
    'revision_number' => 1, 'slug' => $slug, 'active_slug' => $slug,
    'name' => 'Test skladu', 'category' => 'batohy', 'price_czk' => 100,
    'description' => '', 'image_path' => '', 'stock_status' => 'in_stock', 'published' => 1,
]);
$stock->ensure($key);
$products = new ProductRepository($db, ['cs'], null, 50, $stock);
expectStock($products->findPublishedByKey($key, 'cs')['availability_status'] === 'out_of_stock',
    'Zero stock must appear unavailable to customers.');
$stock->setAvailable($key, 0, 5);
expectStock($products->findPublishedByKey($key, 'cs')['availability_status'] === 'in_stock',
    'An available piece must appear in stock.');
try {
    $stock->setAvailable($key, 0, 8);
    throw new RuntimeException('Stale inventory edit was accepted.');
} catch (RuntimeException $error) {
    expectStock(str_contains($error->getMessage(), 'mezi'), 'Unexpected stale edit failure.');
}

$bank = new BankTransferPayment('', '1004823033/3030', 'Test');
$orders = new OrderRepository($db, $bank, 7, $stock);
expectStock($orders->installed(), 'Checkout must require the inventory migration.');
$item = [
    'product_key' => $key, 'language' => 'cs', 'slug' => $slug, 'name' => 'Test skladu',
    'image_path' => '', 'quantity' => 2, 'unit_price_czk' => 100,
    'options' => ['Barva' => 'Modrá'],
];
$shipping = ['method' => 'gls_home', 'label' => 'GLS domů', 'name' => 'Eva Nová'];
$idempotencyKey = bin2hex(random_bytes(32));
$order = $orders->create(null, 'stock@example.test', [$item, array_replace($item, ['quantity' => 2])],
    $shipping, 79, $idempotencyKey);
// Same product in distinct cart lines shares one reservation.
expectStock((int) $db->queryFirstField('SELECT available_quantity FROM shop_product_inventory
    WHERE product_key=%s', $key) === 1, 'Order did not reserve the summed quantities.');
expectStock($orders->create(null, 'stock@example.test', [$item, array_replace($item, ['quantity' => 2])],
    $shipping, 79, $idempotencyKey)['id'] === $order['id'],
    'Idempotent request created another order.');
expectStock((int) $db->queryFirstField('SELECT available_quantity FROM shop_product_inventory
    WHERE product_key=%s', $key) === 1, 'Idempotent request deducted inventory twice.');
try {
    $orders->create(null, 'stock@example.test', [$item], $shipping, 79, bin2hex(random_bytes(32)));
    throw new RuntimeException('Oversold order was accepted.');
} catch (InvalidArgumentException $error) {
    expectStock(str_contains($error->getMessage(), 'skladem'), 'Unexpected oversell failure.');
}
try {
    $orders->cancelOverdueBankTransfer((int) $order['id']);
    throw new RuntimeException('Order was cancelled before the bank transfer due date.');
} catch (InvalidArgumentException $expected) {}
$db->query('UPDATE shop_orders SET payment_due_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND)
    WHERE id=%i', (int) $order['id']);
$orders->cancelOverdueBankTransfer((int) $order['id']);
expectStock((int) $db->queryFirstField('SELECT available_quantity FROM shop_product_inventory
    WHERE product_key=%s', $key) === 5, 'Cancelling an order did not release its stock.');
try {
    $orders->cancelOverdueBankTransfer((int) $order['id']);
    throw new RuntimeException('Order was cancelled twice.');
} catch (InvalidArgumentException $expected) {}
expectStock((int) $db->queryFirstField('SELECT available_quantity FROM shop_product_inventory
    WHERE product_key=%s', $key) === 5, 'Duplicate cancellation changed inventory.');
$controls = new OrderControlRepository($db, $stock);
$controls->correctFulfillment((int) $order['id'], 'new', 1,
    'Omylem zrušená objednávka', 'reopen');
expectStock((int) $db->queryFirstField('SELECT available_quantity FROM shop_product_inventory
    WHERE product_key=%s', $key) === 1, 'Reopening did not reclaim inventory.');
$controls->deleteOrder((int) $order['id'], (string) $order['order_number'], 1,
    'Smazání testovací objednávky');
expectStock((int) $db->queryFirstField('SELECT available_quantity FROM shop_product_inventory
    WHERE product_key=%s', $key) === 5, 'Deleting an unshipped order did not release inventory.');
expectStock((int) $db->queryFirstField('SELECT COUNT(*) FROM shop_order_stock_reservations
    WHERE order_id=%i', (int) $order['id']) === 0, 'Deleted order kept stock reservations.');

// An order placed from the catalogue initially reserves local stock. Switching
// to a supplier must release it; switching back must reserve it once again.
$supplied = $orders->create(null, 'supplier@example.test', [$item], $shipping, 79,
    bin2hex(random_bytes(32)));
$suppliedId = (int) $supplied['id'];
$orders->markPaid($suppliedId, 1);
expectStock((int) $db->queryFirstField('SELECT available_quantity FROM shop_product_inventory
    WHERE product_key=%s', $key) === 3, 'Supplier order was not initially reserved.');
$orders->setFulfillmentStatus($suppliedId, 'processing', 'external', 'Dodavatel');
expectStock((int) $db->queryFirstField('SELECT available_quantity FROM shop_product_inventory
    WHERE product_key=%s', $key) === 5, 'Supplier fulfillment kept local inventory reserved.');
$stock->setAvailable($key, 5, 0);
try {
    $orders->setFulfillmentStatus($suppliedId, 'ready_to_ship', 'own');
    throw new RuntimeException('Switching to own fulfillment oversold local stock.');
} catch (InvalidArgumentException $expected) {}
expectStock($orders->findById($suppliedId)['fulfillment_source'] === 'external',
    'Failed stock reclaim changed the fulfillment owner.');
$stock->setAvailable($key, 0, 5);
$orders->setFulfillmentStatus($suppliedId, 'ready_to_ship', 'own');
expectStock((int) $db->queryFirstField('SELECT available_quantity FROM shop_product_inventory
    WHERE product_key=%s', $key) === 3, 'Switching back did not reclaim stock.');
$orders->setFulfillmentStatus($suppliedId, 'ready_to_ship', 'external', 'Dodavatel');
$orders->setFulfillmentStatus($suppliedId, 'shipped', 'external', 'Dodavatel');
expectStock((int) $db->queryFirstField('SELECT available_quantity FROM shop_product_inventory
    WHERE product_key=%s', $key) === 5, 'Externally shipped order consumed local stock.');
$controls->deleteOrder($suppliedId, (string) $supplied['order_number'], 1,
    'Úklid testovací objednávky');
expectStock((int) $db->queryFirstField('SELECT available_quantity FROM shop_product_inventory
    WHERE product_key=%s', $key) === 5, 'Deleting supplier order changed local inventory.');
expectStock((int) $db->queryFirstField('SELECT COUNT(*) FROM shop_stock_movements
    WHERE reference=%s', (string) $supplied['order_number']) === 0,
    'Deleted supplier order was recorded as dispatch from local stock.');

$newSlug = $slug . '-updated';
$db->query('UPDATE product_revisions SET slug=%s, active_slug=%s WHERE product_key=%s
    AND active_product_key IS NOT NULL', $newSlug, $newSlug, $key);
$links = (new OrderProductLinks($db))->forItems([$item], '/store/');
expectStock(($links[$key . ':cs'] ?? '') === '/store/cs/produkt/' . $newSlug . '?edit=1',
    'Order product links must follow the current slug.');

echo "Inventory database tests passed.\n";
