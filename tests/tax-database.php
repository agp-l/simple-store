<?php
declare(strict_types=1);

// CI-only integration check against an isolated disposable MySQL service.
require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Accounting\InvoiceRepository;
use SimpleStore\Accounting\OrderMailQueue;
use SimpleStore\Accounting\TaxEvidenceRepository;
use SimpleStore\Admin\OrderControlRepository;
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\SchemaUpdater;

$db = ConnectionFactory::create([
    'host' => '127.0.0.1', 'user' => 'root', 'password' => (string) getenv('MYSQL_TEST_PASSWORD'),
    'database' => 'simple_store', 'port' => 3306,
]);
$schema = new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql');
if (!$schema->apply() || $schema->apply()) {
    throw new RuntimeException('Schema must be installable and repeatable.');
}
$tax = new TaxEvidenceRepository($db);
$invoices = new InvoiceRepository($db);
$mail = new OrderMailQueue($db, static fn (): bool => true);
if (!$tax->installed() || !$invoices->installed() || !$mail->installed()) {
    throw new RuntimeException('OSVC tables were not created.');
}

$key = str_repeat('a', 32);
$items = [['product_key' => $key, 'name' => 'Stan', 'quantity' => 2, 'unit_price_czk' => 450]];
$db->insert('shop_orders', [
    'order_number' => 'DB-LIVE-1', 'status' => 'completed',
    'customer_email' => 'buyer@example.test', 'subtotal_czk' => 900,
    'shipping_czk' => 90, 'total_czk' => 990,
    'items_json' => json_encode($items, JSON_THROW_ON_ERROR),
    'shipping_json' => '{"method":"gls_home","recipient":"Buyer"}',
    'payment_method' => 'bank_transfer', 'payment_status' => 'paid',
    'payment_details_json' => '{"account_display":"TEST-ACCOUNT"}',
    'variable_symbol' => '1234567890',
]);
$id = (int) $db->queryFirstField('SELECT id FROM shop_orders WHERE order_number=%s', 'DB-LIVE-1');
$db->insert('shop_sale_lines', [
    'order_id' => $id, 'line_no' => 1, 'product_key' => $key,
    'name' => 'Stan', 'quantity' => 2, 'unit_price_czk' => 450,
]);
$tax->saveSettings(['name' => 'Test OSVČ', 'ico' => '12345678', 'street' => 'Test 1',
    'city' => 'Praha', 'postal_code' => '11000', 'email' => 'shop@example.test',
    'phone' => '', 'bank_account' => '', 'mail_from' => 'shop@example.test']);
$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Prague')))->format('Y-m-d');
$tax->addOrderReceipt($id, ['entry_date' => $today, 'reference' => 'BANK-1']);
if ($tax->orderReceipt($id)['amount_czk'] != 990) {
    throw new RuntimeException('Bank receipt did not link to the order.');
}
$invoice = $invoices->issue($id, $tax->settings(), ['name' => 'Buyer', 'street' => '',
    'city' => '', 'postal_code' => '', 'ico' => '']);
$mailId = $mail->enqueueInvoice($invoice);
if (!$mail->dispatch($mailId, 'shop@example.test') ||
    $invoices->byOrder($id)['emailed_at'] === null) {
    throw new RuntimeException('Invoice mail did not link to the issued invoice.');
}
$invoices->renumber((int) $invoice['id'], 'CUSTOM-2026-9', 1,
    'Oprava pořadového čísla');
if ($invoices->byOrder($id)['document_number'] !== 'CUSTOM-2026-9' ||
    count($invoices->numberHistory((int) $invoice['id'])) !== 1) {
    throw new RuntimeException('Invoice number correction lost its history.');
}
$year = (int) substr($today, 0, 4);
if ($tax->summary($year)['income'] !== 990 || count($tax->saleLines($year)) !== 1) {
    throw new RuntimeException('Tax ledger and sale line disagree with the order.');
}

(new OrderControlRepository($db))->deleteOrder($id, 'DB-LIVE-1', 1,
    'Duplicitní objednávka s již vystavenou fakturou.');
