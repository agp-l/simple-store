<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Database\{ConnectionFactory, SchemaUpdater};
use SimpleStore\Product\{ProductVariantBulkCreate, ProductVariantRepository, ProductVariants};

$config = ['host' => '127.0.0.1', 'user' => 'root', 'password' => (string) getenv('MYSQL_TEST_PASSWORD'), 'database' => 'simple_store', 'port' => 3306];
$adminDb = ConnectionFactory::create($config);
$database = 'simple_store_variant_bulk_' . bin2hex(random_bytes(5));
$adminDb->query('CREATE DATABASE %b CHARACTER SET utf8mb4', $database);
$db = ConnectionFactory::create(array_replace($config, ['database' => $database]));
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$reject = static function (callable $call): void { try { $call(); } catch (InvalidArgumentException $expected) { return; } throw new RuntimeException('Unsafe bulk operation accepted.'); };
try {
    (new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
    $make = static function (string $name, array $options = [], string $sizes = '', ?string $key = null, string $language = 'cs') use ($db): string {
        $key ??= bin2hex(random_bytes(16));
        $db->insert('shop_product_revisions', ['product_key' => $key, 'active_product_key' => $key, 'language' => $language,
            'revision_number' => 1, 'slug' => 'test-' . $key, 'active_slug' => 'test-' . $key, 'name' => $name,
            'description' => 'Keep common description', 'category' => 'batohy', 'price_czk' => 1234,
            'image_path' => 'images/product-placeholder.png', 'stock_status' => 'out_of_stock', 'published' => 0,
            'sizes' => $sizes, 'details_json' => $sizes !== '' ? null : json_encode(['options' => $options, 'gallery' => [], 'sections' => [], 'specifications' => []])]);
        $db->query('INSERT IGNORE INTO shop_product_inventory (product_key,available_quantity) VALUES (%s,7)', $key);
        return $key;
    };
    $options = [['name' => 'Velikost', 'values' => ['S', 'M']], ['name' => 'Barva', 'values' => ['Modrá', 'Červená']]];
    $normal = $make('Jacket', $options);
    $make('Jacket EN', [['name' => 'Size', 'values' => ['EN-only']]], '', $normal, 'en');
    $plain = $make('No choices');
    $existing = $make('Existing', $options);
    $disabled = $make('Disabled variants', $options);
    $legacy = $make('Legacy', [], 'S,M,L');
    $literal = $make('Literal', [['name' => 'Délka: zip', 'values' => ['levý | pravý', 'střed']]]);
    $large = $make('Too many', [['name' => 'Size', 'values' => array_map('strval', range(1, 40))], ['name' => 'Color', 'values' => array_map('strval', range(1, 40))]]);
    $reserved = $make('Reserved', $options);
    foreach ([999, 1000] as $orderId) $db->insert('shop_orders', ['id' => $orderId, 'order_number' => 'BULK-' . $orderId, 'total_czk' => 1234, 'items_json' => '[]', 'shipping_json' => '{}', 'status' => 'pending', 'payment_method' => 'bank_transfer', 'payment_status' => 'pending', 'variable_symbol' => (string) $orderId]);
    $db->insert('shop_order_stock_reservations', ['order_id' => 999, 'product_key' => $reserved, 'quantity' => 1, 'state' => 'reserved']);
    $variants = new ProductVariantRepository($db);
    $items = ProductVariants::generate('Velikost: S'); $items[0]['quantity'] = 23; $items[0]['sku'] = 'KEEP';
    foreach ([$existing, $disabled] as $key) $variants->save($key, ['enabled' => $key === $existing, 'expected' => $variants->snapshot($key)['expected'], 'items' => $items]);
    $before = [$variants->snapshot($existing), $variants->snapshot($disabled)];
    $service = new ProductVariantBulkCreate($db, 'cs');
    foreach ([null, [], '', '-1', '1.5', '1000001'] as $bad) $reject(static fn () => $service->preview($bad));
    $preview = $service->preview('10');
    $check(count($preview['products']) === 3 && $preview['variant_count'] === 9 && count($preview['skipped']) === 5, 'Preview must deduplicate languages and explain skipped products.');
    $check(!$variants->snapshot($normal)['enabled'], 'Preview wrote variant inventory.');
    $db->query('UPDATE shop_product_inventory SET available_quantity=6 WHERE product_key=%s', $normal);
    $reject(static fn () => $service->apply($preview));
    $check(!$variants->snapshot($legacy)['enabled'], 'Stale stock caused partial bulk write.');
    $preview = $service->preview('10');
    $db->query('UPDATE shop_product_revisions SET name=%s WHERE product_key=%s', 'Changed since preview', $normal);
    $reject(static fn () => $service->apply($preview));
    $preview = $service->preview('10');
    $db->insert('shop_order_stock_reservations', ['order_id' => 1000, 'product_key' => $normal, 'quantity' => 1, 'state' => 'reserved']);
    $reject(static fn () => $service->apply($preview));
    $db->query('DELETE FROM shop_order_stock_reservations WHERE order_id=1000');
    $preview = $service->preview('10');
    $result = $service->apply($preview);
    $check($result === ['products' => 3, 'variants' => 9], 'Bulk result counts are incorrect.');
    $state = $variants->snapshot($normal);
    $check(count($state['items']) === 4 && array_column($state['items'], 'quantity') === [10, 10, 10, 10], 'Each combination needs its own initial quantity.');
    $check($state['items'][0]['choices'] === ['Barva' => 'Modrá', 'Velikost' => 'S'], 'The default language was ignored.');
    $check($state['items'][0]['price_czk'] === null && $state['items'][0]['image_path'] === '' && $state['items'][0]['sku'] === '', 'New variants should inherit price/photo and leave SKU optional.');
    $check([$variants->snapshot($existing), $variants->snapshot($disabled)] === $before, 'Existing or retired variants were overwritten.');
    $check((int) $db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s', $normal) === 0, 'Old shared stock was duplicated.');
    $check((int) $db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s', $plain) === 7, 'Unrelated stock was changed.');
    $check((int) $db->queryFirstField('SELECT COUNT(*) FROM shop_product_revisions WHERE revision_number<>1 OR published<>0 OR description<>%s', 'Keep common description') === 0, 'Bulk creation changed content or visibility.');
    $check($variants->snapshot($literal)['items'][0]['choices'] === ['Délka: zip' => 'levý | pravý'], 'Option punctuation was split.');
    $reject(static fn () => $service->apply($preview));
    $check($service->apply($service->preview('10')) === ['products' => 0, 'variants' => 0], 'Repeated operation duplicated variants.');
    $zero = $make('Zero stock', $options);
    $service->apply($service->preview('0'));
    $check(ProductVariants::availability($variants->snapshot($zero)['items'][0]) === 'out_of_stock', 'Zero stock became purchasable.');
    echo "Bulk variant preview, stale writes, reservations, translations and idempotency passed.\n";
} finally {
    $db->disconnect();
    $adminDb->query('DROP DATABASE %b', $database);
}
