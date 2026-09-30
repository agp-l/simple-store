<?php
declare(strict_types=1);

class MeekroDB
{
    public array $orders = [];
    public array $shipments = [];
    public array $cancelledShipments = [];
    public array $documents = [];
    public array $events = [];
    public bool $failEvent = false;
    public bool $failDelete = false;
    public bool $eventsInstalled = true;
    public bool $documentsInstalled = true;
    private ?array $snapshot = null;

    public function startTransaction(): void
    {
        $this->snapshot = [$this->orders, $this->events];
    }

    public function commit(): void
    {
        $this->snapshot = null;
    }

    public function rollback(): void
    {
        if ($this->snapshot !== null) {
            [$this->orders, $this->events] = $this->snapshot;
            $this->snapshot = null;
        }
    }

    public function queryFirstField(string $sql, mixed ...$args): int
    {
        if (str_contains($sql, 'information_schema.TABLES')) {
            return match ($args[0]) {
                'shop_order_admin_events' => $this->eventsInstalled ? 1 : 0,
                'shop_documents' => $this->documentsInstalled ? 1 : 0,
                'shop_packeta_shipments', 'shop_packeta_cancelled_shipments',
                'shop_carrier_shipments' => 1,
                default => throw new RuntimeException('Unknown table check.'),
            };
        }
        $id = $args[0];
        if (str_contains($sql, 'FROM shop_packeta_cancelled_shipments')) {
            return isset($this->cancelledShipments[$id]) ? 1 : 0;
        }
        if (str_contains($sql, 'FROM shop_packeta_shipments')) {
            return isset($this->shipments[$id]) ? 1 : 0;
        }
        if (str_contains($sql, 'FROM shop_carrier_shipments')) return 0;
        if (str_contains($sql, 'FROM shop_documents')) {
            return isset($this->documents[$id]) ? 1 : 0;
        }
        throw new RuntimeException('Unexpected COUNT query.');
    }

    public function queryFirstRow(string $sql, mixed ...$args): ?array
    {
        if (str_contains($sql, 'FROM shop_orders')) return $this->orders[$args[0]] ?? null;
        if (str_contains($sql, 'FROM shop_packeta_shipments')) return $this->shipments[$args[0]] ?? null;
        throw new RuntimeException('Unexpected row query.');
    }

    public function query(string $sql, mixed ...$args): array
    {
        if (str_contains($sql, 'FROM shop_order_admin_events')) {
            if (str_contains($sql, 'WHERE action=%s')) {
                return array_slice(array_reverse(array_values(array_filter($this->events,
                    static fn (array $event): bool => $event['action'] === $args[0]))), 0, $args[1]);
            }
            return array_reverse(array_values(array_filter(
                $this->events, static fn (array $event): bool => $event['order_id'] === $args[0]
            )));
        }
        if (str_contains($sql, 'UPDATE shop_orders SET status=')) {
            [$target, $id, $old, $source, $payment] = $args;
            $row = $this->orders[$id] ?? null;
            if ($row !== null && $row['status'] === $old &&
                $row['fulfillment_source'] === $source && $row['payment_status'] === $payment) {
                $this->orders[$id]['status'] = $target;
            }
            return [];
        }
        if (str_contains($sql, 'DELETE FROM shop_orders')) {
            [$id, $number, $status, $method, $payment] = $args;
            $row = $this->orders[$id] ?? null;
            if ($this->failDelete) throw new RuntimeException('Foreign key deletion rejected.');
            if ($row !== null && $row['order_number'] === $number && $row['status'] === $status &&
                $row['payment_method'] === $method && $row['payment_status'] === $payment &&
                $row['payment_paid_at'] === null && $row['payment_verified_by'] === null &&
                $row['provider_reference'] === null) {
                unset($this->orders[$id]);
            }
            return [];
        }
        throw new RuntimeException('Unexpected mutation.');
    }

    public function insert(string $table, array $values): void
    {
        if ($table !== 'shop_order_admin_events') throw new RuntimeException('Unexpected table.');
        if ($this->failEvent) throw new RuntimeException('Audit storage failed.');
        $this->events[] = $values + ['created_at' => '2026-09-30 00:00:00'];
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
        'payment_method' => $method, 'payment_status' => $payment,
        'payment_paid_at' => null, 'payment_verified_by' => null,
        'provider_reference' => null, 'fulfillment_source' => $source,
        'shipping_json' => json_encode(['method' => 'zasilkovna_pickup'], JSON_THROW_ON_ERROR)];
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
$controls->deleteOrder(14, 'DB-20260930-14', 3, 'Testovací nákup již nepotřebuji.');
if (isset($db->orders[14]) || $db->events[count($db->events) - 1]['new_status'] !== 'deleted' ||
    $db->events[count($db->events) - 1]['order_number'] !== 'DB-20260930-14' ||
    isset($db->events[count($db->events) - 1]['customer_email'])) {
    throw new RuntimeException('Test order deletion did not leave a minimal audit event.');
}
if ($controls->recentDeletions(1)[0]['order_number'] !== 'DB-20260930-14') {
    throw new RuntimeException('Deleted order audit is not visible to administrators.');
}
$db->orders[15] = orderRow(15, 'new', 'bank_transfer', 'pending');
$controls->deleteOrder(15, 'DB-20260930-15', 3, 'Nesprávně vytvořená neplacená objednávka.');
$db->orders[16] = orderRow(16, 'cancelled', 'bank_transfer', 'pending');
$controls->deleteOrder(16, 'DB-20260930-16', 3, 'Duplicitní neplacená objednávka zákazníka.');

foreach (['paid_order', 'shipment', 'cancelled_shipment', 'document', 'verified',
    'not_genuine_test', 'wrong_number'] as $index => $case) {
    $id = $index + 100;
    $db->orders[$id] = orderRow($id, 'new', 'bank_transfer', 'pending');
    if ($case === 'paid_order') $db->orders[$id]['payment_status'] = 'paid';
    if ($case === 'shipment') $db->shipments[$id] = ['status' => 'rejected'];
    if ($case === 'cancelled_shipment') $db->cancelledShipments[$id] = ['status' => 'cancelled'];
    if ($case === 'document') $db->documents[$id] = ['number' => '2026-001'];
    if ($case === 'verified') $db->orders[$id]['payment_verified_by'] = 3;
    if ($case === 'not_genuine_test') {
        $db->orders[$id]['payment_method'] = 'test';
        $db->orders[$id]['payment_status'] = 'test';
    }
    $number = $case === 'wrong_number' ? 'DB-20260930-OTHER' : 'DB-20260930-' . $id;
    expectInvalid(static fn () => $controls->deleteOrder($id, $number, 3, $reason),
        'Deleted blocked order for case ' . $case);
    if (!isset($db->orders[$id])) throw new RuntimeException('Rejected deletion removed an order.');
}

$db->orders[17] = orderRow(17, 'test', 'test', 'test');
$eventCount = count($db->events);
$db->failDelete = true;
try {
    $controls->deleteOrder(17, 'DB-20260930-17', 3, 'Test objednávky, kterou nelze smazat.');
    throw new RuntimeException('A foreign key failure did not abort deletion.');
} catch (RuntimeException $expected) {
    if (!isset($db->orders[17]) || count($db->events) !== $eventCount) {
        throw new RuntimeException('Foreign key failure retained an orphaned audit event.');
    }
}
$db->eventsInstalled = false;
expectInvalid(static fn () => $controls->deleteOrder(17, 'DB-20260930-17', 3, $reason),
    'Missing audit table did not block deletion.');
echo "Order control tests passed.\n";