$keptInvoice = $invoices->byId((int) $invoice['id']);
if ((int) $db->queryFirstField('SELECT COUNT(*) FROM shop_orders WHERE id=%i', $id) !== 0 ||
    $keptInvoice === null || $keptInvoice['order_id'] !== null ||
    $keptInvoice['document_number'] !== 'CUSTOM-2026-9' ||
    count($invoices->numberHistory((int) $invoice['id'])) !== 1 ||
    $tax->summary($year)['income'] !== 990) {
    throw new RuntimeException('Deleting an order removed its issued invoice, number history or tax receipt.');
}

$db->insert('shop_orders', [
    'order_number' => 'DB-LIVE-DELETE', 'status' => 'shipped',
    'customer_email' => 'second@example.test', 'subtotal_czk' => 900,
    'shipping_czk' => 90, 'total_czk' => 990,
    'items_json' => json_encode($items, JSON_THROW_ON_ERROR), 'shipping_json' => '{}',
    'payment_method' => 'bank_transfer', 'payment_status' => 'paid',
    'variable_symbol' => '1234567891',
]);
$paidId = (int) $db->queryFirstField('SELECT id FROM shop_orders WHERE order_number=%s', 'DB-LIVE-DELETE');
$db->insert('shop_sale_lines', [
    'order_id' => $paidId, 'line_no' => 1, 'product_key' => $key,
    'name' => 'Stan', 'quantity' => 2, 'unit_price_czk' => 450,
]);
$db->insert('shop_carrier_shipments', [
    'order_id' => $paidId, 'status' => 'registered', 'method' => 'gls_home',
    'draft_json' => '{}', 'tracking_number' => 'GLS987654',
    'created_by' => 1, 'updated_by' => 1,
]);
$tax->addOrderReceipt($paidId, ['entry_date' => $today, 'reference' => 'BANK-2']);
(new OrderControlRepository($db))->deleteOrder($paidId, 'DB-LIVE-DELETE', 1,
    'Oprava duplicitního prodeje.');
if ((int) $db->queryFirstField('SELECT COUNT(*) FROM shop_orders WHERE id=%i', $paidId) !== 0 ||
    (int) $db->queryFirstField('SELECT COUNT(*) FROM shop_deleted_sale_lines
        WHERE order_number=%s', 'DB-LIVE-DELETE') !== 1 ||
    (int) $db->queryFirstField('SELECT COUNT(*) FROM shop_tax_entries
        WHERE reference=%s AND order_id IS NULL', 'BANK-2') !== 1 ||
    (int) $db->queryFirstField('SELECT COUNT(*) FROM shop_stock_movements
        WHERE reference=%s AND quantity_change=%i', 'DB-LIVE-DELETE', -2) !== 1 ||
    (int) $db->queryFirstField('SELECT COUNT(*) FROM shop_deleted_shipments
        WHERE order_number=%s AND external_number=%s', 'DB-LIVE-DELETE', 'GLS987654') !== 1) {
    throw new RuntimeException('Deletion of a paid sale lost its item, bank, stock or carrier evidence.');
}

$db->insert('shop_orders', [
    'order_number' => 'TEST-LIVE-2', 'status' => 'test', 'customer_email' => 'test@example.test',
    'total_czk' => 100, 'items_json' => '[]', 'shipping_json' => '{}',
    'payment_method' => 'test', 'payment_status' => 'test',
]);
$testId = (int) $db->queryFirstField('SELECT id FROM shop_orders WHERE order_number=%s', 'TEST-LIVE-2');
(new OrderControlRepository($db))->deleteOrder($testId, 'TEST-LIVE-2', 1,
    'Smazání vývojového testu.');
if ((int) $db->queryFirstField('SELECT COUNT(*) FROM shop_orders WHERE id=%i', $testId) !== 0 ||
    (int) $db->queryFirstField('SELECT COUNT(*) FROM shop_order_admin_events WHERE order_id=%i', $testId) !== 0) {
    throw new RuntimeException('Test order left an admin record.');
}

echo "Live tax database integration passed.\n";
