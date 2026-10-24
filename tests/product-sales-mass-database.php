<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Database\{ConnectionFactory, SchemaUpdater};
use SimpleStore\Product\{ProductSalesMassUpdate, ProductSalesRepository, PurchasePartnerRepository, ProductVariantRepository, ProductVariants};

$config = ['host' => '127.0.0.1', 'user' => 'root', 'password' => (string) getenv('MYSQL_TEST_PASSWORD'), 'database' => 'simple_store', 'port' => 3306];
$adminDb = ConnectionFactory::create($config);
$database = 'simple_store_sale_bulk_' . bin2hex(random_bytes(5));
$adminDb->query('CREATE DATABASE %b CHARACTER SET utf8mb4', $database);
$db = ConnectionFactory::create(array_replace($config, ['database' => $database]));
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$reject = static function (callable $call): void { try { $call(); } catch (InvalidArgumentException $expected) { return; } throw new RuntimeException('Invalid or stale sale change accepted.'); };
try {
    (new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
    $service = new ProductSalesMassUpdate($db, 'cs');
    $sales = new ProductSalesRepository($db);
    $partners = new PurchasePartnerRepository($db);
    $variants = new ProductVariantRepository($db);
    $partnerData = ['name' => 'Partner', 'ico' => '12345678', 'address' => 'Ulice 1, Praha',
        'email' => 'partner@example.test', 'terms_url' => 'https://example.test/terms', 'confirmed' => '1'];
    $partner = $partners->save(0, $partnerData);
    $draftPartner = $partners->save(0, ['name' => 'Draft']);
    $reject(static fn () => $service->preview('own'));
    foreach ([null, [], '', 'bad'] as $bad) $reject(static fn () => $service->preview($bad));
    $make = static function (string $name, ?string $key = null, string $language = 'cs') use ($db): string {
        $key ??= bin2hex(random_bytes(16));
        $db->insert('shop_product_revisions', ['product_key' => $key, 'active_product_key' => $key,
            'language' => $language, 'revision_number' => 1, 'slug' => 'sale-' . $key, 'active_slug' => 'sale-' . $key,
            'name' => $name, 'description' => 'Keep description', 'category' => 'batohy', 'price_czk' => 1800,
            'image_path' => 'images/test.webp', 'stock_status' => 'on_order', 'published' => 0]);
        $db->query('INSERT IGNORE INTO shop_product_inventory (product_key,available_quantity) VALUES (%s,7)', $key);
        return $key;
    };
    $normal = $make('Normal'); $make('Normal EN', $normal, 'en');
    $assisted = $make('Already assisted');
    $unconfigured = $make('Missing <seller>');
    $variantKey = $make('Color variants');
    $costly = $make('Cost exceeds retail');
    $draft = $make('Draft partner');
    $input = ['mode' => 'own', 'version' => '0', 'partner_id' => (string) $partner, 'supplier_price_czk' => '1678'];
    $sales->save($normal, 'cs', $input);
    $sales->save($assisted, 'cs', array_replace($input, ['mode' => 'assisted']));
    $sales->save($costly, 'cs', array_replace($input, ['supplier_price_czk' => '1900']));
    $sales->save($draft, 'cs', array_replace($input, ['partner_id' => (string) $draftPartner]));
    $items = ProductVariants::generate('Barva: Modrá | Červená | Zelená');
    $items[0]['quantity'] = 3; $items[1]['quantity'] = 5; $items[1]['price_czk'] = 3000;
    $items[2]['enabled'] = false; $items[2]['price_czk'] = 1;
    $variants->save($variantKey, ['enabled' => true, 'items' => $items, 'expected' => $variants->snapshot($variantKey)['expected']]);
    $items = $variants->snapshot($variantKey)['items'];
    $variantCosts = [$items[0]['id'] => '1500', $items[1]['id'] => '2500', $items[2]['id'] => '9999'];
    $sales->save($variantKey, 'cs', array_replace($input, ['supplier_price_czk' => '', 'variant_price' => $variantCosts]));
    $db->update('shop_product_sales', ['delivery_note' => 'Legacy note'], 'product_key=%s', $normal);
    $orderItems = json_encode([$sales->snapshot($assisted, '', 1800)], JSON_THROW_ON_ERROR);
    $db->insert('shop_orders', ['id' => 999, 'order_number' => 'SALE-BULK-999', 'total_czk' => 1800,
        'items_json' => $orderItems, 'shipping_json' => '{}', 'status' => 'pending', 'payment_method' => 'bank_transfer', 'payment_status' => 'pending', 'variable_symbol' => '999']);
    $db->insert('shop_order_stock_reservations', ['order_id' => 999, 'product_key' => $normal, 'quantity' => 2, 'state' => 'reserved']);
    $beforeSales = $db->query('SELECT * FROM shop_product_sales ORDER BY product_key');
    $preview = $service->preview('assisted');
    $check($preview['total'] === 6 && $preview['changed'] === 5 && $preview['unchanged'] === 1 && count($preview['issues']) === 3,
        'Preview must deduplicate translations and validate active variant overrides without requiring an unused base cost.');
    $check($service->counts() === ['own' => 5, 'assisted' => 1], 'Initial counts are wrong.');
    $check($db->query('SELECT * FROM shop_product_sales ORDER BY product_key') === $beforeSales, 'Preview wrote product settings.');
    $reject(static fn () => $service->apply(array_replace($preview, ['mode' => 'own'])));

    // Product, partner and variant price changes invalidate the reviewed plan.
    $db->update('shop_product_revisions', ['price_czk' => 1600], 'product_key=%s AND language=%s', $normal, 'en');
    $check(count($service->preview('assisted')['issues']) === 4, 'A lower translated retail price was not checked.');
    $reject(static fn () => $service->apply($preview));
    $db->update('shop_product_revisions', ['price_czk' => 1800], 'product_key=%s AND language=%s', $normal, 'en');
    $partners->save($partner, array_replace($partnerData, ['confirmed' => '0', 'version' => '1']));
    $reject(static fn () => $service->apply($preview));
    $check(count($service->preview('assisted')['issues']) === 6, 'Unconfirmed seller became checkout-ready.');
    $partners->save($partner, $partnerData + ['version' => '2']);
    $preview = $service->preview('assisted');
    $db->update('shop_product_variants', ['price_czk' => 1000], 'product_key=%s AND variant_key=%s', $variantKey, $items[0]['id']);
    $reject(static fn () => $service->apply($preview));
    $check(count($service->preview('assisted')['issues']) === 4, 'Invalid variant margin was not reported.');
    $db->update('shop_product_variants', ['price_czk' => null], 'product_key=%s AND variant_key=%s', $variantKey, $items[0]['id']);
    $preview = $service->preview('assisted');
    $extra = $make('New since preview');
    $reject(static fn () => $service->apply($preview));
    $db->query('DELETE FROM shop_product_revisions WHERE product_key=%s', $extra);
    $db->query('DELETE FROM shop_product_inventory WHERE product_key=%s', $extra);
    $check($service->counts() === ['own' => 5, 'assisted' => 1], 'Rejected plans partially changed the catalog.');

    // Stock changes do not invalidate a mode-only plan, nor get overwritten by it.
    $preview = $service->preview('assisted');
    $db->update('shop_product_inventory', ['available_quantity' => 4], 'product_key=%s', $normal);
    $db->update('shop_product_variants', ['available_quantity' => 2], 'product_key=%s AND variant_key=%s', $variantKey, $items[0]['id']);
    $protected = ['shop_product_revisions', 'shop_product_inventory', 'shop_product_variants', 'shop_orders', 'shop_order_stock_reservations'];
    $before = [];
    foreach ($protected as $table) $before[$table] = $db->query('SELECT * FROM ' . $table);
    $check($service->apply($preview) === 5 && $service->counts() === ['own' => 0, 'assisted' => 6], 'Not all products switched to assistance.');
    $reject(static fn () => $service->apply($preview));
    $check($sales->snapshot($normal, '', 1800)['assistance_fee_czk'] === 122, 'Prepared assistance lost its price split.');
    $check($sales->snapshot($variantKey, $items[1]['id'], 3000)['assistance_fee_czk'] === 500, 'Variant cost override was erased.');
    foreach ([$unconfigured, $costly, $draft] as $key) $reject(static fn () => $sales->snapshot($key, '', 1800));
    $reject(static fn () => $sales->save($normal, 'cs', array_replace($input, ['version' => '1'])));
    $check($service->apply($service->preview('assisted')) === 0, 'Repeated same mode created redundant writes.');
    $check($service->apply($service->preview('own')) === 6 && $service->counts() === ['own' => 6, 'assisted' => 0], 'Switching all products back failed.');
    foreach ([$normal, $assisted, $unconfigured, $variantKey, $costly, $draft] as $key) {
        $check($sales->snapshot($key, '', 1800) === [], 'Own sale still requires assistance settings.');
    }
    foreach ($beforeSales as $sale) {
        $current = $db->queryFirstRow('SELECT * FROM shop_product_sales WHERE product_key=%s', $sale['product_key']);
        unset($current['mode'], $current['version'], $sale['mode'], $sale['version']);
        $check($current === $sale, 'Switching back erased prepared partner data.');
    }
    foreach ($protected as $table) $check($db->query('SELECT * FROM ' . $table) === $before[$table], 'Mode switch changed ' . $table);
    $check($service->apply($service->preview('own')) === 0, 'Own mode no-op wrote settings.');

    // Actual admin preview, form escaping and session-token gate, including a subdirectory installation.
    $method = 'POST'; $site = ['default_language' => 'cs']; $adminUrl = '/shop/admin.php'; $basePath = '/shop/';
    $settingsError = ''; $_SESSION = []; $_POST = ['action' => 'products-sale-preview', 'sale_mode' => 'assisted'];
    require dirname(__DIR__) . '/src/Admin/settings-products.php';
    $check($saleBulkPreview['changed'] === 6 && isset($_SESSION['product_sale_bulk_preview']['token']), 'Admin preview was not staged.');
    $escape = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); $csrf = 'test-token';
    ob_start(); require dirname(__DIR__) . '/view/admin/settings-products.php'; $html = ob_get_clean();
    $check(str_contains($html, 'Missing &lt;seller&gt;') && str_contains($html, '/shop/cs/produkt/') &&
        str_contains($html, 'name="csrf" value="test-token"') && str_contains($html, 'name="action" value="products-sale-apply"'), 'Admin form lost escaping, base path or authenticated action.');
    $_POST = ['action' => 'products-sale-apply', 'preview_token' => 'wrong-token'];
    require dirname(__DIR__) . '/src/Admin/settings-products.php';
    $check($settingsError !== '' && $service->counts()['assisted'] === 0, 'Invalid session plan changed products.');
    $_POST = ['action' => 'products-sale-apply', 'preview_token' => $_SESSION['product_sale_bulk_preview']['token'], 'sale_mode' => 'own'];
    $db->update('shop_product_sales', ['version' => 99], 'product_key=%s', $normal);
    require dirname(__DIR__) . '/src/Admin/settings-products.php';
    $check(!isset($_SESSION['product_sale_bulk_preview']) && $service->counts()['assisted'] === 0 &&
        $saleBulkMode === 'assisted', 'Stale preview or posted target bypassed the stored plan.');
    echo "Bulk sale modes: preview, round trip, variants, drafts, protected data, stale plans and admin forms passed.\n";
} finally {
    $db->disconnect();
    $adminDb->query('DROP DATABASE %b', $database);
}
