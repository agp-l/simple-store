<?php
declare(strict_types=1);

class MeekroDB
{
    public array $orders = [];
    public array $shipments = [];
    public array $cancelledShipments = [];
    public array $documents = [];
    public array $invoices = [];
    public array $carriers = [];
    public array $events = [];
    public array $financialEvents = [];
    public array $archivedSales = [];
    public array $archivedShipments = [];
    public array $gopayAttempts = [];
    public array $comgateAttempts = [];
    public array $btcpayAttempts = [];
    public bool $failEvent = false;
    public bool $failDelete = false;
    public bool $eventsInstalled = true;
    public bool $financialInstalled = true;
    public bool $documentsInstalled = true;
    private ?array $snapshot = null;

    public function startTransaction(): void
    {
        $this->snapshot = [$this->orders, $this->events, $this->financialEvents,
            $this->carriers, $this->archivedSales, $this->shipments, $this->cancelledShipments,
            $this->archivedShipments, $this->invoices, $this->gopayAttempts,
            $this->comgateAttempts, $this->btcpayAttempts];
    }

    public function commit(): void
    {
        $this->snapshot = null;
    }

    public function rollback(): void
    {
        if ($this->snapshot !== null) {
            [$this->orders, $this->events, $this->financialEvents,
                $this->carriers, $this->archivedSales, $this->shipments, $this->cancelledShipments,
                $this->archivedShipments, $this->invoices, $this->gopayAttempts,
                $this->comgateAttempts, $this->btcpayAttempts] = $this->snapshot;
            $this->snapshot = null;
        }
    }

    public function queryFirstField(string $sql, mixed ...$args): int
    {
        if (str_contains($sql, 'information_schema.TABLES')) {
            return match ($args[0]) {
                'shop_order_admin_events' => $this->eventsInstalled ? 1 : 0,
                'shop_order_financial_events' => $this->financialInstalled ? 1 : 0,
                'shop_documents' => $this->documentsInstalled ? 1 : 0,
                'shop_invoices' => 1,
                'shop_packeta_shipments', 'shop_packeta_cancelled_shipments',
                'shop_carrier_shipments' => 1,
                'shop_sale_lines', 'shop_deleted_sale_lines', 'shop_deleted_shipments',
                'shop_comgate_payments', 'shop_gopay_payments', 'shop_btcpay_payments' => 1,
                'shop_tax_entries', 'shop_mail_outbox', 'shop_stock_movements' => 0,
                default => throw new RuntimeException('Unknown table check.'),
            };
        }
        if (str_contains($sql, 'information_schema.COLUMNS')) {
            return in_array($args[0], ['shop_gopay_payments', 'shop_comgate_payments',
                'shop_btcpay_payments', 'shop_invoices'], true) ? 1 : 0;
        }
        $id = $args[0];
        if (str_starts_with(ltrim($sql), 'SELECT') &&
            str_contains($sql, 'FROM shop_packeta_cancelled_shipments')) {
            return isset($this->cancelledShipments[$id]) ? 1 : 0;
        }
        if (str_contains($sql, 'FROM shop_packeta_shipments')) {
            return isset($this->shipments[$id]) ? 1 : 0;
        }
        if (str_contains($sql, 'FROM shop_carrier_shipments')) return isset($this->carriers[$id]) ? 1 : 0;
        if (str_contains($sql, 'FROM shop_documents')) {
            return isset($this->documents[$id]) ? 1 : 0;
        }
        if (str_contains($sql, 'FROM shop_invoices')) {
            return isset($this->invoices[$id]) ? 1 : 0;
        }
        throw new RuntimeException('Unexpected COUNT query.');
    }

    public function queryFirstRow(string $sql, mixed ...$args): ?array
    {
        if (str_contains($sql, 'FROM shop_orders')) return $this->orders[$args[0]] ?? null;
        if (str_contains($sql, 'FROM shop_packeta_shipments')) return $this->shipments[$args[0]] ?? null;
        if (str_contains($sql, 'FROM shop_carrier_shipments')) return $this->carriers[$args[0]] ?? null;
        throw new RuntimeException('Unexpected row query.');
    }

