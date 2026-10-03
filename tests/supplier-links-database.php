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
