<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Product\ProductMassUpdate;
use SimpleStore\Admin\AdminActionPolicy;

$db = ConnectionFactory::create(['host' => '127.0.0.1', 'user' => 'root', 'password' => (string) getenv('MYSQL_TEST_PASSWORD'), 'database' => 'simple_store', 'port' => 3306]);
$service = new ProductMassUpdate($db);
$key = bin2hex(random_bytes(16));
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
try {
    foreach (['cs', 'en'] as $language) {
        $db->insert('shop_product_revisions', ['product_key' => $key, 'active_product_key' => $key,
            'language' => $language, 'revision_number' => 1, 'slug' => 'mass-' . $key,
            'active_slug' => 'mass-' . $key, 'name' => 'Mass test', 'description' => 'Keep this text',
            'details_json' => '{"sections":[]}', 'category' => 'batohy', 'price_czk' => 987,
            'image_path' => 'images/test.webp', 'stock_status' => 'out_of_stock', 'published' => 0]);
    }
    $db->insert('shop_product_inventory', ['product_key' => $key, 'available_quantity' => 2]);
    $assert(AdminActionPolicy::allows('products-mass-preview') && AdminActionPolicy::allows('products-mass-apply'), 'Admin actions are not allowed.');
    foreach ([[], ['quantity' => '-1'], ['quantity' => '1.5'], ['quantity' => '1000001'], ['stock_status' => 'bad'], ['visibility' => 'bad']] as $bad) {
        try { ProductMassUpdate::choices($bad); throw new RuntimeException('Invalid choice accepted.'); }
        catch (InvalidArgumentException $expected) {}
    }
    $preview = $service->preview(['quantity' => '10', 'stock_status' => 'in_stock', 'visibility' => 'public']);
    $db->query('UPDATE shop_product_inventory SET available_quantity=1 WHERE product_key=%s', $key);
    try { $service->apply($preview); throw new RuntimeException('Stale preview accepted.'); }
    catch (InvalidArgumentException $expected) {}
    $assert((int) $db->queryFirstField('SELECT published FROM shop_product_revisions WHERE product_key=%s LIMIT 1', $key) === 0, 'Stale plan partially published products.');
    $service->apply($service->preview($preview['choices']));
    foreach ($db->query('SELECT * FROM shop_product_revisions WHERE product_key=%s AND active_product_key IS NOT NULL', $key) as $row) {
        $assert((int) $row['revision_number'] === 2 && (int) $row['published'] === 1 && $row['stock_status'] === 'in_stock', 'Language was not updated with a revision.');
        $assert($row['description'] === 'Keep this text' && (int) $row['price_czk'] === 987 && $row['image_path'] === 'images/test.webp', 'Other product data changed.');
    }
    $assert((int) $db->queryFirstField('SELECT COUNT(*) FROM shop_product_revisions WHERE product_key=%s AND revision_number=1 AND published=0 AND stock_status=%s', $key, 'out_of_stock') === 2, 'Original revisions were changed or removed.');
    $assert((int) $db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s', $key) === 10, 'Shared quantity is incorrect.');
    $assert($service->apply($service->preview($preview['choices'])) === 0, 'Same values created redundant changes.');
    $service->apply($service->preview(['quantity' => '0']));
    $assert((int) $db->queryFirstField('SELECT revision_number FROM shop_product_revisions WHERE product_key=%s AND active_product_key IS NOT NULL LIMIT 1', $key) === 2, 'Quantity-only change modified content revision.');
    $service->apply($service->preview(['stock_status' => 'on_order', 'visibility' => 'hidden']));
    $assert((int) $db->queryFirstField('SELECT published FROM shop_product_revisions WHERE product_key=%s AND active_product_key IS NOT NULL LIMIT 1', $key) === 0, 'Hide all failed.');
    echo "Product mass update database tests passed.\n";
} finally {
    $db->query('DELETE FROM shop_product_revisions WHERE product_key=%s', $key);
    $db->query('DELETE FROM shop_product_inventory WHERE product_key=%s', $key);
}