    public function query(string $sql, mixed ...$args): array
    {
        if (str_starts_with(ltrim($sql), 'SELECT') &&
            str_contains($sql, 'FROM shop_packeta_cancelled_shipments')) {
            return isset($this->cancelledShipments[$args[0]]) ? [$this->cancelledShipments[$args[0]]] : [];
        }
        if (str_contains($sql, 'FROM shop_comgate_payments')) {
            return $this->comgateAttempts[$args[0]] ?? [];
        }
        if (str_contains($sql, 'FROM shop_gopay_payments')) {
            return $this->gopayAttempts[$args[0]] ?? [];
        }
        if (str_contains($sql, 'FROM shop_btcpay_payments')) {
            return $this->btcpayAttempts[$args[0]] ?? [];
        }
        if (str_starts_with(ltrim($sql), 'SELECT') &&
            str_contains($sql, 'FROM shop_order_admin_events')) {
            if (str_contains($sql, 'WHERE action=%s')) {
                return array_slice(array_reverse(array_values(array_filter($this->events,
                    static fn (array $event): bool => $event['action'] === $args[0]))), 0, $args[1]);
            }
            return array_reverse(array_values(array_filter(
                $this->events, static fn (array $event): bool => $event['order_id'] === $args[0]
            )));
        }
        if (str_contains($sql, 'FROM shop_sale_lines')) return [];
        if (str_contains($sql, 'UPDATE shop_orders SET status=')) {
            [$target, $id, $old, $source, $payment] = $args;
            $row = $this->orders[$id] ?? null;
            if ($row !== null && $row['status'] === $old &&
                $row['fulfillment_source'] === $source && $row['payment_status'] === $payment) {
                $this->orders[$id]['status'] = $target;
            }
            return [];
        }
        if (str_contains($sql, 'UPDATE shop_orders SET payment_status=')) {
            [$target, $id, $old, $method] = $args;
            if (($this->orders[$id]['payment_status'] ?? null) === $old &&
                ($this->orders[$id]['payment_method'] ?? null) === $method) {
                $this->orders[$id]['payment_status'] = $target;
                $this->orders[$id]['payment_paid_at'] = null;
                $this->orders[$id]['payment_verified_by'] = null;
            }
            return [];
        }
        if (str_contains($sql, 'DELETE FROM shop_order_admin_events')) {
            $this->events = array_values(array_filter($this->events,
                static fn (array $event): bool => $event['order_id'] !== $args[0]));
            return [];
        }
        if (str_contains($sql, 'DELETE FROM shop_order_financial_events')) {
            $this->financialEvents = array_values(array_filter($this->financialEvents,
                static fn (array $event): bool => $event['order_id'] !== $args[0]));
            return [];
        }
        if (str_contains($sql, 'DELETE FROM shop_carrier_shipments')) {
            unset($this->carriers[$args[0]]);
            return [];
        }
        if (str_contains($sql, 'DELETE FROM shop_packeta_cancelled_shipments')) {
            unset($this->cancelledShipments[$args[0]]);
            return [];
        }
        if (str_contains($sql, 'DELETE FROM shop_packeta_shipments')) {
            unset($this->shipments[$args[0]]);
            return [];
        }
        if (str_contains($sql, 'UPDATE shop_invoices SET order_id=NULL')) {
            if (isset($this->invoices[$args[0]])) $this->invoices[$args[0]]['order_id'] = null;
            return [];
        }
        if (str_contains($sql, 'SET order_number=%s, total_czk=%i, order_id=NULL')) {
            if (str_contains($sql, 'shop_gopay_payments')) {
                foreach ($this->gopayAttempts[$args[2]] ?? [] as $index => $row) {
                    $this->gopayAttempts[$args[2]][$index]['order_id'] = null;
                    $this->gopayAttempts[$args[2]][$index]['order_number'] = $args[0];
                    $this->gopayAttempts[$args[2]][$index]['total_czk'] = $args[1];
                }
            } elseif (str_contains($sql, 'shop_btcpay_payments')) {
                foreach ($this->btcpayAttempts[$args[2]] ?? [] as $index => $row) {
                    $this->btcpayAttempts[$args[2]][$index]['order_id'] = null;
                    $this->btcpayAttempts[$args[2]][$index]['order_number'] = $args[0];
                    $this->btcpayAttempts[$args[2]][$index]['total_czk'] = $args[1];
                }
            } else {
                foreach ($this->comgateAttempts[$args[2]] ?? [] as $index => $row) {
                    $this->comgateAttempts[$args[2]][$index]['order_id'] = null;
                    $this->comgateAttempts[$args[2]][$index]['order_number'] = $args[0];
                    $this->comgateAttempts[$args[2]][$index]['total_czk'] = $args[1];
                }
            }
            return [];
        }
        if (str_contains($sql, 'DELETE FROM shop_orders')) {
            [$id, $number, $status, $method, $payment] = $args;
            $row = $this->orders[$id] ?? null;
            if ($this->failDelete) throw new RuntimeException('Foreign key deletion rejected.');
            if ($row !== null && $row['order_number'] === $number && $row['status'] === $status &&
                $row['payment_method'] === $method && $row['payment_status'] === $payment) {
                unset($this->orders[$id]);
            }
            return [];
        }
        throw new RuntimeException('Unexpected mutation.');
    }

