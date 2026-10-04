<?php
declare(strict_types=1);

// CI integration: preserve checkout pricing while changing only the fulfillment carrier.
require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Admin\OrderShippingRepository;
use SimpleStore\Checkout\BankTransferPayment;
use SimpleStore\Checkout\OrderRepository;
use SimpleStore\Checkout\ShippingPolicy;
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\SchemaUpdater;

function expectShipping(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$db = ConnectionFactory::create([
    'host' => '127.0.0.1', 'user' => 'root', 'password' => (string) getenv('MYSQL_TEST_PASSWORD'),
    'database' => 'simple_store', 'port' => 3306,
]);
(new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
$shippingManager = new OrderShippingRepository($db, new ShippingPolicy(ShippingPolicy::defaults()));
expectShipping($shippingManager->installed(), 'Dispatch override migration is missing.');

$orderRepository = new OrderRepository($db, new BankTransferPayment('', '1265098001/5500', 'Testovací obchod'));
$item = ['product_key' => bin2hex(random_bytes(16)), 'language' => 'cs', 'slug' => 'test-dopravy',
    'name' => 'Test dopravy', 'image_path' => '', 'quantity' => 1, 'unit_price_czk' => 123,
    'options' => []];
$originalShipping = ['method' => 'gls_home', 'label' => 'GLS – na adresu',
    'recipient' => 'Eva Nová', 'name' => 'Eva Nová', 'street' => 'Nádražní 1',
    'city' => 'Praha', 'postal_code' => '110 00', 'country' => 'CZ',
    'phone' => '+420777123456', 'email' => 'eva@example.test'];
$order = $orderRepository->create(null, 'eva@example.test', [$item], $originalShipping,
    79, bin2hex(random_bytes(32)));
$id = (int) $order['id'];
$shippingManager->change($id, 1, 'ppl_home', 'gls_home', 'Expedice kurýrem PPL');
$changed = $orderRepository->findById($id);
expectShipping($changed !== null && $changed['shipping']['method'] === 'ppl_home',
    'Order details did not use the effective carrier.');
expectShipping($changed['shipping_ordered'] === $originalShipping &&
    (int) $changed['shipping_czk'] === 79 && (int) $changed['total_czk'] === 202,
    'Changing the dispatch carrier modified the agreed sale or price.');
expectShipping((string) $db->queryFirstField('SELECT shipping_json FROM shop_orders WHERE id=%i', $id) ===
    json_encode($originalShipping, JSON_UNESCAPED_UNICODE),
    'The original delivery snapshot was overwritten.');
expectShipping((int) $db->queryFirstField('SELECT COUNT(*) FROM shop_order_admin_events
    WHERE order_id=%i AND action=%s AND old_status=%s AND new_status=%s',
    $id, 'shipping_changed', 'gls_home', 'ppl_home') === 1,
    'The change was not recorded with both carrier codes.');
try {
    $shippingManager->change($id, 1, 'dpd_home', 'gls_home', 'Neaktuální formulář');
    throw new RuntimeException('A stale delivery form succeeded.');
} catch (InvalidArgumentException $expected) {
    expectShipping(str_contains($expected->getMessage(), 'mezitím'), 'Wrong stale form error.');
}
$shippingManager->change($id, 1, 'gls_home', 'ppl_home', 'Vrácení původní dopravy');
expectShipping($db->queryFirstField('SELECT dispatch_shipping_json FROM shop_orders WHERE id=%i', $id) === null,
    'Returning to original delivery did not clear the override.');

$pickupOriginal = ['method' => 'gls_pickup', 'label' => 'GLS – ParcelShop',
    'recipient' => 'Eva Nová', 'name' => 'Eva Nová', 'country' => 'CZ',
    'pickup_code' => 'P12345', 'pickup_point' => 'Praha box',
    'pickup_address' => 'Nádražní 5, Praha 1'];
$pickupOrder = $orderRepository->create(null, 'eva@example.test', [$item], $pickupOriginal,
    59, bin2hex(random_bytes(32)));
$pickupId = (int) $pickupOrder['id'];
$shippingManager->change($pickupId, 1, 'dpd_home', 'gls_pickup',
    'Adresa ověřena se zákazníkem', false,
    ['street' => 'Jarní 12', 'city' => 'Brno', 'postal_code' => '602 00'], true);
$pickupChanged = $orderRepository->findById($pickupId);
expectShipping($pickupChanged !== null && $pickupChanged['shipping']['method'] === 'dpd_home' &&
    $pickupChanged['shipping']['street'] === 'Jarní 12' &&
    !isset($pickupChanged['shipping']['pickup_code']) &&
    $pickupChanged['shipping_ordered'] === $pickupOriginal &&
    (int) $pickupChanged['shipping_czk'] === 59 && (int) $pickupChanged['total_czk'] === 182,
    'Pickup order redirect lost the original purchase or saved the wrong dispatch address.');
$shippingManager->change($pickupId, 1, 'gls_pickup', 'dpd_home', 'Vrácení místa odběru');
expectShipping($db->queryFirstField('SELECT dispatch_shipping_json FROM shop_orders WHERE id=%i', $pickupId) === null,
    'Original pickup point was not restored.');

echo "Order shipping database tests passed.\n";
