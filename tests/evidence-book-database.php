<?php
declare(strict_types=1);

// Run against the disposable MySQL/MariaDB service used by the database CI jobs.
require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Accounting\EvidenceBookRepository;
use SimpleStore\Accounting\InvoiceRepository;
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\SchemaUpdater;

$db = ConnectionFactory::create([
    'host' => '127.0.0.1', 'user' => 'root', 'password' => (string) getenv('MYSQL_TEST_PASSWORD'),
    'database' => 'simple_store', 'port' => 3306,
]);
(new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
$book = new EvidenceBookRepository($db);
if (!$book->installed()) throw new RuntimeException('Evidence book tables are missing.');

$suffix = strtoupper(bin2hex(random_bytes(4)));
$linkedNumber = 'DB-EVID-LINKED-' . $suffix;
$deletedNumber = 'DB-DELETED-' . $suffix;
$invoiceNumber = 'EV-' . $suffix;
$detachedNumber = 'EV-OLD-' . $suffix;
$variableSymbol = (string) random_int(100000000, 999999999);
$seller = json_encode(['name' => 'Prodávající'], JSON_THROW_ON_ERROR);
$buyer = json_encode(['name' => 'Eva Dokladová', 'email' => 'eva@example.test'], JSON_THROW_ON_ERROR);
$items = json_encode([['name' => 'Stan', 'quantity' => 1, 'unit_price_czk' => 500]], JSON_THROW_ON_ERROR);
$assert = static function (bool $result, string $message): void {
    if (!$result) throw new RuntimeException($message);
};

$db->startTransaction();
try {
    // A full first page and a second page exercise the derived UNION pagination.
    for ($number = 1; $number <= 31; $number++) {
        $db->insert('shop_orders', [
            'order_number' => 'DB-EVID-PENDING-' . $suffix . '-' . $number,
            'status' => 'new', 'customer_email' => 'pending@example.test',
            'subtotal_czk' => 500, 'shipping_czk' => 0, 'total_czk' => 500,
            'items_json' => $items, 'shipping_json' => '{}',
            'payment_method' => 'bank_transfer', 'payment_status' => 'pending',
            'created_at' => '2098-01-02 09:00:00',
        ]);
    }
    $db->insert('shop_orders', [
        'order_number' => $linkedNumber, 'status' => 'shipped',
        'customer_email' => 'eva@example.test', 'subtotal_czk' => 500,
        'shipping_czk' => 0, 'total_czk' => 500, 'items_json' => $items,
        'shipping_json' => '{}', 'variable_symbol' => $variableSymbol,
        'payment_method' => 'bank_transfer', 'payment_status' => 'paid',
        'payment_paid_at' => '2098-06-03 09:00:00', 'created_at' => '2098-06-01 09:00:00',
    ]);
    $linkedId = (int) $db->queryFirstField('SELECT id FROM shop_orders WHERE order_number=%s', $linkedNumber);
    $db->insert('shop_invoices', [
        'order_id' => $linkedId, 'order_number' => $linkedNumber,
        'document_number' => $invoiceNumber, 'issue_date' => '2098-06-04',
        'due_date' => '2098-06-04', 'seller_json' => $seller,
        'buyer_json' => $buyer, 'items_json' => $items, 'subtotal_czk' => 500,
        'shipping_czk' => 0, 'total_czk' => 500, 'variable_symbol' => $variableSymbol,
        'payment_method' => 'bank_transfer',
    ]);
    $db->insert('shop_tax_entries', [
        'entry_date' => '2098-06-05', 'direction' => 'income', 'account' => 'bank',
        'tax_kind' => 'taxable', 'amount_czk' => 500,
        'description' => 'Úhrada objednávky', 'counterparty' => 'Eva Dokladová',
        'reference' => 'BANK-' . $suffix, 'order_id' => $linkedId,
    ]);
    $db->insert('shop_tax_entries', [
        'entry_date' => '2098-06-02', 'direction' => 'expense', 'account' => 'bank',
        'tax_kind' => 'deductible', 'amount_czk' => 150,
        'description' => 'Hosting 20%', 'counterparty' => 'Dodavatel Hosting',
        'reference' => 'BILL_' . $suffix, 'order_id' => null,
    ]);
    $db->insert('shop_invoices', [
        'order_id' => null, 'order_number' => $deletedNumber,
        'document_number' => $detachedNumber, 'issue_date' => '2098-07-01',
        'due_date' => '2098-07-01', 'seller_json' => $seller,
        'buyer_json' => $buyer, 'items_json' => $items, 'subtotal_czk' => 500,
        'shipping_czk' => 0, 'total_czk' => 500, 'payment_method' => 'bank_transfer',
    ]);

    $first = $book->page(2098, 0);
    $assert(count($first['items']) === 30 && $first['nextOffset'] === 30,
        'First evidence page has the wrong size or cursor.');
    $second = $book->page(2098, 30);
    $all = array_merge($first['items'], $second['items']);
    $assert(count($all) === 34 && $second['nextOffset'] === null,
        'The second page lost or duplicated an order, receipt, or detached invoice.');
    $kinds = array_count_values(array_column($all, 'kind'));
    $assert($kinds === ['invoice' => 1, 'order' => 32, 'entry' => 1] ||
        ($kinds['invoice'] ?? 0) === 1 && ($kinds['order'] ?? 0) === 32 && ($kinds['entry'] ?? 0) === 1,
        'The evidence book mixed document and cash row types.');

    $paid = $book->page(2098, 0, $linkedNumber)['items'];
    $assert(count($paid) === 1 && $paid[0]['kind'] === 'order' &&
        $paid[0]['order_id'] === $linkedId && $paid[0]['payment_status'] === 'paid' &&
        $paid[0]['total_czk'] === 500 && $paid[0]['invoice_number'] === $invoiceNumber &&
        $paid[0]['receipt_date'] === '2098-06-05' && $paid[0]['receipt_amount_czk'] === 500 &&
        $paid[0]['receipt_id'] === $paid[0]['entry_id'] &&
        $paid[0]['activity_date'] === '2098-06-05',
        'The paid order did not retain distinct invoice, payment, and journal facts.');
    foreach ([$invoiceNumber, $variableSymbol, 'Eva Dokladová', 'BANK-' . $suffix] as $needle) {
        $rows = $book->page(2098, 0, $needle)['items'];
        $linkedMatches = array_filter($rows, static fn (array $row): bool => $row['order_id'] === $linkedId);
        $assert($linkedMatches !== [],
            'Searching an order by its invoice, VS, buyer, or bank reference failed.');
    }
    $manual = $book->page(2098, 0, 'BILL_' . $suffix)['items'];
    $assert(count($manual) === 1 && $manual[0]['kind'] === 'entry' &&
        $manual[0]['entry_id'] > 0 && $manual[0]['receipt_id'] === null &&
        $manual[0]['entry_reference'] === 'BILL_' . $suffix &&
        $manual[0]['entry_direction'] === 'expense' && $manual[0]['total_czk'] === null,
        'A standalone expense must retain its reference and must not claim an order payment.');
    $assert(count($book->page(2098, 0, 'Hosting 20%')['items']) === 1,
        'Literal percent signs must not turn into LIKE wildcards.');
    $detached = $book->page(2098, 0, $detachedNumber)['items'];
    $assert(count($detached) === 1 && $detached[0]['kind'] === 'invoice' &&
        $detached[0]['invoice_number'] === $detachedNumber &&
        $detached[0]['order_id'] === null && $detached[0]['payment_status'] === '',
        'An invoice from a deleted order disappeared or invented a payment status.');

    $invoices = new InvoiceRepository($db);
    $invoicePageOne = $invoices->page(2098, 0, 1);
    $invoicePageTwo = $invoices->page(2098, 1, 1);
    $assert(count($invoicePageOne['items']) === 1 &&
        $invoicePageOne['items'][0]['document_number'] === $detachedNumber &&
        $invoicePageOne['nextOffset'] === 1 &&
        count($invoicePageTwo['items']) === 1 &&
        $invoicePageTwo['items'][0]['document_number'] === $invoiceNumber &&
        $invoicePageTwo['nextOffset'] === null,
        'Invoice pagination lost its stable issue-date order or detached invoice.');
    foreach ([[1999, 0, 50], [2098, -1, 50], [2098, 0, 0], [2098, 0, 101]] as $input) {
        try {
            $invoices->page(...$input);
            throw new RuntimeException('Invalid invoice pagination input was accepted.');
        } catch (InvalidArgumentException $expected) {
        }
    }

    $crossYearNumber = 'DB-CROSS-' . $suffix;
    $db->insert('shop_orders', [
        'order_number' => $crossYearNumber, 'status' => 'completed',
        'customer_email' => 'cross@example.test', 'subtotal_czk' => 500,
        'shipping_czk' => 0, 'total_czk' => 500, 'items_json' => $items,
        'shipping_json' => '{}', 'payment_method' => 'bank_transfer',
        'payment_status' => 'paid', 'payment_paid_at' => '2098-01-03 10:00:00',
        'created_at' => '2097-12-31 10:00:00',
    ]);
    $crossId = (int) $db->queryFirstField('SELECT id FROM shop_orders WHERE order_number=%s', $crossYearNumber);
    $db->insert('shop_invoices', [
        'order_id' => $crossId, 'order_number' => $crossYearNumber,
        'document_number' => 'EV-CROSS-' . $suffix, 'issue_date' => '2099-01-04',
        'due_date' => '2099-01-04', 'seller_json' => $seller,
        'buyer_json' => $buyer, 'items_json' => $items, 'subtotal_czk' => 500,
        'shipping_czk' => 0, 'total_czk' => 500, 'payment_method' => 'bank_transfer',
    ]);
    $db->insert('shop_tax_entries', [
        'entry_date' => '2100-01-05', 'direction' => 'income', 'account' => 'bank',
        'tax_kind' => 'taxable', 'amount_czk' => 500,
        'description' => 'Pozdní bankovní zápis', 'counterparty' => 'Eva Dokladová',
        'reference' => 'BANK-CROSS-' . $suffix, 'order_id' => $crossId,
    ]);
    foreach ([2097 => '2097-12-31', 2098 => '2098-01-03',
        2099 => '2099-01-04', 2100 => '2100-01-05'] as $year => $date) {
        $rows = $book->page($year, 0, $crossYearNumber)['items'];
        $assert(count($rows) === 1 && $rows[0]['activity_date'] === $date,
            'A cross-year order was not shown under the year of its actual activity.');
    }

    foreach ([[1999, 0, ''], [2098, -1, ''], [2098, 0, str_repeat('x', 151)]] as $input) {
        try {
            $book->page(...$input);
            throw new RuntimeException('Invalid evidence page input was accepted.');
        } catch (InvalidArgumentException $expected) {
        }
    }
    $db->rollback();
} catch (Throwable $error) {
    $db->rollback();
    throw $error;
}

echo "Evidence book database integration passed.\n";
