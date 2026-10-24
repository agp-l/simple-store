<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\SchemaUpdater;
use SimpleStore\Category\CategoryRepository;
use SimpleStore\Product\{ProductRepository, ProductStockRepository, ProductVariants, ProductVariantRepository, ProductImport, ProductBulkTransfer, ProductInlineEditor, ProductMassUpdate, ProductVariantMerge};
use SimpleStore\Checkout\{BankTransferPayment, OrderRepository};
use SimpleStore\Admin\OrderControlRepository;

$db = ConnectionFactory::create(['host' => '127.0.0.1', 'user' => 'root', 'password' => (string) getenv('MYSQL_TEST_PASSWORD'), 'database' => 'simple_store', 'port' => 3306]);
(new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$reject = static function (callable $call): void { try { $call(); } catch (InvalidArgumentException $error) { return; } throw new RuntimeException('Unsafe variant change was accepted.'); };
$stock = new ProductStockRepository($db);
$products = new ProductRepository($db, ['cs', 'en'], new CategoryRepository($db), 50, $stock);
$variants = new ProductVariantRepository($db);
$prefix = 'variants-' . bin2hex(random_bytes(6));
$doc = ProductImport::decode(file_get_contents(dirname(__DIR__) . '/src/Product/examples/flextail.json'));
$doc['product'] = array_replace($doc['product'], ['slug' => $prefix, 'name' => $prefix, 'category_path' => 'batohy', 'price_czk' => 100, 'published' => true, 'stock_status' => 'in_stock']);
$key = $products->saveRevision(ProductImport::fields($doc))['product_key'];
$stock->setAvailable($key, 0, 10);
$generate = static function (array $post) use ($key): array {
    $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/variant-action.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot run the variant controller.');
    fwrite($pipes[0], json_encode($post + ['key' => $key, 'language' => 'cs', 'operation' => 'generate'], JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException($errors);
    return json_decode($output, true, 16, JSON_THROW_ON_ERROR);
};
$create = ['groups' => "Velikost: S | M\nBarva: Modrá | Červená", 'confirm' => '1', 'expected' => $variants->snapshot($key)['expected']];
$missing = $generate(array_replace($create, ['groups' => '']));
$check(isset($missing['variant_error']) && $variants->snapshot($key)['items'] === [], 'Empty generation changed inventory.');
$unconfirmed = $generate(array_replace($create, ['confirm' => '0']));
$check(isset($unconfirmed['variant_error']) && $variants->snapshot($key)['items'] === [], 'Unconfirmed mode switch created variants.');
$created = $generate($create);
$check(!isset($created['variant_error']), 'First generation failed: ' . ($created['variant_error']['message'] ?? ''));
$generated = $variants->snapshot($key);
$check($generated['enabled'] && count($generated['items']) === 4 && array_sum(array_column($generated['items'], 'quantity')) === 0,
    'Controller failed to create the first zero-stock variants.');
$check(count(array_filter($generated['items'], static fn (array $row): bool => $row['price_czk'] !== null)) === 0, 'New variants must inherit the base price.');
$duplicate = $generate(array_replace($create, ['expected' => $generated['expected']]));
$check(!isset($duplicate['variant_error']) && $variants->snapshot($key)['items'] === $generated['items'], 'Repeated generation duplicated variants or replaced their identities.');
$stale = $generate($create);
$check(isset($stale['variant_error']) && $variants->snapshot($key)['items'] === $generated['items'], 'Stale generation bypassed concurrency protection.');
$rows = $generated['items'];
$rows[0]['quantity'] = 3; $rows[0]['sku'] = 'S-BLUE'; $rows[0]['price_czk'] = 120;
$rows[1]['quantity'] = 1;
$rows[3]['stock_status'] = 'on_order';
$initial = ['enabled' => true, 'items' => $rows, 'expected' => $variants->snapshot($key)['expected']];
$variants->save($key, $initial);
$state = $variants->snapshot($key);
$check($state['items'][1]['price_czk'] === null, 'Inherited price became zero.');
$check((int) $db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s', $key) === 0, 'Converting duplicated the old shared quantity.');
$check($products->current($key, 'cs')['stock_quantity'] === 4 && $products->current($key, 'cs')['offer_price_czk'] === 100, 'Catalog variant stock/price aggregation failed.');
$reject(static fn () => $variants->save($key, $initial));
$reject(static fn () => $stock->setAvailable($key, 0, 20));
$bank = new BankTransferPayment('', '1004823033/3030', 'Test');
$orders = new OrderRepository($db, $bank, 7, $stock);
$controls = new OrderControlRepository($db, $stock);
$item = static function (array $variant, int $quantity = 1) use ($key, $prefix): array { return [
    'product_key' => $key, 'language' => 'cs', 'slug' => $prefix, 'name' => $prefix,
    'image_path' => 'images/product-placeholder.png', 'quantity' => $quantity,
    'unit_price_czk' => $variant['price_czk'] ?? 100, 'options' => $variant['choices'],
    'variant_key' => $variant['id'], 'variant_sku' => $variant['sku'],
]; };
$shipping = ['method' => 'gls_home', 'label' => 'GLS domů', 'name' => 'Test variant'];
$token = bin2hex(random_bytes(32));
$orderItems = [$item($rows[0], 2), $item($rows[1])];
$order = $orders->create(null, 'variants@example.test', $orderItems, $shipping, 79, $token);
$id = (int) $order['id'];
$after = $variants->snapshot($key);
$check(array_column($after['items'], 'quantity') === [1, 0, 0, 0], 'Order reserved the wrong combinations.');
$check($orders->create(null, 'variants@example.test', $orderItems, $shipping, 79, $token)['id'] === $order['id'], 'Order replay did not retain its identity.');
$check($variants->snapshot($key) === $after, 'Order replay reserved variants twice.');
$reject(static fn () => $variants->save($key, $state));
$reject(static fn () => $variants->save($key, array_replace($after, ['enabled' => false])));
$reject(static fn () => $orders->create(null, 'variants@example.test', [$item($rows[0], 2)], $shipping, 79, bin2hex(random_bytes(32))));
$wrong = $item($rows[0]); $wrong['unit_price_czk'] = 99;
$reject(static fn () => $orders->create(null, 'variants@example.test', [$wrong], $shipping, 79, bin2hex(random_bytes(32))));
$wrong = $item($rows[0]); $wrong['options']['Velikost'] = 'XL';
$reject(static fn () => $orders->create(null, 'variants@example.test', [$wrong], $shipping, 79, bin2hex(random_bytes(32))));
// Retirement keeps the original reservation identity for cancellations and reopenings.
$after['items'][0]['enabled'] = false; $variants->save($key, $after);
$db->query('UPDATE shop_orders SET payment_due_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id=%i', $id);
$orders->cancelOverdueBankTransfer($id);
$check(array_column($variants->snapshot($key)['items'], 'quantity') === [3, 1, 0, 0], 'Cancellation did not return retired variant stock.');
$controls->correctFulfillment($id, 'new', 1, 'Variant test reopening', 'reopen');
$check($variants->snapshot($key)['items'][0]['quantity'] === 1, 'Reopening did not reclaim the exact variant.');
$controls->deleteOrder($id, $order['order_number'], 1, 'Variant test cleanup');
$check($variants->snapshot($key)['items'][0]['quantity'] === 3, 'Deleting unshipped order lost stock.');
$check((int) $db->queryFirstField('SELECT COUNT(*) FROM shop_order_variant_reservations WHERE order_id=%i', $id) === 0, 'Deleted order retained reservation rows.');
$fresh = $variants->snapshot($key); $fresh['items'][0]['enabled'] = true; $variants->save($key, $fresh);
// Supplier fulfillment and reversals use the same variant ledger.
$order = $orders->create(null, 'variants@example.test', [$item($rows[0])], $shipping, 79, bin2hex(random_bytes(32)));
$id = (int) $order['id']; $orders->markPaid($id, 1);
$orders->setFulfillmentStatus($id, 'processing', 'external', 'Supplier');
$check($variants->snapshot($key)['items'][0]['quantity'] === 3, 'Supplier fulfillment kept local variant reserved.');
$orders->setFulfillmentStatus($id, 'ready_to_ship', 'own');
$check($variants->snapshot($key)['items'][0]['quantity'] === 2, 'Own fulfillment did not reclaim variant.');
$orders->setFulfillmentStatus($id, 'shipped', 'own');
$controls->deleteOrder($id, $order['order_number'], 1, 'Cleanup consumed stock');
$check($variants->snapshot($key)['items'][0]['quantity'] === 2, 'Deleting a shipped order returned consumed stock.');
// Ordinary content edits and revision restores never overwrite live quantities.
$current = $products->current($key, 'cs'); $before = $variants->snapshot($key);
$fields = ProductInlineEditor::fromRevision($current); $fields['name'] .= ' shared';
$products->saveRevision($fields, $key, (int) $current['revision_number']);
$check($variants->snapshot($key) === $before, 'Shared description edit changed stock.');
$transfer = new ProductBulkTransfer($db, $products, ['cs']);
$backup = $transfer->export([$products->current($key, 'cs')]);
$data = json_decode($backup, true, 40, JSON_THROW_ON_ERROR); $data['products'][0]['product']['variants']['items'][0]['quantity'] = 37;
$json = json_encode($data, JSON_THROW_ON_ERROR);
$plan = $transfer->prepare($json, false, false); $check($plan[0]['status'] === 'pending', $plan[0]['message']); $transfer->import($plan[0]);
$check($variants->snapshot($key)['items'][0]['quantity'] === 2, 'Default backup import overwrote live variant stock.');
$plan = $transfer->prepare($json, true, true); $transfer->import($plan[0]);
$check($variants->snapshot($key)['items'][0]['quantity'] === 37, 'Explicit backup restore did not restore variant stock.');
$stale = $transfer->prepare($json, true, true);
$fresh = $variants->snapshot($key); $fresh['items'][0]['quantity'] = 36; $variants->save($key, $fresh);
$beforeRevision = (int) $products->current($key, 'cs')['revision_number'];
$reject(static fn () => $transfer->import($stale[0]));
$check((int) $products->current($key, 'cs')['revision_number'] === $beforeRevision, 'Variant conflict partially saved the product.');
$mass = new ProductMassUpdate($db); $preview = $mass->preview(['quantity' => '5']); $mass->apply($preview);
$check(array_column($variants->snapshot($key)['items'], 'quantity') === [5, 5, 5, 5], 'Mass inventory ignored active variants.');
$reject(static fn () => $mass->apply($preview));
// Merge only an explicitly selected single-provision duplicate, retaining the original content.
$doc['product']['slug'] = $prefix . '-duplicate'; $doc['product']['name'] = 'Old XL red';
$source = $products->saveRevision(ProductImport::fields($doc))['product_key']; $stock->setAvailable($source, 0, 7);
$merge = new ProductVariantMerge($db);
$mergePlan = $merge->preview($key, $prefix . '-duplicate', 'cs', "Velikost: XL\nBarva: Červená", $variants->snapshot($key)['expected']);
$merge->apply($key, $mergePlan);
$check($products->current($source, 'cs')['stock_quantity'] === 0 && !(bool) $products->current($source, 'cs')['published'], 'Merged source was not hidden or stock was duplicated.');
$check(count($variants->snapshot($key)['items']) === 5 && $variants->snapshot($key)['items'][4]['quantity'] === 7, 'Merge lost source stock.');
$check($merge->redirect('cs', $prefix . '-duplicate') === $prefix, 'Merged public URL did not redirect.');
$reject(static fn () => $merge->apply($key, $mergePlan));
$check($products->current($source, 'cs')['name'] === 'Old XL red', 'Merge deleted the old description.');
// Price ordering must use the variant offer, not the unused base price.
$page = $products->publishedPage('cs', null, $prefix, 'price-asc');
$check(count($page['items']) >= 1, 'Price-ordered catalog query failed with variants.');
// Clear existing price exceptions atomically, preserving stock and future product-price inheritance.
$beforePrices = $variants->snapshot($key);
$sharedPrices = \SimpleStore\Product\ProductVariantForm::inheritPrices($beforePrices);
$variants->save($key, $sharedPrices);
$reject(static fn () => $variants->save($key, $sharedPrices)); // stale page must not overwrite newer stock
$inherited = $variants->snapshot($key);
$check(array_filter($inherited['items'], static fn (array $variant): bool => $variant['price_czk'] !== null) === [],
    'Shared prices must remain NULL in storage rather than being copied to each variant.');
$check(array_column($inherited['items'], 'quantity') === array_column($beforePrices['items'], 'quantity') &&
    array_column($inherited['items'], 'sku') === array_column($beforePrices['items'], 'sku') &&
    array_column($inherited['items'], 'image_path') === array_column($beforePrices['items'], 'image_path'), 'Unifying prices changed unrelated row data.');
$current = $products->current($key, 'cs');
$fields = ProductInlineEditor::fromRevision($current); $fields['price_czk'] = '1800';
$products->saveRevision($fields, $key, (int) $current['revision_number']);
$current = $products->current($key, 'cs');
$check($current['offer_price_czk'] === 1800 && !$current['price_varies'], 'Catalog still advertises old fixed variant prices.');
foreach ($inherited['items'] as $variant) {
    if (!$variant['enabled']) continue;
    // Base product columns can be returned as numeric strings by MeekroDB.
    $check((int) ProductVariantRepository::resolve($current, $variant['id'])['price_czk'] === 1800,
        'Stored inheritance does not resolve to the latest shared product price.');
}
foreach ([$source, $key] as $cleanup) {
    $db->query('DELETE FROM shop_product_redirects WHERE target_key=%s', $cleanup);
    $db->query('DELETE FROM shop_product_variants WHERE product_key=%s', $cleanup);
    $db->query('DELETE FROM shop_product_variant_settings WHERE product_key=%s', $cleanup);
    $db->query('DELETE FROM shop_product_inventory WHERE product_key=%s', $cleanup);
    $db->query('DELETE FROM shop_product_revisions WHERE product_key=%s', $cleanup);
}
echo "Variant inventory, reservations, import, mass updates and duplicate conversion passed.\n";
