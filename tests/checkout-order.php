<?php
declare(strict_types=1);

class MeekroDB
{
    public array $rows = [];
    public array $saleLines = [];
    public int $transactions = 0;
    public int $commits = 0;
    public int $rollbacks = 0;
    public ?array $shipment = null;
    public string $lastManagementSql = '';
    public array $lastManagementParameters = [];
    private array $before = [];

    public function queryFirstField(string $sql, mixed ...$values): int { return 1; }

    public function startTransaction(): void
    {
        $this->transactions++;
        $this->before = [$this->rows, $this->saleLines];
    }

    public function commit(): void { $this->commits++; }
    public function rollback(): void { $this->rollbacks++; [$this->rows, $this->saleLines] = $this->before; }

    public function insert(string $table, array $fields): void
    {
        if ($table === 'shop_sale_lines') {
            $this->saleLines[] = $fields;
            return;
        }
        if ($table !== 'shop_orders') throw new RuntimeException('Unexpected table.');
        foreach ($this->rows as $row) {
            if ($row['idempotency_key'] === $fields['idempotency_key'] ||
                $row['variable_symbol'] === $fields['variable_symbol'] ||
                $row['order_token'] === $fields['order_token']) {
                throw new RuntimeException('Duplicate unique key.');
            }
        }
        $this->rows[] = ['id' => count($this->rows) + 1, 'created_at' => '2026-09-29 12:00:00',
            'fulfillment_source' => 'own', 'fulfillment_note' => null] + $fields;
    }

    public function queryFirstRow(string $sql, mixed ...$values): ?array
    {
        if (str_contains($sql, 'FROM shop_packeta_shipments')) return $this->shipment;
        foreach ($this->rows as $row) {
            if (str_contains($sql, 'idempotency_key=%s') && $row['idempotency_key'] === $values[0] ||
                str_contains($sql, 'order_token=%s') && $row['order_token'] === $values[0] ||
                str_contains($sql, 'WHERE id=%i') && $row['id'] === $values[0]) {
                return str_contains($sql, 'SELECT status, payment_method, payment_status')
                    ? array_intersect_key($row, array_flip([
                        'status', 'payment_method', 'payment_status', 'order_token',
                        'variable_symbol', 'payment_details_json',
                    ]))
                    : $row;
            }
        }
        return null;
    }