    public function insert(string $table, array $values): void
    {
        if (!in_array($table, ['shop_order_admin_events', 'shop_order_financial_events',
            'shop_deleted_sale_lines', 'shop_deleted_shipments'], true)) {
            throw new RuntimeException('Unexpected table.');
        }
        if ($this->failEvent) throw new RuntimeException('Audit storage failed.');
        if ($table === 'shop_order_admin_events') {
            $this->events[] = $values + ['created_at' => '2026-09-30 00:00:00'];
        } elseif ($table === 'shop_order_financial_events') {
            $this->financialEvents[] = $values + ['created_at' => '2026-09-30 00:00:00'];
        } elseif ($table === 'shop_deleted_sale_lines') {
            $this->archivedSales[] = $values;
        } else {
            $this->archivedShipments[] = $values;
        }
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Admin\OrderControlRepository;

function expectInvalid(callable $action, string $description): void
{
    try {
        $action();
    } catch (InvalidArgumentException $expected) {
        return;
    }
    throw new RuntimeException($description);
}

function orderRow(int $id, string $status, string $method = 'bank_transfer',
    string $payment = 'paid', string $source = 'own'): array
{
    return ['id' => $id, 'order_number' => 'DB-20260930-' . $id, 'status' => $status,
        'variable_symbol' => (string) (1000000000 + $id), 'total_czk' => 1790,
        'payment_method' => $method, 'payment_status' => $payment,
        'payment_paid_at' => null, 'payment_verified_by' => null,
        'provider_reference' => null, 'fulfillment_source' => $source,
        'shipping_json' => json_encode(['method' => 'zasilkovna_pickup'], JSON_THROW_ON_ERROR),
        'created_at' => '2026-09-30 12:00:00',
        'items_json' => json_encode([['product_key' => str_repeat('a', 32),
            'name' => 'Testovací zboží', 'quantity' => 2, 'unit_price_czk' => 895]], JSON_THROW_ON_ERROR)];
}

$db = new MeekroDB();
$controls = new OrderControlRepository($db);
$reason = 'Chybně označeno odeslání objednávky.';
$db->orders[7] = orderRow(7, 'shipped', 'bank_transfer', 'paid', 'external');
$controls->correctFulfillment(7, 'processing', 3, $reason, 'not_handed');
if ($db->orders[7]['status'] !== 'processing' || $db->orders[7]['fulfillment_source'] !== 'external' ||
    $db->events[0]['old_status'] !== 'shipped' || $db->events[0]['new_status'] !== 'processing' ||
    $db->events[0]['admin_id'] !== 3 || $controls->eventsForOrder(7)[0]['reason'] !== $reason) {
    throw new RuntimeException('Status correction lost the source or audit trail.');
}
$db->orders[8] = orderRow(8, 'completed', 'bank_transfer', 'paid', 'external');
$controls->correctFulfillment(8, 'shipped', 3, 'Balík ještě nedorazil, oprava stavu.', 'not_delivered');
expectInvalid(static fn () => $controls->correctFulfillment(8, 'completed', 3, $reason, 'not_handed'),
    'Allowed forward transition in correction API.');
expectInvalid(static fn () => $controls->correctFulfillment(7, 'ready_to_ship', 3, $reason, 'reopen'),
    'Accepted incorrect confirmation.');
expectInvalid(static fn () => $controls->correctFulfillment(7, 'ready_to_ship', 3, 'Krátce', 'not_handed'),
    'Accepted a reason too short for audit.');
expectInvalid(static fn () => $controls->correctFulfillment(7, 'ready_to_ship', 3,
    "Důvod\nnelze ponechat v záznamu.", 'not_handed'), 'Accepted control characters in reason.');

$db->orders[9] = orderRow(9, 'shipped');
expectInvalid(static fn () => $controls->correctFulfillment(9, 'ready_to_ship', 3, $reason, 'not_handed'),
    'Own Packeta shipment was not required for ready status.');
$db->shipments[9] = ['status' => 'cancel_uncertain'];
expectInvalid(static fn () => $controls->correctFulfillment(9, 'ready_to_ship', 3, $reason, 'not_handed'),
    'Uncertain Packeta cancellation was treated as an active parcel.');
$db->shipments[9] = ['status' => 'created'];
$controls->correctFulfillment(9, 'ready_to_ship', 3, $reason, 'not_handed');
$db->orders[90] = orderRow(90, 'shipped');
$db->orders[90]['shipping_json'] = json_encode(['method' => 'zasilkovna_pickup',
    'pickup_source' => 'manual', 'pickup_verified' => false], JSON_THROW_ON_ERROR);
$controls->correctFulfillment(90, 'ready_to_ship', 3, $reason, 'not_handed');
if ($db->orders[90]['status'] !== 'ready_to_ship') {
    throw new RuntimeException('Manual Packeta order could not be corrected without an API parcel.');
}

$db->orders[10] = orderRow(10, 'cancelled', 'bank_transfer', 'pending');
$controls->correctFulfillment(10, 'new', 3, 'Neplacená objednávka byla zrušena omylem.', 'reopen');
$db->orders[11] = orderRow(11, 'cancelled');
expectInvalid(static fn () => $controls->correctFulfillment(11, 'new', 3, $reason, 'reopen'),
    'Reopened a paid cancellation.');
$db->orders[12] = orderRow(12, 'cancelled', 'bank_transfer', 'pending');
$db->shipments[12] = ['status' => 'submitting'];
expectInvalid(static fn () => $controls->correctFulfillment(12, 'new', 3, $reason, 'reopen'),
    'Reopened an order with an unresolved parcel.');

$db->orders[13] = orderRow(13, 'completed', 'bank_transfer', 'paid', 'external');
$eventCount = count($db->events);
$db->failEvent = true;
try {
    $controls->correctFulfillment(13, 'processing', 3, $reason, 'not_handed');
    throw new RuntimeException('An audit storage failure did not abort status correction.');
} catch (RuntimeException $expected) {
    if ($db->orders[13]['status'] !== 'completed' || count($db->events) !== $eventCount) {
        throw new RuntimeException('Failed audit did not roll back status correction.');
    }
}
$db->failEvent = false;

$db->orders[14] = orderRow(14, 'test', 'test', 'test');
$eventCount = count($db->events);
$controls->deleteOrder(14, 'DB-20260930-14', 3, 'Testovací nákup již nepotřebuji.');
if (isset($db->orders[14]) || count($db->events) !== $eventCount) {
    throw new RuntimeException('Test order deletion left an accounting or audit event.');
}
$db->orders[15] = orderRow(15, 'new', 'bank_transfer', 'pending');
$controls->deleteOrder(15, 'DB-20260930-15', 3, 'Nesprávně vytvořená neplacená objednávka.');
$db->orders[16] = orderRow(16, 'cancelled', 'bank_transfer', 'pending');
$controls->deleteOrder(16, 'DB-20260930-16', 3, 'Duplicitní neplacená objednávka zákazníka.');
if ($controls->recentDeletions(1)[0]['order_number'] !== 'DB-20260930-16') {
    throw new RuntimeException('Deleted real order audit is not visible to administrators.');
}

$db->orders[18] = orderRow(18, 'completed');
$db->orders[18]['payment_paid_at'] = '2026-09-29 12:00:00';
$db->orders[18]['payment_verified_by'] = 3;
$controls->correctPayment(18, 4, 'Chybné párování ve výpisu banky.', 'not_received');
$correctionEvent = $db->financialEvents[array_key_last($db->financialEvents)];
if ($db->orders[18]['payment_status'] !== 'pending' ||
    $db->orders[18]['payment_paid_at'] !== null ||
    $db->orders[18]['payment_verified_by'] !== null ||
    $correctionEvent['payment_paid_at'] !== '2026-09-29 12:00:00' ||
    $correctionEvent['variable_symbol'] !== $db->orders[18]['variable_symbol'] ||
    $controls->eventsForOrder(18)[0]['action'] !== 'payment_correction') {
    throw new RuntimeException('Payment correction lost the prior paid evidence.');
}
expectInvalid(static fn () => $controls->correctPayment(18, 4, $reason, 'not_received'),
    'Accepted a second payment reset.');
$db->carriers[18] = ['status' => 'draft', 'method' => 'gls_home', 'tracking_number' => null];
$controls->deleteOrder(18, 'DB-20260930-18', 4, 'Test zaplacené objednávky po opravě.');
if (isset($db->orders[18]) || isset($db->carriers[18]) ||
    $db->financialEvents[array_key_last($db->financialEvents)]['action'] !== 'order_deleted') {
    throw new RuntimeException('Corrected bank order or its draft was not deleted with an audit trail.');
}
$db->orders[19] = orderRow(19, 'completed');
$db->orders[19]['payment_paid_at'] = '2026-09-29 13:00:00';
$controls->deleteOrder(19, 'DB-20260930-19', 4, 'Přímé smazání duplicitní platby.');
$deletionEvent = $db->financialEvents[array_key_last($db->financialEvents)];
if (isset($db->orders[19]) || $deletionEvent['payment_status_before'] !== 'paid' ||
    $deletionEvent['total_czk'] !== 1790 ||
    $db->archivedSales[array_key_last($db->archivedSales)]['quantity'] !== 2) {
    throw new RuntimeException('Paid deletion lost its financial snapshot.');
}

foreach (['document', 'not_genuine_test', 'wrong_number'] as $index => $case) {
    $id = $index + 100;
    $db->orders[$id] = orderRow($id, 'new', 'bank_transfer', 'pending');
    if ($case === 'document') $db->documents[$id] = ['number' => '2026-001'];
    if ($case === 'not_genuine_test') {
        $db->orders[$id]['payment_method'] = 'test';
        $db->orders[$id]['payment_status'] = 'test';
    }
    $number = $case === 'wrong_number' ? 'DB-20260930-OTHER' : 'DB-20260930-' . $id;
    expectInvalid(static fn () => $controls->deleteOrder($id, $number, 3, $reason),
        'Deleted blocked order for case ' . $case);
    if (!isset($db->orders[$id])) throw new RuntimeException('Rejected deletion removed an order.');
}

$db->orders[104] = orderRow(104, 'shipped');
$db->invoices[104] = ['order_id' => 104, 'number' => 'F2026-000001'];
$db->shipments[104] = ['status' => 'created', 'method' => 'zasilkovna_pickup',
    'packet_id' => '12345', 'barcode' => 'Z12345'];
$db->cancelledShipments[104] = ['method' => 'zasilkovna_pickup', 'packet_id' => '12344',
    'barcode' => 'Z12344'];
$db->carriers[104] = ['status' => 'registered', 'method' => 'gls_home',
    'tracking_number' => 'GLS123456'];
$controls->deleteOrder(104, 'DB-20260930-104', 3, 'Duplicitní záznam již odeslané objednávky.');
if (isset($db->orders[104]) || isset($db->shipments[104]) || isset($db->carriers[104]) ||
    isset($db->cancelledShipments[104]) || $db->invoices[104]['order_id'] !== null ||
    count($db->archivedShipments) !== 3 ||
    $db->archivedShipments[0]['external_number'] !== 'GLS123456' ||
    $db->archivedShipments[1]['external_number'] !== 'Z12345' ||
    $db->archivedShipments[2]['external_number'] !== 'Z12344') {
    throw new RuntimeException('Deletion failed to preserve issued invoice or parcel references.');
}

$db->orders[105] = orderRow(105, 'completed', 'gopay');
$db->gopayAttempts[105] = [['order_id' => 105, 'status' => 'paid']];
$controls->deleteOrder(105, 'DB-20260930-105', 3, 'Odstranění duplicitní úhrady GoPay.');
if (isset($db->orders[105]) || $db->gopayAttempts[105][0]['order_id'] !== null ||
    $db->gopayAttempts[105][0]['order_number'] !== 'DB-20260930-105' ||
    $db->gopayAttempts[105][0]['total_czk'] !== 1790) {
    throw new RuntimeException('Gateway payment lost its detached snapshot.');
}

$db->orders[106] = orderRow(106, 'new', 'comgate', 'pending');
$db->comgateAttempts[106] = [['order_id' => 106, 'status' => 'creating']];
expectInvalid(static fn () => $controls->deleteOrder(106, 'DB-20260930-106', 3, $reason),
    'Uncertain gateway creation was detached without a transaction ID.');
if (!isset($db->orders[106]) || $db->comgateAttempts[106][0]['order_id'] !== 106) {
    throw new RuntimeException('Blocked deletion did not roll back.');
}

$db->orders[107] = orderRow(107, 'ready_to_ship');
$db->shipments[107] = ['status' => 'uncertain', 'method' => 'zasilkovna_pickup',
    'packet_id' => null, 'barcode' => null];
expectInvalid(static fn () => $controls->deleteOrder(107, 'DB-20260930-107', 3, $reason),
    'Uncertain Packeta submission was discarded without external reconciliation.');
if (!isset($db->orders[107]) || !isset($db->shipments[107])) {
    throw new RuntimeException('An unresolved parcel must remain linked for reconciliation.');
}

$db->orders[108] = orderRow(108, 'completed', 'btcpay');
$db->btcpayAttempts[108] = [['order_id' => 108, 'status' => 'settled']];
$controls->deleteOrder(108, 'DB-20260930-108', 3, 'Odstranění duplicitní úhrady BTCPay.');
if (isset($db->orders[108]) || $db->btcpayAttempts[108][0]['order_id'] !== null ||
    $db->btcpayAttempts[108][0]['order_number'] !== 'DB-20260930-108' ||
    $db->btcpayAttempts[108][0]['total_czk'] !== 1790) {
    throw new RuntimeException('BTCPay invoice lost its order snapshot after deletion.');
}

$db->orders[109] = orderRow(109, 'new', 'btcpay', 'pending');
$db->btcpayAttempts[109] = [['order_id' => 109, 'status' => 'uncertain']];
expectInvalid(static fn () => $controls->deleteOrder(109, 'DB-20260930-109', 3, $reason),
    'An uncertain BTCPay invoice was detached without reconciliation.');
if (!isset($db->orders[109]) || $db->btcpayAttempts[109][0]['order_id'] !== 109) {
    throw new RuntimeException('Failed BTCPay deletion did not roll back its invoice attempt.');
}

$db->orders[17] = orderRow(17, 'test', 'test', 'test');
$eventCount = count($db->events);
$financialCount = count($db->financialEvents);
$db->failDelete = true;
try {
    $controls->deleteOrder(17, 'DB-20260930-17', 3, 'Test objednávky, kterou nelze smazat.');
    throw new RuntimeException('A foreign key failure did not abort deletion.');
} catch (RuntimeException $expected) {
    if (!isset($db->orders[17]) || count($db->events) !== $eventCount ||
        count($db->financialEvents) !== $financialCount) {
        throw new RuntimeException('Foreign key failure retained an orphaned audit event.');
    }
}
$db->eventsInstalled = false;
expectInvalid(static fn () => $controls->deleteOrder(17, 'DB-20260930-17', 3, $reason),
    'Missing audit table did not block deletion.');
$db->eventsInstalled = true;
$db->financialInstalled = false;
expectInvalid(static fn () => $controls->correctPayment(19, 3, $reason, 'not_received'),
    'Missing financial audit table did not block payment corrections.');
echo "Order control tests passed.\n";