    public function query(string $sql, mixed ...$values): array
    {
        if (str_contains($sql, 'UPDATE shop_orders SET status=')) {
            $withSource = str_contains($sql, 'fulfillment_source=%s');
            foreach ($this->rows as &$row) {
                if ($row['id'] === $values[$withSource ? 3 : 1] &&
                    $row['status'] === $values[$withSource ? 4 : 2]) {
                    $row['status'] = $values[0];
                    if ($withSource) {
                        $row['fulfillment_source'] = $values[1];
                        $row['fulfillment_note'] = $values[2];
                    }
                }
            }
            unset($row);
            return [];
        }
        if (str_contains($sql, 'UPDATE shop_orders')) {
            $hasAdmin = str_contains($sql, 'payment_verified_by=%i');
            $id = $hasAdmin ? $values[2] : $values[1];
            $expected = $hasAdmin ? $values[3] : $values[2];
            foreach ($this->rows as &$row) {
                if ($row['id'] === $id && $row['payment_status'] === $expected) {
                    $row['payment_status'] = $values[0];
                    $row['payment_paid_at'] = '2026-09-29 12:10:00';
                    $row['payment_verified_by'] = $hasAdmin ? $values[1] : null;
                }
            }
            unset($row);
            return [];
        }
        if (str_contains($sql, 'FROM shop_orders')) {
            $this->lastManagementSql = $sql;
            $this->lastManagementParameters = $values;
            $rows = array_reverse($this->rows);
            $position = 0;
            if (str_contains($sql, 'payment_status=%s') || str_contains($sql, 'status=%s')) {
                $rows = array_values(array_filter($rows,
                    static fn (array $row): bool => $row[str_contains($sql, 'payment_status=%s') ? 'payment_status' : 'status'] === $values[0]));
                $position++;
            }
            if (str_contains($sql, 'payment_method=%s')) {
                $rows = array_values(array_filter($rows,
                    static fn (array $row): bool => $row['payment_method'] === $values[$position]));
                $position++;
            }
            if (str_contains($sql, 'order_number LIKE %s')) {
                $needle = str_replace(['!%', '!_', '!!', '%'], ['%', '_', '!', ''], $values[$position]);
                $rows = array_values(array_filter($rows,
                    static fn (array $row): bool => str_contains($row['order_number'], $needle) ||
                        str_contains($row['customer_email'], $needle) ||
                        str_contains((string) $row['variable_symbol'], $needle)));
            }
            return array_slice($rows, $values[count($values) - 1], $values[count($values) - 2]);
        }
        throw new RuntimeException('Unexpected SQL query.');
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Checkout\BankTransferPayment;
use SimpleStore\Checkout\OrderRepository;

$bank = new BankTransferPayment('CZ58 5500 0000 0012 6509 8001', '1265098001/5500', 'Obchod');
$db = new MeekroDB();
$repository = new OrderRepository($db, $bank, 7);
$items = [[
    'product_key' => str_repeat('a', 32), 'language' => 'cs', 'slug' => 'stan', 'name' => 'Stan',
    'quantity' => 2, 'unit_price_czk' => 500, 'image_path' => '/images/stan.webp', 'options' => [],
]];
$shipping = [
    'method' => 'home', 'label' => 'Doručení na adresu', 'name' => 'Eva Nová',
    'street' => 'Polní 1', 'city' => 'Praha', 'postal_code' => '11000', 'country' => 'CZ',
];
$key = str_repeat('b', 64);
$order = $repository->create(null, 'EVA@example.org', $items, $shipping, 100, $key);
if (count($db->rows) !== 1 || $db->commits !== 1 || $order['total_czk'] !== 1100 ||
    $order['subtotal_czk'] !== 1000 || $order['payment_status'] !== 'pending' ||
    $order['status'] !== 'new' || $order['customer_email'] !== 'eva@example.org' ||
    $order['shipping']['recipient'] !== 'Eva Nová' ||
    !preg_match('/^[0-9]{10}$/D', $order['variable_symbol']) ||
    !preg_match('/^DB-[0-9]{2}-[0-9]{10}$/D', $order['order_number']) ||
    !str_ends_with($order['order_number'], $order['variable_symbol']) ||
    !preg_match('/^[a-f0-9]{64}$/D', $order['order_token']) ||
    count($db->saleLines) !== 1 || $db->saleLines[0]['quantity'] !== 2) {
    throw new RuntimeException('Order snapshot, total, token or payment state is wrong.');
}
$details = BankTransferPayment::fromOrder($order)->details($order);
if ($details['iban'] !== 'CZ5855000000001265098001' ||
    $details['spayd'] !== 'SPD*1.0*ACC:CZ5855000000001265098001*AM:1100.00*CC:CZK*X-VS:' .
        $order['variable_symbol']) {
    throw new RuntimeException('Bank transfer payload does not match the saved order.');
}
$changedSettings = new BankTransferPayment(
    'CZ5855000000001265098001', '1265098001/5500', 'Nový příjemce'
);
if ($changedSettings->details($order)['recipient'] !== 'Obchod') {
    throw new RuntimeException('Existing payment was silently retargeted after a config change.');
}
$same = $repository->create(null, 'eva@example.org', $items, $shipping, 100, $key);
if (count($db->rows) !== 1 || count($db->saleLines) !== 1 ||
    $same['order_token'] !== $order['order_token'] ||
    $repository->findByToken($order['order_token'])['order_number'] !== $order['order_number'] ||
    $repository->findByToken('guess') !== null || $repository->findById(0) !== null) {
    throw new RuntimeException('Idempotent checkout or private order lookup failed.');
}
try {
    $repository->create(null, 'eve@example.org', $items, $shipping, 100, $key);
    throw new RuntimeException('Reused key with different recipient was accepted.');
} catch (InvalidArgumentException $expected) {
    if (count($db->rows) !== 1) throw new RuntimeException('The old order changed.');
}
$repository->markPaid(1, 4);
$repository->markPaid(1, 9);
if ($db->rows[0]['payment_status'] !== 'paid' || $db->rows[0]['status'] !== 'new' ||
    $db->rows[0]['payment_verified_by'] !== 4 ||
    $db->rows[0]['payment_paid_at'] !== '2026-09-29 12:10:00' ||
    $repository->managementPage(0, 20, 'paid')['items'][0]['id'] !== 1 ||
    $repository->managementPage(0, 20, 'pending')['items'] !== []) {
    throw new RuntimeException('Manual reconciliation changed the wrong status or filter.');
}
$repository->setFulfillmentStatus(1, 'processing');
$repository->setFulfillmentStatus(1, 'ready_to_ship');
if ($repository->managementPage(0, 20, 'ready_to_ship')['items'][0]['id'] !== 1) {
    throw new RuntimeException('Ready orders are not filterable.');
}
$repository->setFulfillmentStatus(1, 'shipped');
try {
    $repository->setFulfillmentStatus(1, 'processing');
    throw new RuntimeException('A handed over order returned to preparation.');
} catch (InvalidArgumentException $expected) {}
$repository->setFulfillmentStatus(1, 'completed');
if ($db->rows[0]['status'] !== 'completed') {
    throw new RuntimeException('Fulfillment status did not advance.');
}
try {
    $repository->setFulfillmentStatus(1, 'cancelled');
    throw new RuntimeException('A completed and paid order was cancelled.');
} catch (InvalidArgumentException $expected) {
}
$db->rows[0]['payment_method'] = 'comgate';
try {
    $repository->markPaid(1, 4);
    throw new RuntimeException('A provider payment was manually marked as bank transfer.');
} catch (InvalidArgumentException $expected) {
    if ($db->rows[0]['payment_status'] !== 'paid') throw new RuntimeException('Provider payment changed.');
}
$db->rows[0]['payment_method'] = 'bank_transfer';
$db->rows[0]['order_token'] = null;
try {
    $repository->markPaid(1, 4);
    throw new RuntimeException('A legacy order was manually confirmed.');
} catch (InvalidArgumentException $expected) {
}
foreach (['CZ5955000000001265098001', 'GB82WEST12345698765432'] as $invalidIban) {
    $rejected = false;
    try {
        new BankTransferPayment($invalidIban, '1265098001/5500', 'Obchod');
    } catch (InvalidArgumentException $expected) {
        $rejected = true;
    }
    if (!$rejected) throw new RuntimeException('Invalid or non-Czech IBAN was accepted.');
}
$rejected = false;
try {
    new BankTransferPayment('CZ5855000000001265098001', '1265098001/0100', 'Obchod');
} catch (InvalidArgumentException $expected) {
    $rejected = true;
}
if (!$rejected) throw new RuntimeException('Bank details mismatch was accepted.');
$derived = new BankTransferPayment('', '1265098001/5500', 'Obchod');
if ($derived->snapshot()['iban'] !== 'CZ5855000000001265098001') {
    throw new RuntimeException('Domestic account did not produce the correct Czech IBAN.');
}
$preview = (new OrderRepository($db))->create(null, 'test@example.org', $items, $shipping, 100,
    str_repeat('c', 64), true);
if ($preview['payment_method'] !== 'test' || $preview['payment_status'] !== 'test' ||
    $preview['payment_details'] !== [] || $preview['payment_due_at'] !== null ||
    $preview['variable_symbol'] !== null ||
    !preg_match('/^TEST-[0-9]{2}-[A-F0-9]{8}$/D', $preview['order_number']) ||
    $preview['status'] !== 'test' || $repository->managementPage(0, 20, 'test')['items'][0]['id'] !== $preview['id']) {
    throw new RuntimeException('Test order must be distinguishable and have no payment instructions.');
}
$filtered = $repository->managementPage(0, 1, 'paid', 'bank_transfer', 'eva@example.org');
if (count($filtered['items']) !== 1 || $filtered['items'][0]['id'] !== 1 ||
    $repository->managementPage(0, 1, null, null, $preview['order_number'])['items'][0]['id'] !== $preview['id'] ||
    $repository->managementPage(1, 1)['items'][0]['id'] !== 1) {
    throw new RuntimeException('Order search, payment method or pagination failed.');
}
$repository->managementPage(0, 20, null, null, '50%_!');
if (!str_contains($db->lastManagementSql, 'LIKE %s ESCAPE %s') ||
    $db->lastManagementParameters[0] !== '%50!%!_!!%' ||
    $db->lastManagementParameters[1] !== '!' ||
    $db->lastManagementParameters[2] !== '%50!%!_!!%') {
    throw new RuntimeException('Search wildcards must be matched literally.');
}
$rejected = false;
try {
    (new OrderRepository($db))->markPaid((int) $preview['id'], 4);
} catch (InvalidArgumentException $expected) {
    $rejected = true;
}
if (!$rejected) throw new RuntimeException('A test order was marked paid.');
$pending = $repository->create(null, 'pending@example.org', $items, $shipping, 100,
    str_repeat('e', 64));
try {
    $repository->setFulfillmentStatus((int) $pending['id'], 'shipped');
    throw new RuntimeException('An unpaid order was marked as shipped.');
} catch (InvalidArgumentException $expected) {
}
$repository->setFulfillmentStatus((int) $pending['id'], 'cancelled');
if ($db->rows[(int) $pending['id'] - 1]['status'] !== 'cancelled') {
    throw new RuntimeException('An unpaid order could not be cancelled.');
}
try {
    $repository->markPaid((int) $pending['id'], 4);
    throw new RuntimeException('Cancelled order was falsely marked paid.');
} catch (InvalidArgumentException $expected) {
    if ($db->rows[(int) $pending['id'] - 1]['payment_status'] !== 'pending') {
        throw new RuntimeException('Cancelled order payment was altered.');
    }
}
$rejected = false;
try {
    (new OrderRepository($db))->create(null, 'eva@example.org', $items, $shipping, 100, str_repeat('d', 64));
} catch (RuntimeException $expected) {
    $rejected = true;
}
if (!$rejected) throw new RuntimeException('Checkout without bank settings was accepted.');
$pickupShipping = ['method' => 'gls_pickup', 'label' => 'GLS – ParcelShop',
    'name' => 'Eva Nová', 'pickup_point' => 'ParcelShop Brno',
    'pickup_address' => 'Nádražní 1, 602 00 Brno', 'pickup_code' => '123'];
$pickupOrder = $repository->create(7, 'eva@example.org', $items, $pickupShipping, 59,
    str_repeat('f', 64));
if ($pickupOrder['shipping_czk'] !== 59 || $pickupOrder['total_czk'] !== 1059 ||
    $pickupOrder['user_id'] !== 7 ||
    $pickupOrder['shipping']['pickup_address'] !== 'Nádražní 1, 602 00 Brno') {
    throw new RuntimeException('Pickup point, customer and shipping price were not captured in the order.');
}
$packetaShipping = array_replace($pickupShipping, ['method' => 'zasilkovna_pickup',
    'label' => 'Zásilkovna', 'pickup_verified' => true]);
$packetaOrder = $repository->create(null, 'eva@example.org', $items, $packetaShipping, 90,
    str_repeat('1', 64));
$repository->markPaid((int) $packetaOrder['id'], 4);
try {
    $repository->setFulfillmentStatus((int) $packetaOrder['id'], 'ready_to_ship');
    throw new RuntimeException('Packeta order was ready without an active parcel.');
} catch (InvalidArgumentException $expected) {}
$db->shipment = ['status' => 'cancel_uncertain'];
try {
    $repository->setFulfillmentStatus((int) $packetaOrder['id'], 'shipped');
    throw new RuntimeException('Packeta order shipped while cancellation is unresolved.');
} catch (InvalidArgumentException $expected) {}
$db->shipment = ['status' => 'created'];
$repository->setFulfillmentStatus((int) $packetaOrder['id'], 'ready_to_ship');
if ($db->rows[(int) $packetaOrder['id'] - 1]['status'] !== 'ready_to_ship') {
    throw new RuntimeException('A confirmed Packeta parcel was not ready to ship.');
}
$externalOrder = $repository->create(null, 'supplier@example.org', $items,
    $packetaShipping, 90, str_repeat('2', 64));
$repository->markPaid((int) $externalOrder['id'], 4);
foreach (['created', 'cancel_uncertain'] as $active) {
    $db->shipment = ['status' => $active];
    try {
        $repository->setFulfillmentStatus((int) $externalOrder['id'], 'shipped',
            'external', 'Dodavatel A');
        throw new RuntimeException('An active Packeta API parcel was ignored for external shipping.');
    } catch (InvalidArgumentException $expected) {}
}
$db->shipment = null;
$repository->setFulfillmentStatus((int) $externalOrder['id'], 'ready_to_ship',
    'external', 'Dodavatel A');
$repository->setFulfillmentStatus((int) $externalOrder['id'], 'shipped',
    'external', 'Dodavatel A');
if ($db->rows[(int) $externalOrder['id'] - 1]['fulfillment_source'] !== 'external' ||
    $db->rows[(int) $externalOrder['id'] - 1]['fulfillment_note'] !== 'Dodavatel A') {
    throw new RuntimeException('External dispatch without a local Packeta parcel was not recorded.');
}
try {
    $repository->setFulfillmentStatus((int) $externalOrder['id'], 'completed', 'own');
    throw new RuntimeException('Fulfillment source changed after shipping.');
} catch (InvalidArgumentException $expected) {}
$repository->setFulfillmentStatus((int) $externalOrder['id'], 'completed',
    'external', 'Dodavatel A');
echo "Checkout order and bank transfer tests passed.\n";
